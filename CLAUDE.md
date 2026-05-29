# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Bar Assistant is a Laravel 12 API for managing cocktail recipes and home bars. It provides cocktail-specific features like ingredient substitutes, ABV calculations, and unit conversions. The frontend client is a separate project ([Salt Rim](https://github.com/karlomikus/vue-salt-rim)).

## Development Commands

```bash
# Start development environment
docker compose up -d
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan storage:link
docker compose exec app php artisan migrate

# Run all tests
composer test
# Or directly: php artisan test

# Run a single test file
php artisan test tests/Feature/CocktailControllerTest.php

# Run a single test method
php artisan test --filter=test_list_cocktails_response

# Static analysis (PHPStan level 7)
composer static
# Or: vendor/bin/phpstan analyse

# Code style (Laravel Pint, PSR-12)
composer fix-style
# Check only: vendor/bin/pint --test

# Generate OpenAPI spec
composer openapi
```

## Architecture

### Multi-tenancy via Bars
All major resources (cocktails, ingredients, tags, glasses, etc.) belong to a Bar. The `bar_id` query parameter is required for most endpoints and is validated by `EnsureRequestHasBarQuery` middleware.

The `bar()` helper function (defined in `app/helpers.php`) returns the current Bar from `BarContext`, which is set by the middleware.

### Key Domain Models
- **Cocktail** (`app/Models/Cocktail.php`): Core model with ingredients, tags, glass, method. Calculates ABV, volume, calories. Supports public sharing via ULID.
- **Ingredient** (`app/Models/Ingredient.php`): Hierarchical (materialized path for ancestry). Has strength, prices, and can be "complex" (composed of other ingredients).
- **Bar** (`app/Models/Bar.php`): Multi-tenant container. Has memberships (users with roles), shelf ingredients, and Meilisearch tenant tokens.
- **User**: Has shelf ingredients (what they own) and shopping lists per bar.

### Services Layer
- `CocktailService`: CRUD operations, finding cocktails by available ingredients
- `IngredientService`: CRUD, hierarchy management
- `Image/ImageService`: Image processing with libvips (thumbhash generation)
- `MeilisearchService`: Search indexing with bar-scoped tenant tokens

### External Integrations
- **Meilisearch**: Full-text search for cocktails/ingredients (Laravel Scout)
- **Recipe Scrapers** (`app/Scraper/`): Import cocktails from external sites
- **Export/Import** (`app/External/`): JSON, YAML, Markdown, XML, CSV, DataPack formats

### Authentication
- Laravel Sanctum for API tokens with ability-based permissions
- SSO support: Authentik, Authelia, Kanidm, Keycloak, PocketID, Zitadel

## Testing

Tests use SQLite in-memory database (configured in `phpunit.xml`). Test suites:
- `Unit`: Unit tests in `tests/Unit/`
- `Feature`: API integration tests in `tests/Feature/`
- `Scrapers`: Scraper tests in `tests/Scrapers/`

- Several models (Bar, BarIngredient, CocktailIngredient) define no `$fillable` — use factories (`->for()->create()`) or `DB::table()->insert()` in seeders/tests, not `Model::create([...])` (throws MassAssignmentException).

## Code Style

- PSR-12 via Laravel Pint (config in `pint.json`)
- Imports ordered by length
- PHPStan level 7 with Larastan extension
- Use `declare(strict_types=1)` in all PHP files
