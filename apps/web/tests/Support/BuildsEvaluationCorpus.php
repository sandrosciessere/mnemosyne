<?php

namespace Tests\Support;

use App\Models\BookAsset;

/**
 * Heterogeneous synthetic retrieval corpus (original generated content —
 * never copyrighted text): three books, decoys sharing query words with
 * irrelevant contexts, paraphrase targets whose wording differs from the
 * queries, multilingual/Unicode material and oversized nodes that force
 * chunk splits. Shared by the quality integration tests and the
 * evaluation CLI cases (tests/retrieval/evaluation-cases.json).
 */
trait BuildsEvaluationCorpus
{
    /** @return array{assets: array<string, BookAsset>, canonicals: array<string, string>} */
    protected function buildEvaluationCorpus(): array
    {
        $filler = fn (string $topic, int $n) => array_map(
            fn ($i) => ['text' => "Background passage {$i} about {$topic}, written to give the retrieval corpus realistic bulk and vocabulary variety across many sentences of neutral narration."],
            range(1, $n),
        );

        $bookA = $this->buildArtifacts([
            0 => array_merge(
                [['type' => 'heading', 'text' => "The Cartographer's Secret", 'heading_path' => ["The Cartographer's Secret"]]],
                $filler('an old mapmaker and his workshop by the harbor', 3),
            ),
            1 => [
                ['type' => 'heading', 'text' => 'Chapter One — Instruments', 'heading_path' => ['Chapter One — Instruments']],
                ['text' => 'Among the instruments on the bench, the meridian brass compass sang at dawn, its needle steady against the pull of the northern cliffs while the navigator plotted a course between the shoals.'],
                // Adversarial decoy: shares "compass"/"dawn" in a cooking context.
                ['text' => 'In the galley below, the cook pressed a compass of tin into the dough at dawn, cutting perfect circles for the morning biscuits and humming while the ovens warmed.'],
                ['text' => 'A sextant, two chronometers and a roll of vellum completed the kit that no serious navigator would sail without.'],
            ],
            2 => [
                ['type' => 'heading', 'text' => 'Chapter Two — Memory of Coastlines', 'heading_path' => ['Chapter Two — Memory of Coastlines']],
                ['text' => 'He sketched every chart from the memory of shorelines he had walked as a boy, tracing bays and headlands without ever consulting an atlas, and the harbours grew from his pencil as if the coast itself were dictating.'],
                ['text' => 'Critics said no honest map could be drawn that way, yet ships that followed his lines came home.'],
            ],
        ]);

        $bookB = $this->buildArtifacts([
            0 => array_merge(
                [['type' => 'heading', 'text' => 'Il Giardino delle Ore', 'heading_path' => ['Il Giardino delle Ore']]],
                [[
                    'text' => 'Nel cuore del giardino, il pendolo di ottone segnava le ore perdute, e ogni oscillazione sembrava restituire ai vialetti un minuto dimenticato.',
                ]],
                $filler('un giardino segreto e le sue siepi geometriche', 2),
            ),
            1 => [
                ['type' => 'heading', 'text' => "L'Orologiaio", 'heading_path' => ["L'Orologiaio"]],
                ['text' => "Nella bottega in fondo al vicolo, l'orologiaio riparava meccanismi antichi con dita pazienti, restituendo il battito a ingranaggi che tacevano da decenni."],
                // Decoy sharing "tempo"/"giardino" in a weather context.
                ['text' => 'Le previsioni del tempo annunciavano pioggia sul giardino per tutta la settimana, e i visitatori rimandavano ancora una volta la passeggiata.'],
                ['text' => 'Ogni sera annotava su un registro i lavori compiuti e quelli promessi, con una calligrafia fitta e ordinata.'],
            ],
        ]);

        // Oversized node (forces sentence splitting) with an embedded
        // distinctive phrase, plus Unicode-heavy material.
        $longSentences = [];
        for ($index = 1; $index <= 40; $index++) {
            $longSentences[] = "Long study sentence {$index} continues the meticulous survey of the archive shelves and their catalogued curiosities.";
        }
        // Distinctive pair in the middle: likely to sit near a split.
        array_splice($longSentences, 20, 0, [
            'The tide register hid a marginal note.',
            'That note read: the ninth wave carries the ledger home.',
        ]);

        $bookC = $this->buildArtifacts([
            0 => [
                ['type' => 'heading', 'text' => 'Παράρτημα — 記憶の地図', 'heading_path' => ['Παράρτημα — 記憶の地図']],
                ['text' => 'Ο χαρτογράφος ονόμασε το σχέδιο «χάρτης της μνήμης 記憶の地図» και το κλείδωσε στο συρτάρι.'],
                ['text' => '🌊 the tide answered twice 🌊 before the harbour bell found its voice again, and the fishermen wrote it down as an omen.'],
                ['text' => implode(' ', $longSentences)],
            ],
        ]);

        return [
            'assets' => ['A' => $bookA['asset'], 'B' => $bookB['asset'], 'C' => $bookC['asset']],
            'canonicals' => ['A' => $bookA['canonical'], 'B' => $bookB['canonical'], 'C' => $bookC['canonical']],
        ];
    }
}
