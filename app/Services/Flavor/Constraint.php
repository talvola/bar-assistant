<?php

declare(strict_types=1);

namespace Kami\Cocktail\Services\Flavor;

/**
 * Tagged union: a slot constraint is either a Point (penalty grows with
 * distance from value) or a Band (zero penalty inside [lo,hi]; graded
 * penalty outside, with `hard=true` causing disqualification).
 *
 * Port of flavor.py's Point/Band dataclasses. PHP doesn't have proper
 * tagged unions; we use a discriminator string `kind` and nullable fields.
 */
final readonly class Constraint
{
    /** @param 'point'|'band' $kind */
    public function __construct(
        public string $kind,
        public ?int $pointValue = null,
        public ?int $bandLo = null,
        public ?int $bandHi = null,
        public float $weight = 1.0,
        public float $outWeight = 1.0,
        public bool $hard = false,
    ) {
    }

    public static function point(int $value, float $weight = 1.0): self
    {
        return new self('point', pointValue: $value, weight: $weight);
    }

    public static function band(int $lo, int $hi, float $outWeight = 1.0, bool $hard = false): self
    {
        return new self('band', bandLo: $lo, bandHi: $hi, outWeight: $outWeight, hard: $hard);
    }
}
