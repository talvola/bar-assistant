<?php

declare(strict_types=1);

namespace Kami\Cocktail\Services\Flavor;

/**
 * Result of scoring one bottle against one slot. Port of flavor.py's
 * Assessment dataclass.
 *
 * @property list<string> $flags
 */
final readonly class Assessment
{
    /** @param list<string> $flags */
    public function __construct(
        public float $penalty,
        public bool $disqualified,
        public array $flags,
        public bool $crossCategory = false,
    ) {
    }

    public function verdict(): string
    {
        if ($this->disqualified) {
            return 'off-pattern (hard limit)';
        }
        if ($this->penalty == 0.0) {
            return 'squarely in pattern';
        }
        if ($this->penalty <= 2.0) {
            return 'slight stray';
        }
        return 'notable stray';
    }
}
