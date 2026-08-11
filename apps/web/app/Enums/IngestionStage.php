<?php

namespace App\Enums;

/**
 * Stages implemented by the current ingestion pipeline. Future stages
 * (enrich, chunk, embed, summarize, entities, relationships, index,
 * verify) are documented in docs/architecture/epub-ingestion.md and must
 * be appended here when they become real — never faked.
 */
enum IngestionStage: string
{
    case Hash = 'hash';
    case Validate = 'validate';
    case Parse = 'parse';
    case Normalize = 'normalize';
    case Structure = 'structure';

    /** @return list<self> stages in execution order */
    public static function ordered(): array
    {
        return [self::Hash, self::Validate, self::Parse, self::Normalize, self::Structure];
    }

    public static function first(): self
    {
        return self::ordered()[0];
    }

    public function next(): ?self
    {
        $ordered = self::ordered();
        $index = array_search($this, $ordered, true);

        return $ordered[$index + 1] ?? null;
    }

    public function position(): int
    {
        return (int) array_search($this, self::ordered(), true);
    }

    /**
     * Declared progress weights (sum 100) used to report honest, coarse
     * ingestion progress. These weights cover ingestion only — they are
     * not "full analysis" progress.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Hash => 10,
            self::Validate => 15,
            self::Parse => 25,
            self::Normalize => 25,
            self::Structure => 25,
        };
    }

    /** Progress (0-100) once this stage has completed. */
    public function progressAfter(): int
    {
        $total = 0;
        foreach (self::ordered() as $stage) {
            $total += $stage->weight();
            if ($stage === $this) {
                break;
            }
        }

        return $total;
    }
}
