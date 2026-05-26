<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Seed the 11 flavor categories that came out of Phase A. Mirrors
     * DEFAULT_AXES in the MCP sidecar's flavor_db.py at the time of Slice 1.
     * Idempotent — uses INSERT ... ON CONFLICT semantics via Eloquent
     * updateOrCreate (so re-running the migration on an existing DB just
     * confirms the rows are there).
     */
    public function up(): void
    {
        $rows = [
            'gin' => ['juniper', 'citrus', 'floral', 'heat', 'spice', 'herbal', 'fruited'],
            'aquavit' => ['juniper', 'citrus', 'floral', 'heat', 'spice', 'herbal'],
            'bourbon' => ['spice', 'sweet', 'oak', 'vanilla', 'fruit', 'body'],
            'rye' => ['spice', 'sweet', 'oak', 'vanilla', 'fruit', 'body'],
            'scotch' => ['smoke', 'sweet', 'oak', 'vanilla', 'fruit', 'body'],
            'american_single_malt' => ['smoke', 'sweet', 'oak', 'vanilla', 'fruit', 'body'],
            'amaro' => ['bitter', 'sweet', 'citrus', 'herbal', 'dark', 'mint', 'root'],
            'herbal_liqueur' => ['herbal', 'sweet', 'anise', 'honey', 'spice', 'cooling'],
            'rum' => ['funk', 'sweet', 'oak', 'vanilla', 'molasses', 'grassy'],
            'vermouth' => ['sweet', 'bitter', 'citrus', 'herbal', 'floral', 'fruited'],
            'fruit_liqueur' => ['sweet', 'citrus', 'orchard', 'berry', 'floral', 'tropical', 'spice'],
        ];

        $now = now();
        foreach ($rows as $category => $axes) {
            DB::table('flavor_category_axes')->updateOrInsert(
                ['category' => $category],
                [
                    'axes_json' => json_encode($axes),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        // Leave the rows in place on rollback — they're config seed data,
        // not user data. The create-table migration's down() will drop them.
    }
};
