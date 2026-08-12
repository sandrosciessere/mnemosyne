<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\BookAsset;
use App\Models\GroundedAnswerRun;
use App\Services\Reader\ReaderResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Evidence Reader v1: canonical source rendering with exact evidence
 * highlighting. Deep links carry durable answer/evidence identity —
 * resolution never touches retrieval chunks, so citations keep working
 * after generation supersession. Stale sources fail closed with an
 * explicit status instead of a possibly-wrong highlight.
 */
class ReaderController extends Controller
{
    public function show(Request $request, BookAsset $asset, ReaderResolver $resolver): Response
    {
        $user = $request->user();

        abort_unless($user->isAdmin() || $user->can('view', $asset), 403);

        $sections = $resolver->sections($asset);
        $highlights = [];
        $staleNotices = [];
        $answerPublicId = null;
        $requestedSection = $request->query('section');
        $spineIndex = is_numeric($requestedSection) ? (int) $requestedSection : null;

        // Citation deep link: ?answer=<ulid>&evidence=E1,E2
        if (is_string($request->query('answer')) && $request->query('answer') !== '') {
            $answer = GroundedAnswerRun::query()
                ->where('public_id', $request->query('answer'))
                ->first();

            // Fail closed: unknown answers and other users' answers are
            // indistinguishable.
            abort_if($answer === null || ($answer->user_id !== $user->id && ! $user->isAdmin()), 403);

            $answerPublicId = $answer->public_id;
            $keys = array_filter(explode(',', (string) $request->query('evidence', '')));

            foreach ($answer->evidence()->with('claims')->whereIn('evidence_key', $keys)->get() as $evidence) {
                if ($evidence->book_asset_id !== $asset->id) {
                    continue; // evidence for a different book in the same answer
                }

                $resolved = $resolver->resolveEvidence($evidence);

                if ($resolved['status'] !== 'ok') {
                    $staleNotices[] = [
                        'evidence_key' => $evidence->evidence_key,
                        'citation_number' => $evidence->citation_number,
                        'status' => $resolved['status'],
                    ];

                    continue;
                }

                $spineIndex ??= $resolved['spine_index'];

                // Highlight the minimal verified CitationSpans (atoms
                // selected by the verifier + gate) rather than the whole
                // EvidenceUnit; units cited without spans (pre-corrective
                // answers) fall back to the full unit range. Atom offsets
                // are absolute — shift into the node-relative frame the
                // resolver established for the unit.
                $spans = [];

                foreach ($evidence->claims as $claim) {
                    foreach (json_decode((string) ($claim->pivot->atoms ?? '[]'), true) ?: [] as $atom) {
                        $spans[$atom['key']] = $atom;
                    }
                }

                if ($spans === []) {
                    $highlights[] = [
                        'evidence_key' => $evidence->evidence_key,
                        'citation_number' => $evidence->citation_number,
                        'spine_index' => $resolved['spine_index'],
                        'node_id' => $resolved['node_id'],
                        'utf16_start' => $resolved['utf16_start'],
                        'utf16_end' => $resolved['utf16_end'],
                    ];

                    continue;
                }

                foreach ($spans as $atom) {
                    $highlights[] = [
                        'evidence_key' => $evidence->evidence_key,
                        'citation_number' => $evidence->citation_number,
                        'spine_index' => $resolved['spine_index'],
                        'node_id' => $resolved['node_id'],
                        'utf16_start' => $resolved['utf16_start'] + ($atom['utf16_start'] - $evidence->utf16_start),
                        'utf16_end' => $resolved['utf16_start'] + ($atom['utf16_end'] - $evidence->utf16_start),
                    ];
                }
            }
        }

        $spineIndex ??= ($sections[0]['spine_index'] ?? 0);
        $nodes = $resolver->section($asset, $spineIndex);

        return Inertia::render('library/reader', [
            'asset' => [
                'public_id' => $asset->public_id,
                'title' => $asset->edition?->title ?? $asset->original_filename,
                'edition_label' => $asset->edition?->subtitle,
            ],
            'sections' => $sections,
            'current_section' => [
                'spine_index' => $spineIndex,
                'label' => collect($sections)->firstWhere('spine_index', $spineIndex)['label'] ?? null,
                'nodes' => $nodes ?? [],
                'missing' => $nodes === null,
            ],
            'highlights' => array_values(array_filter(
                $highlights,
                fn ($highlight) => $highlight['spine_index'] === $spineIndex,
            )),
            'stale_notices' => $staleNotices,
            'answer_id' => $answerPublicId,
        ]);
    }
}
