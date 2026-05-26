<?php

declare(strict_types=1);

namespace Kami\Cocktail\Services\Flavor;

/**
 * A recipe slot — one ingredient position with category + tolerance + axis
 * constraints. Built from SlotMeta + SlotConstraint rows by FlavorService.
 *
 * Port of flavor.py's RecipeSlot dataclass.
 *
 * @property array<string, Constraint> $constraints  axis name → constraint
 * @property list<string> $alsoAcceptCategories
 */
final readonly class RecipeSlot
{
    /**
     * @param array<string, Constraint> $constraints
     * @param list<string> $alsoAcceptCategories
     */
    public function __construct(
        public int $cocktailId,
        public int $sort,
        public string $category,
        public string $tolerance = 'style',
        public ?int $exactIngredientId = null,
        public array $constraints = [],
        public array $alsoAcceptCategories = [],
        public ?float $proofMin = null,
        public ?float $proofMax = null,
        public float $crossCategoryPenalty = 1.0,
    ) {
    }

    /** @return list<string> */
    public function eligibleCategories(): array
    {
        return array_unique(array_merge([$this->category], $this->alsoAcceptCategories));
    }
}
