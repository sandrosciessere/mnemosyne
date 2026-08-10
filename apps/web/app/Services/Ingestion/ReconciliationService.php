<?php

namespace App\Services\Ingestion;

use App\Enums\IngestionEventType;
use App\Models\BookAsset;
use App\Models\Contributor;
use App\Models\DuplicateCandidate;
use App\Models\Edition;
use App\Models\EditionIdentifier;
use App\Models\IngestionEvent;
use App\Models\IngestionRun;
use App\Models\Work;
use Illuminate\Support\Facades\DB;

/**
 * Conservative bibliographic reconciliation, run after the structure
 * stage. Confidence classes are labels, not pseudo-scientific numbers:
 * exact | high_confidence | candidate | unresolved. Every automatic
 * decision records method + evidence and is reversible by an admin (the
 * raw metadata artifact and extracted_metadata survive unchanged).
 */
class ReconciliationService
{
    public const VERSION = '1';

    public function reconcile(IngestionRun $run): void
    {
        $asset = $run->asset?->refresh();

        if ($asset === null || $asset->extracted_metadata === null) {
            return;
        }

        DB::transaction(function () use ($run, $asset) {
            $this->detectContentDuplicates($run, $asset);

            if ($asset->edition_id !== null) {
                return; // Reprocessing an already-linked asset: keep links.
            }

            [$edition, $method, $confidence, $evidence] = $this->linkOrCreateEdition($asset);

            $asset->forceFill([
                'edition_id' => $edition->id,
                'reconciliation' => [
                    'method' => $method,
                    'confidence' => $confidence,
                    'evidence' => $evidence,
                    'version' => self::VERSION,
                ],
            ])->save();

            IngestionEvent::record(IngestionEventType::AssetReconciled, run: $run, payload: [
                'edition' => $edition->public_id,
                'work' => $edition->work->public_id,
                'method' => $method,
                'confidence' => $confidence,
            ]);
        });
    }

    /**
     * Same normalized text, different file: record a candidate for the
     * admin. Never merges, never deletes.
     */
    private function detectContentDuplicates(IngestionRun $run, BookAsset $asset): void
    {
        if ($asset->content_sha256 === null) {
            return;
        }

        $twins = BookAsset::query()
            ->where('content_sha256', $asset->content_sha256)
            ->where('id', '!=', $asset->id)
            ->limit(20)
            ->get();

        foreach ($twins as $twin) {
            $exists = DuplicateCandidate::query()
                ->where(function ($query) use ($asset, $twin) {
                    $query->where('book_asset_id', $asset->id)->where('duplicate_of_asset_id', $twin->id);
                })
                ->orWhere(function ($query) use ($asset, $twin) {
                    $query->where('book_asset_id', $twin->id)->where('duplicate_of_asset_id', $asset->id);
                })
                ->exists();

            if ($exists) {
                continue;
            }

            $candidate = new DuplicateCandidate;
            $candidate->forceFill([
                'book_asset_id' => $asset->id,
                'duplicate_of_asset_id' => $twin->id,
                'reason' => 'content_sha256_match',
                'evidence' => [
                    'content_sha256' => $asset->content_sha256,
                    'fingerprint_version' => $asset->content_fingerprint_version,
                    'metadata_comparison' => [
                        'this' => $this->comparableMetadata($asset),
                        'other' => $this->comparableMetadata($twin),
                    ],
                ],
            ])->save();

            IngestionEvent::record(IngestionEventType::DuplicateCandidateDetected, run: $run, payload: [
                'candidate' => $candidate->public_id,
                'other_asset' => $twin->public_id,
                'reason' => 'content_sha256_match',
            ]);

            // Same normalized content strongly implies the same edition
            // text; adopting the twin's edition (when linked) is the one
            // automatic link we allow, labeled exact via fingerprint.
            if ($asset->edition_id === null && $twin->edition_id !== null) {
                $asset->forceFill([
                    'edition_id' => $twin->edition_id,
                    'reconciliation' => [
                        'method' => 'content_fingerprint',
                        'confidence' => 'exact',
                        'evidence' => ['content_sha256' => $asset->content_sha256, 'twin_asset' => $twin->public_id],
                        'version' => self::VERSION,
                    ],
                ])->save();
            }
        }
    }

