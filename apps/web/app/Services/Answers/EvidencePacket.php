<?php

namespace App\Services\Answers;

/**
 * The bounded, deterministic evidence context for one generation +
 * verification pass. Generator and verifier may ONLY treat this packet
 * as source material; keys (E1..En) are the only evidence identifiers
 * the model ever sees.
 */
class EvidencePacket
{
    /** @param  array<string, EvidenceUnit>  $units  keyed by E-key in packet order */
    public function __construct(
        public readonly array $units,
        public readonly array $stats,
        public readonly array $diagnostics,
    ) {}

    public function isEmpty(): bool
    {
        return $this->units === [];
    }

    public function unitCount(): int
    {
        return count($this->units);
    }

    public function has(string $key): bool
    {
        return isset($this->units[$key]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->units);
    }

    public function totalChars(): int
    {
        return array_sum(array_map(fn (EvidenceUnit $unit) => $unit->charCount(), $this->units));
    }

    /** @return array<int, int> bookAssetId => unit count */
    public function unitsPerAsset(): array
    {
        $counts = [];

        foreach ($this->units as $unit) {
            $counts[$unit->bookAssetId] = ($counts[$unit->bookAssetId] ?? 0) + 1;
        }

        return $counts;
    }
}
