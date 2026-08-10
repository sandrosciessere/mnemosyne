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
use App\Models\IngestionStageAttempt;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Conservative bibliographic reconciliation, run after the structure
 * stage. Confidence classes are labels, not pseudo-scientific numbers:
 * exact | high_confidence | candidate | unresolved. Every automatic
 * decision records method + evidence and is reversible by an admin (the
 * raw metadata artifact and extracted_metadata survive unchanged).
 *
 * Core invariants of this milestone:
 *  - A normalized name/title is EVIDENCE that records may relate, never a
 *    global identity key. Homonyms stay distinct rows (provisional
 *    identity); a name match only proposes a WORK, never an EDITION.
 *  - An EDITION is auto-linked only on strong, non-contradicted evidence:
 *    a canonical (ISBN-13) identifier match, or a content-fingerprint
 *    match — each corroborated and with NO conflicting strong metadata.
 *  - Missing metadata is ABSENT, never affirmative agreement.
 *  - A filename-derived title is a display fallback only; it never
 *    participates in matching.
 */
class ReconciliationService
{
    public const VERSION = '1';

    /** Evidence classification per dimension. */
    private const AGREE = 'agree';

    private const ABSENT = 'absent';

    private const CONFLICT = 'conflict';

    public function reconcile(IngestionRun $run): void
    {
        $asset = $run->asset?->refresh();

        if ($asset === null || $asset->extracted_metadata === null) {
            return;
        }

        DB::transaction(function () use ($run, $asset) {
            $this->detectContentDuplicates($run, $asset);

            if ($asset->edition_id !== null) {
                return; // Reprocessing / fingerprint-adopted asset: keep links.
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
     * admin. Never merges, never deletes. A fingerprint match alone is
     * EVIDENCE, not identity — the twin's Edition is adopted only when the
     * bibliographic metadata independently corroborates it AND no strong
     * dimension conflicts.
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
            $result = $this->openDuplicateCandidate($asset, $twin, 'content_sha256_match', [
                'content_sha256' => $asset->content_sha256,
                'fingerprint_version' => $asset->content_fingerprint_version,
                'metadata_comparison' => [
                    'this' => $this->comparableMetadata($asset),
                    'other' => $this->comparableMetadata($twin),
                ],
            ]);

            if ($result['created']) {
                IngestionEvent::record(IngestionEventType::DuplicateCandidateDetected, run: $run, payload: [
                    'candidate' => $result['candidate']->public_id,
                    'other_asset' => $twin->public_id,
                    'reason' => 'content_sha256_match',
                ]);
            }

            // Adopt the twin's Edition only when the bibliographic metadata
            // corroborates the fingerprint (Path B): title AND creator AND
            // explicit language AGREE, and NO strong dimension conflicts.
            if ($asset->edition_id === null && $twin->edition_id !== null) {
                $edition = $twin->edition;
                $meta = $asset->extracted_metadata ?? [];
                $matchTitle = $this->matchTitleKey($asset);
                $language = $meta['languages'][0] ?? null;
                $dims = $this->classifyDimensions($meta, $edition, $matchTitle, $language);

                if ($dims['title'] === self::AGREE
                    && $dims['creator'] === self::AGREE
                    && $dims['language'] === self::AGREE
                    && ! $this->hasConflict($dims)) {
                    // Item H: adopting an edition must carry the incoming
                    // asset's identifiers into it (previously dropped).
                    $this->attachIdentifiers($edition, $meta['identifiers'] ?? []);

                    $asset->forceFill([
                        'edition_id' => $twin->edition_id,
                        'reconciliation' => [
                            'method' => 'content_fingerprint_with_bibliographic_agreement',
                            'confidence' => 'high_confidence',
                            'evidence' => [
                                'content_sha256' => $asset->content_sha256,
                                'twin_asset' => $twin->public_id,
                                'dimensions' => $dims,
                                'title_source' => 'metadata',
                            ],
                            'version' => self::VERSION,
                        ],
                    ])->save();
                }
                // Conflicting/insufficient metadata: assets stay on distinct
                // provisional editions; the content candidate above is left
                // open for the admin (never an automatic merge).
            }
        }
    }

    /** @return array{0: Edition, 1: string, 2: string, 3: array} */
    private function linkOrCreateEdition(BookAsset $asset): array
    {
        $meta = $asset->extracted_metadata;

        $rawTitle = trim((string) ($meta['title'] ?? ''));
        $titleSource = $rawTitle !== '' ? 'metadata' : 'filename';
        // A filename-derived title is a DISPLAY fallback only (item G): it
        // must never establish identity, so it is null for matching.
        $displayTitle = $rawTitle !== '' ? $rawTitle : pathinfo($asset->original_filename, PATHINFO_FILENAME);
        $normalizedDisplayTitle = $this->titleKey($displayTitle);
        $matchTitle = $titleSource === 'metadata' ? $normalizedDisplayTitle : null;
        $language = $meta['languages'][0] ?? null;

        // Path A: a canonical trusted identifier (ISBN-13) matches an
        // existing edition, the title reasonably agrees, and no strong
        // dimension conflicts → high confidence auto-link.
        foreach ($this->assetCanonicalIsbns($meta) as $isbn) {
            $match = EditionIdentifier::query()
                ->where(function ($query) use ($isbn) {
                    $query->where(function ($q) use ($isbn) {
                        $q->where('canonical_scheme', 'isbn13')->where('canonical_value', $isbn);
                    })->orWhere(function ($q) use ($isbn) {
                        // Legacy rows not yet backfilled with a canonical form.
                        $q->where('scheme', 'isbn13')->where('value', $isbn);
                    });
                })
                ->with(['edition.work', 'edition.contributors', 'edition.identifiers'])
                ->first();

            if ($match === null) {
                continue;
            }

            $edition = $match->edition;
            $dims = $this->classifyDimensions($meta, $edition, $matchTitle, $language);

            if ($dims['title'] === self::AGREE && ! $this->hasConflict($dims)) {
                $this->attachIdentifiers($edition, $meta['identifiers'] ?? []); // item H
                $edition->loadMissing('work');

                return [$edition, 'identifier_and_title', 'high_confidence', [
                    'canonical_isbn' => $isbn,
                    'dimensions' => $dims,
                    'title_source' => $titleSource,
                ]];
            }

            // ISBN matched but the title is absent/contradicted or another
            // strong dimension conflicts → do NOT auto-link. Provisional +
            // a review candidate explaining the relation (item J).
            $this->flagConflictWithEdition($asset, $meta, $edition, $dims);
            break;
        }

        // Item F: title+creator agreement is evidence of the same WORK,
        // never automatically the same EDITION. So we do NOT adopt an
        // edition here; createProvisionalEdition reuses the Work (on
        // title+creator evidence) and mints a distinct provisional Edition.
        $edition = $this->createProvisionalEdition(
            $asset, $meta, $displayTitle, $normalizedDisplayTitle, $matchTitle, $titleSource, $language,
        );

        // A plausible sibling edition (same work title + creator) whose
        // strong metadata conflicts is surfaced as a review candidate.
        $this->flagBibliographicConflicts($asset, $meta, $edition, $matchTitle, $language);

        return [$edition, 'provisional_creation', 'unresolved', [
            'normalized_title' => $normalizedDisplayTitle,
            'title_source' => $titleSource,
        ]];
    }

    private function createProvisionalEdition(
        BookAsset $asset,
        array $meta,
        string $displayTitle,
        string $normalizedDisplayTitle,
        ?string $matchTitle,
        string $titleSource,
        ?string $language,
    ): Edition {
        // Attach to an existing Work only on real (metadata) title +
        // creator EVIDENCE; a filename-derived title never reuses a Work.
        $work = null;
        $creatorKeys = $this->creatorKeys($meta);

        if ($matchTitle !== null && $creatorKeys !== []) {
            $work = Work::query()
                ->where('normalized_title', $matchTitle)
                ->whereHas(
                    'editions.contributors',
                    fn ($contributors) => $contributors->whereIn('normalized_name', $creatorKeys),
                )
                ->first();
        }

        if ($work === null) {
            $work = new Work;
            $work->forceFill([
                'canonical_title' => mb_substr($displayTitle, 0, 1000),
                'normalized_title' => $normalizedDisplayTitle,
                'original_language' => $language,
                'status' => 'provisional',
                'reconciliation' => [
                    'method' => 'provisional_creation',
                    'title_source' => $titleSource,
                    'version' => self::VERSION,
                ],
            ])->save();
        }

        $publicationDate = $meta['dates'][0]['value'] ?? $meta['date'] ?? null;

        $edition = new Edition;
        $edition->forceFill([
            'work_id' => $work->id,
            'title' => mb_substr($displayTitle, 0, 1000),
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
                'parser_version' => $this->parserVersionFor($asset),
                'asset' => $asset->public_id,
                'title_source' => $titleSource,
            ],
            'status' => 'provisional',
        ])->save();

        $this->attachContributors($edition, $meta);
        $this->attachIdentifiers($edition, $meta['identifiers'] ?? []);

        return $edition;
    }

    /**
     * The handler version that actually parsed this asset's metadata, read
     * from the recorded parse attempt (the worker is authoritative — Laravel
     * never mirrors worker versions). Null if not yet recorded.
     */
    private function parserVersionFor(BookAsset $asset): ?string
    {
        return IngestionStageAttempt::query()
            ->whereIn('ingestion_run_id', $asset->runs()->select('ingestion_runs.id'))
            ->where('stage', 'parse')
            ->where('status', 'succeeded')
            ->orderByDesc('id')
            ->value('handler_version');
    }

    /**
     * Provisional identity: a normalized-name match is EVIDENCE for future
     * authority resolution, NEVER a global identity. Absent strong
     * corroborating identity evidence (authority ids — none in metadata
     * yet), every bibliographic credit becomes a fresh Contributor row.
     * Two separate books each crediting "John Smith" therefore yield two
     * rows; normalized_name stays populated for search/candidate matching.
     */
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

            $normalized = $this->nameKey($name);

            $contributor = new Contributor;
            $contributor->forceFill([
                'name' => mb_substr($name, 0, 500),
                'sort_name' => isset($entry['file_as']) ? mb_substr((string) $entry['file_as'], 0, 500) : null,
                'normalized_name' => $normalized,
            ])->save();

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

            [$canonicalScheme, $canonicalValue] = $this->canonicalIdentifier($identifier, $scheme, $value);

            $exists = $edition->identifiers()
                ->where('scheme', $scheme)
                ->where('value', $value)
                ->exists();

            if (! $exists) {
                $edition->identifiers()->create([
                    'scheme' => mb_substr($scheme, 0, 30),
                    'value' => mb_substr($value, 0, 500),
                    'canonical_scheme' => $canonicalScheme !== null ? mb_substr($canonicalScheme, 0, 30) : null,
                    'canonical_value' => $canonicalValue !== null ? mb_substr($canonicalValue, 0, 500) : null,
                    'raw_value' => mb_substr((string) ($identifier['raw'] ?? $value), 0, 500),
                    'source' => 'epub_opf',
                ]);
            }
        }
    }

    // --- Evidence classification (AGREE / ABSENT / CONFLICT) -------------

    /**
     * Classify each evidence dimension for an asset against an existing
     * edition. Missing data on either side is ABSENT — never agreement.
     *
     * @return array{title: string, creator: string, language: string, identifier: string, publisher_year: string}
     */
    private function classifyDimensions(array $meta, Edition $edition, ?string $matchTitle, ?string $language): array
    {
        $edition->loadMissing(['contributors', 'identifiers']);

        return [
            'title' => $this->classifyTitle($matchTitle, $edition),
            'creator' => $this->classifyCreator($meta, $edition),
            'language' => $this->classifyLanguage($language, $edition->language),
            'identifier' => $this->classifyIdentifier($meta, $edition),
            'publisher_year' => $this->classifyPublisherYear($meta, $edition),
        ];
    }

    private function classifyTitle(?string $matchTitle, Edition $edition): string
    {
        if ($matchTitle === null || $matchTitle === '') {
            return self::ABSENT; // filename-derived or empty: not identity.
        }

        return $matchTitle === $this->titleKey((string) $edition->title) ? self::AGREE : self::CONFLICT;
    }

    private function classifyCreator(array $meta, Edition $edition): string
    {
        $assetKeys = $this->creatorKeys($meta);
        $editionKeys = $edition->contributors
            ->map(fn ($contributor) => $contributor->normalized_name)
            ->filter()
            ->values()
            ->all();

        if ($assetKeys === [] || $editionKeys === []) {
            return self::ABSENT;
        }

        return array_intersect($assetKeys, $editionKeys) !== [] ? self::AGREE : self::CONFLICT;
    }

    private function classifyLanguage(?string $assetLanguage, ?string $editionLanguage): string
    {
        if ($assetLanguage === null || $assetLanguage === '' || $editionLanguage === null || $editionLanguage === '') {
            return self::ABSENT;
        }

        return $assetLanguage === $editionLanguage ? self::AGREE : self::CONFLICT;
    }

    private function classifyIdentifier(array $meta, Edition $edition): string
    {
        $assetTokens = $this->assetIdentifierTokens($meta);
        $editionTokens = $this->editionIdentifierTokens($edition);

        if ($assetTokens === [] || $editionTokens === []) {
            return self::ABSENT;
        }

        return array_intersect($assetTokens, $editionTokens) !== [] ? self::AGREE : self::CONFLICT;
    }

    private function classifyPublisherYear(array $meta, Edition $edition): string
    {
        $assetPublisher = $this->publisherKey($meta['publisher'] ?? null);
        $assetYear = $this->extractYear($meta['dates'][0]['value'] ?? $meta['date'] ?? null);
        $editionPublisher = $this->publisherKey($edition->publisher);
        $editionYear = $edition->publication_year;

        if ($assetPublisher === null || $assetYear === null || $editionPublisher === null || $editionYear === null) {
            return self::ABSENT;
        }

        return ($assetPublisher === $editionPublisher && $assetYear === (int) $editionYear)
            ? self::AGREE
            : self::CONFLICT;
    }

    private function hasConflict(array $dimensions): bool
    {
        return in_array(self::CONFLICT, $dimensions, true);
    }

    // --- Conflict review signals ----------------------------------------

    /**
     * A candidate exists but strong metadata conflicts: record why the two
     * assets look related and what disagrees. Never an auto-merge.
     */
    private function flagConflictWithEdition(BookAsset $asset, array $meta, Edition $edition, array $dims): void
    {
        if (! $this->hasConflict($dims)) {
            return;
        }

        $other = $edition->assets()->first();

        if ($other === null) {
            return;
        }

        $this->openDuplicateCandidate($asset, $other, 'bibliographic_conflict', [
            'reason' => 'strong_identifier_or_metadata_conflict',
            'dimensions' => $dims,
            'metadata_comparison' => [
                'this' => $this->comparableMetadata($asset),
                'other' => $this->comparableMetadata($other),
            ],
        ]);
    }

    /**
     * Sibling editions sharing the same WORK evidence (normalized title +
     * creator) whose strong metadata conflicts are surfaced for review
     * (item J). Distinct editions are preferred over any collapse.
     */
    private function flagBibliographicConflicts(
        BookAsset $asset,
        array $meta,
        Edition $newEdition,
        ?string $matchTitle,
        ?string $language,
    ): void {
        if ($matchTitle === null) {
            return; // filename-derived title never establishes a relation.
        }

        $creatorKeys = $this->creatorKeys($meta);

        if ($creatorKeys === []) {
            return;
        }

        $siblings = Edition::query()
            ->where('id', '!=', $newEdition->id)
            ->whereHas('work', fn ($work) => $work->where('normalized_title', $matchTitle))
            ->whereHas('contributors', fn ($contributors) => $contributors->whereIn('normalized_name', $creatorKeys))
            ->with(['contributors', 'identifiers', 'assets'])
            ->limit(20)
            ->get();

        foreach ($siblings as $sibling) {
            $dims = $this->classifyDimensions($meta, $sibling, $matchTitle, $language);

            if ($this->hasConflict($dims)) {
                $this->flagConflictWithEdition($asset, $meta, $sibling, $dims);
            }
        }
    }

    /**
     * Symmetric, race-safe candidate creation. The pair is stored in
     * canonical (low, high) order in both the directional columns (kept
     * for display/provenance) and the canonical columns (DB-enforced
     * symmetric unique). insertOrIgnore makes a reversed/concurrent
     * creation a no-op instead of a crash.
     *
     * @return array{candidate: DuplicateCandidate|null, created: bool}
     */
    private function openDuplicateCandidate(BookAsset $a, BookAsset $b, string $reason, array $evidence): array
    {
        if ($a->id === $b->id) {
            return ['candidate' => null, 'created' => false];
        }

        [$low, $high] = DuplicateCandidate::orderedPair($a->id, $b->id);
        $now = now();

        $inserted = DB::table('duplicate_candidates')->insertOrIgnore([
            'public_id' => strtolower((string) Str::ulid()),
            'book_asset_id' => $low,
            'duplicate_of_asset_id' => $high,
            'asset_low_id' => $low,
            'asset_high_id' => $high,
            'reason' => $reason,
            'evidence' => json_encode($evidence),
            'status' => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $candidate = DuplicateCandidate::query()
            ->forPair($low, $high)
            ->where('reason', $reason)
            ->first();

        return ['candidate' => $candidate, 'created' => $inserted > 0];
    }

    // --- Canonical identifier helpers -----------------------------------

    /**
     * Canonical ISBN-13 forms declared by the asset (the worker derives
     * `isbn13` for a valid ISBN-10, so an ISBN-10 and its ISBN-13
     * equivalent match as the same identifier).
     *
     * @return list<string>
     */
    private function assetCanonicalIsbns(array $meta): array
    {
        $isbns = [];

        foreach ($meta['identifiers'] ?? [] as $identifier) {
            $isbn13 = $identifier['isbn13'] ?? null;

            if (is_string($isbn13) && $isbn13 !== '') {
                $isbns[] = $isbn13;
            } elseif (($identifier['scheme'] ?? null) === 'isbn13' && ! empty($identifier['value'])) {
                $isbns[] = (string) $identifier['value'];
            }
        }

        return array_values(array_unique($isbns));
    }

    /**
     * Comparable strong-identifier token set for the asset: canonical
     * ISBNs plus exact doi/uuid values. Used only to detect AGREE/CONFLICT.
     *
     * @return list<string>
     */
    private function assetIdentifierTokens(array $meta): array
    {
        $tokens = [];

        foreach ($this->assetCanonicalIsbns($meta) as $isbn) {
            $tokens[] = 'isbn:'.$isbn;
        }

        foreach ($meta['identifiers'] ?? [] as $identifier) {
            $scheme = $identifier['scheme'] ?? null;
            $value = trim((string) ($identifier['value'] ?? ''));

            if ($value !== '' && in_array($scheme, ['doi', 'uuid'], true)) {
                $tokens[] = $scheme.':'.$value;
            }
        }

        return array_values(array_unique($tokens));
    }

    /** @return list<string> */
    private function editionIdentifierTokens(Edition $edition): array
    {
        $tokens = [];

        foreach ($edition->identifiers as $identifier) {
            $canonicalScheme = $identifier->canonical_scheme;
            $canonicalValue = $identifier->canonical_value;

            if ($canonicalScheme === 'isbn13' && $canonicalValue) {
                $tokens[] = 'isbn:'.$canonicalValue;
            } elseif ($identifier->scheme === 'isbn13' && $identifier->value) {
                $tokens[] = 'isbn:'.$identifier->value; // legacy, not backfilled
            } elseif (in_array($identifier->scheme, ['doi', 'uuid'], true) && $identifier->value) {
                $tokens[] = $identifier->scheme.':'.$identifier->value;
            }
        }

        return array_values(array_unique($tokens));
    }

    /** @return array{0: ?string, 1: ?string} [canonical_scheme, canonical_value] */
    private function canonicalIdentifier(array $identifier, string $scheme, string $value): array
    {
        $isbn13 = $identifier['isbn13'] ?? null;

        if (is_string($isbn13) && $isbn13 !== '') {
            return ['isbn13', $isbn13];
        }

        if ($scheme === 'isbn13') {
            return ['isbn13', $value];
        }

        if (in_array($scheme, ['doi', 'uuid'], true)) {
            return [$scheme, $value];
        }

        return [null, null];
    }

    // --- Normalization helpers (store & match on the SAME form) ---------

    /** Truncated normalized title key — identical for storage and matching. */
    private function titleKey(string $title): string
    {
        return mb_substr(Work::normalizeTitle($title), 0, 1000);
    }

    /** Truncated normalized name key — identical for storage and matching. */
    private function nameKey(string $name): string
    {
        return mb_substr(Contributor::normalizeName($name), 0, 500);
    }

    /** Metadata title normalized for matching, or null if filename-derived. */
    private function matchTitleKey(BookAsset $asset): ?string
    {
        $title = trim((string) (($asset->extracted_metadata['title'] ?? '')));

        return $title === '' ? null : $this->titleKey($title);
    }

    /**
     * Normalized keys for every credited creator/contributor name. Used as
     * WORK-matching EVIDENCE, never as contributor-row identity.
     *
     * @return list<string>
     */
    private function creatorKeys(array $meta): array
    {
        $keys = [];

        foreach (array_merge($meta['creators'] ?? [], $meta['contributors'] ?? []) as $person) {
            $name = trim((string) ($person['name'] ?? ''));

            if ($name !== '') {
                $keys[] = $this->nameKey($name);
            }
        }

        return array_values(array_unique($keys));
    }

    private function publisherKey(?string $publisher): ?string
    {
        if ($publisher === null) {
            return null;
        }

        $key = trim(preg_replace('/\s+/u', ' ', mb_strtolower($publisher)) ?? $publisher);

        return $key === '' ? null : $key;
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