    /** @return array{0: Edition, 1: string, 2: string, 3: array} */
    private function linkOrCreateEdition(BookAsset $asset): array
    {
        $meta = $asset->extracted_metadata;
        $title = trim((string) ($meta['title'] ?? '')) ?: pathinfo($asset->original_filename, PATHINFO_FILENAME);
        $normalizedTitle = Work::normalizeTitle($title);
        $language = $meta['languages'][0] ?? null;
        $identifiers = collect($meta['identifiers'] ?? []);

        // 1. Strong identifier (ISBN-13/10) matching an existing edition,
        //    corroborated by the normalized title → high confidence.
        foreach ($identifiers as $identifier) {
            if (! in_array($identifier['scheme'] ?? '', ['isbn13', 'isbn10'], true)) {
                continue;
            }

            $match = EditionIdentifier::query()
                ->where('scheme', $identifier['scheme'])
                ->where('value', $identifier['value'])
                ->with('edition.work')
                ->first();

            if ($match === null) {
                continue;
            }

            if (Work::normalizeTitle($match->edition->title) === $normalizedTitle) {
                return [$match->edition, 'identifier_and_title', 'high_confidence', [
                    'scheme' => $identifier['scheme'],
                    'value' => $identifier['value'],
                ]];
            }
        }

        // 2. Title + primary creator + language agreement → high confidence.
        $primaryCreator = $meta['creators'][0]['name'] ?? null;

        if ($primaryCreator !== null) {
            $normalizedCreator = Contributor::normalizeName($primaryCreator);

            $candidateEdition = Edition::query()
                ->whereHas('work', fn ($works) => $works->where('normalized_title', $normalizedTitle))
                ->when($language !== null, fn ($query) => $query->where('language', $language))
                ->whereHas(
                    'contributors',
                    fn ($contributors) => $contributors->where('normalized_name', $normalizedCreator),
                )
                ->first();

            if ($candidateEdition !== null) {
                return [$candidateEdition, 'title_creator_language', 'high_confidence', [
                    'normalized_title' => $normalizedTitle,
                    'creator' => $normalizedCreator,
                    'language' => $language,
                ]];
            }
        }

        // 3. Unknown book → provisional Work + Edition (reversible).
        $edition = $this->createProvisionalEdition($asset, $meta, $title, $normalizedTitle, $language);

        return [$edition, 'provisional_creation', 'unresolved', [
            'normalized_title' => $normalizedTitle,
        ]];
    }

    private function createProvisionalEdition(
        BookAsset $asset,
        array $meta,
        string $title,
        string $normalizedTitle,
        ?string $language,
    ): Edition {
        $primaryCreator = $meta['creators'][0]['name'] ?? null;

        // Attach to an existing Work only when title AND primary creator
        // both agree; otherwise a new provisional Work.
        $work = null;

        if ($primaryCreator !== null) {
            $normalizedCreator = Contributor::normalizeName($primaryCreator);
            $work = Work::query()
                ->where('normalized_title', $normalizedTitle)
                ->whereHas(
                    'editions.contributors',
                    fn ($contributors) => $contributors->where('normalized_name', $normalizedCreator),
                )
                ->first();
        }

        if ($work === null) {
            $work = new Work;
            $work->forceFill([
                'canonical_title' => mb_substr($title, 0, 1000),
                'normalized_title' => mb_substr($normalizedTitle, 0, 1000),
                'original_language' => $language,
                'status' => 'provisional',
                'reconciliation' => ['method' => 'provisional_creation', 'version' => self::VERSION],
            ])->save();
        }

        $publicationDate = $meta['dates'][0]['value'] ?? $meta['date'] ?? null;

        $edition = new Edition;
        $edition->forceFill([
            'work_id' => $work->id,
            'title' => mb_substr($title, 0, 1000),
            'subtitle' => isset($meta['subtitle']) ? mb_substr((string) $meta['subtitle'], 0, 1000) : null,
            'language' => $language,
            'publisher' => isset($meta['publisher']) ? mb_substr((string) $meta['publisher'], 0, 500) : null,
            'publication_date' => $publicationDate !== null ? mb_substr((string) $publicationDate, 0, 60) : null,
            'publication_year' => $this->extractYear($publicationDate),
            'description' => $meta['description'] ?? null,
            'rights' => $meta['rights'] ?? null,
            'subjects' => $meta['subjects'] ?? null,
            'source_metadata' => [
                'source' => 'epub_opf',
                'parser_version' => StageExecutor::HANDLER_VERSIONS['parse'],
                'asset' => $asset->public_id,
            ],
            'status' => 'provisional',
        ])->save();

        $this->attachContributors($edition, $meta);
        $this->attachIdentifiers($edition, $meta['identifiers'] ?? []);

        return $edition;
    }

