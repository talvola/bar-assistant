<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Four tables implementing the Phase A flavor-matching engine natively
     * in BA. Names prefixed `flavor_` so they're easy to upstream or strip.
     *
     * Schema mirrors `flavor_db.py` in the MCP sidecar (SQLite). MySQL types:
     * - JSON for axes_json / also_accept_json (was TEXT in SQLite).
     * - boolean (TINYINT(1)) for `hard` (was INTEGER in SQLite).
     * - Foreign keys with cascading deletes since BA is now authoritative.
     */
    public function up(): void
    {
        // Per-category axis registry. e.g. gin → [juniper, citrus, ...].
        // Bar-agnostic — axes are universal, not per-bar.
        Schema::create('flavor_category_axes', function (Blueprint $table) {
            $table->string('category', 64)->primary();
            $table->json('axes_json');
            $table->timestamps();
        });

        // Sidecar: maps ingredient → category (gin/aquavit/amaro/...). One row
        // per ingredient. Replaces the materialized_path-inference + cross-path
        // special cases from the MCP sidecar. Bar-agnostic.
        Schema::create('flavor_ingredient_categories', function (Blueprint $table) {
            $table->foreignId('ingredient_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('category', 64);
            $table->timestamps();

            $table->index('category', 'fic_category_index');
        });

        // Per-ingredient flavor profiles. One row per (ingredient, axis).
        // Bar-agnostic — palate calibrations are universal. The ingredient
        // itself is bar-scoped via ingredients.bar_id.
        Schema::create('flavor_ingredient_profiles', function (Blueprint $table) {
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('axis', 32);
            $table->unsignedTinyInteger('value');
            // Optional provenance — populated by the Phase A bootstrap port.
            $table->string('source', 32)->nullable();      // 'tgii' | 'llm_from_description' | 'manual' | 'manual_override'
            $table->string('confidence', 16)->nullable();  // 'high' | 'medium' | 'low'
            $table->text('notes')->nullable();
            // `suggestable_for_classics` defaults true; flip to false for
            // novelty/joke/allocated bottles that should never be ranked even
            // when their profile coincidentally fits a recipe.
            $table->boolean('suggestable_for_classics')->default(true);
            $table->date('scored_at')->nullable();
            $table->timestamps();

            $table->primary(['ingredient_id', 'axis']);
            $table->index('ingredient_id', 'fip_ingredient_id_index');
        });

        // Per-slot meta: which category fills this recipe ingredient slot.
        // Bar-scoped via cocktails.bar_id.
        Schema::create('flavor_slot_metas', function (Blueprint $table) {
            $table->foreignId('cocktail_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort');  // 1-based slot index in the recipe
            $table->string('category', 64);
            $table->string('tolerance', 16)->default('style');  // 'exact' | 'style' | 'any'
            $table->foreignId('exact_ingredient_id')->nullable()->constrained('ingredients')->nullOnDelete();
            $table->json('also_accept_json')->nullable();  // list of other categories accepted with cross-cat penalty
            $table->decimal('proof_min', 5, 2)->nullable();
            $table->decimal('proof_max', 5, 2)->nullable();
            $table->timestamps();

            $table->primary(['cocktail_id', 'sort']);
            $table->index('cocktail_id', 'fsm_cocktail_id_index');
        });

        // Per-axis constraint on a slot. Point or Band.
        // Bar-scoped via cocktails.bar_id (no direct column).
        Schema::create('flavor_slot_constraints', function (Blueprint $table) {
            $table->foreignId('cocktail_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort');
            $table->string('axis', 32);
            $table->string('kind', 8);  // 'point' | 'band'
            $table->tinyInteger('point_value')->nullable();
            $table->tinyInteger('band_lo')->nullable();
            $table->tinyInteger('band_hi')->nullable();
            $table->decimal('weight', 4, 2)->default(1.0);
            $table->decimal('out_weight', 4, 2)->default(1.0);
            $table->boolean('hard')->default(false);
            $table->timestamps();

            $table->primary(['cocktail_id', 'sort', 'axis']);
            // Composite FK to ensure slot_meta exists before constraints. Laravel
            // doesn't model multi-col FKs cleanly with foreignId() — declare
            // explicit cascade on the cocktail_id leg; the (cocktail_id, sort)
            // integrity is enforced at the app layer.
            $table->index(['cocktail_id', 'sort'], 'fsc_cocktail_sort_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flavor_slot_constraints');
        Schema::dropIfExists('flavor_slot_metas');
        Schema::dropIfExists('flavor_ingredient_profiles');
        Schema::dropIfExists('flavor_ingredient_categories');
        Schema::dropIfExists('flavor_category_axes');
    }
};
