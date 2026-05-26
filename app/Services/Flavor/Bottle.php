<?php

declare(strict_types=1);

namespace Kami\Cocktail\Services\Flavor;

/**
 * A bottle (BA ingredient) with a flavor profile, ready to score against a
 * recipe slot. Built from IngredientProfile rows by FlavorService.
 *
 * Port of flavor.py's Bottle dataclass.
 *
 * @property array<string, int> $profile  axis name → 0-3 value
 */
final readonly class Bottle
{
    /** @param array<string, int> $profile */
    public function __construct(
        public int $id,
        public string $name,
        public string $category,
        public array $profile,
        public ?float $proof = null,
        public bool $inStock = true,
        public string $source = '',
        public string $confidence = '',
        public string $notes = '',
        public bool $suggestableForClassics = true,
    ) {
    }
}