    private function attachContributors(Edition $edition, array $meta): void
    {
        $position = 0;
        $entries = array_merge(
            array_map(fn ($creator) => $creator + ['default_role' => 'aut'], $meta['creators'] ?? []),
            array_map(fn ($contributor) => $contributor + ['default_role' => 'oth'], $meta['contributors'] ?? []),
        );

        foreach ($entries as $entry) {
            $name = trim((string) ($entry['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $normalized = Contributor::normalizeName($name);

            $contributor = Contributor::query()->where('normalized_name', $normalized)->first();

            if ($contributor === null) {
                $contributor = new Contributor;
                $contributor->forceFill([
                    'name' => mb_substr($name, 0, 500),
                    'sort_name' => isset($entry['file_as']) ? mb_substr((string) $entry['file_as'], 0, 500) : null,
                    'normalized_name' => mb_substr($normalized, 0, 500),
                ])->save();
            }

            // Worker metadata carries MARC relator codes as a list (an OPF
            // creator can hold several roles); first role wins for the
            // pivot, the raw list survives in extracted_metadata.
            $role = $entry['roles'][0] ?? $entry['role'] ?? $entry['default_role'];

            $edition->contributors()->attach($contributor->id, [
                'role' => mb_substr((string) $role, 0, 12),
                'credited_as' => mb_substr($name, 0, 500),
                'position' => $position++,
            ]);
        }
    }

    private function attachIdentifiers(Edition $edition, array $identifiers): void
    {
        foreach ($identifiers as $identifier) {
            $scheme = (string) ($identifier['scheme'] ?? 'other');
            $value = trim((string) ($identifier['value'] ?? ''));

            if ($value === '') {
                continue;
            }

            $exists = $edition->identifiers()
                ->where('scheme', $scheme)
                ->where('value', $value)
                ->exists();

            if (! $exists) {
                $edition->identifiers()->create([
                    'scheme' => mb_substr($scheme, 0, 30),
                    'value' => mb_substr($value, 0, 500),
                    'raw_value' => mb_substr((string) ($identifier['raw'] ?? $value), 0, 500),
                    'source' => 'epub_opf',
                ]);
            }
        }
    }

    private function extractYear(?string $date): ?int
    {
        if ($date === null || preg_match('/(\d{4})/', $date, $matches) !== 1) {
            return null;
        }

        $year = (int) $matches[1];

        return ($year >= 1400 && $year <= 2100) ? $year : null;
    }

    private function comparableMetadata(BookAsset $asset): array
    {
        $meta = $asset->extracted_metadata ?? [];

        return [
            'asset' => $asset->public_id,
            'title' => $meta['title'] ?? null,
            'creators' => array_column($meta['creators'] ?? [], 'name'),
            'publisher' => $meta['publisher'] ?? null,
            'language' => $meta['languages'][0] ?? null,
            'identifiers' => array_map(
                fn ($identifier) => ($identifier['scheme'] ?? '?').':'.($identifier['value'] ?? ''),
                $meta['identifiers'] ?? [],
            ),
            'sha256' => $asset->sha256,
        ];
    }
}
