<?php

declare(strict_types=1);

namespace Kami\Cocktail\Models\Flavor;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-axis constraint on a recipe slot. Point or Band. Composite PK on
 * (cocktail_id, sort, axis).
 *
 * @property int $cocktail_id
 * @property int $sort
 * @property string $axis
 * @property string $kind       'point' | 'band'
 * @property ?int $point_value
 * @property ?int $band_lo
 * @property ?int $band_hi
 * @property float $weight      penalty multiplier for Point distance
 * @property float $out_weight  penalty multiplier for Band out-of-range
 * @property bool $hard         if true, Band breach disqualifies the candidate
 */
class SlotConstraint extends Model
{
    protected $table = 'flavor_slot_constraints';
    public $incrementing = false;
    protected $primaryKey = null;

    protected $fillable = [
        'cocktail_id',
        'sort',
        'axis',
        'kind',
        'point_value',
        'band_lo',
        'band_hi',
        'weight',
        'out_weight',
        'hard',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'point_value' => 'integer',
            'band_lo' => 'integer',
            'band_hi' => 'integer',
            'weight' => 'float',
            'out_weight' => 'float',
            'hard' => 'boolean',
        ];
    }
}
