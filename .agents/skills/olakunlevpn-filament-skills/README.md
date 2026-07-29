# Filament PHP v5 Coding Standards

[![GitHub stars](https://img.shields.io/github/stars/olakunlevpn/olakunlevpn-filament-skills?style=flat-square)](https://github.com/olakunlevpn/olakunlevpn-filament-skills/stargazers)
[![License: MIT](https://img.shields.io/badge/License-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)

Professional coding standards for building production-grade Filament PHP v5 admin panels. Covers resources, forms, tables, infolists, actions, widgets, multi-tenancy, testing, authorization, custom pages, plugins, and deployment.

This is an **agent skill** that works with Claude Code, Cursor, Cline, Gemini CLI and 40+ other AI coding agents. Once installed, your agent will follow these standards automatically when writing Filament code.

## Installation

```bash
npx skills add olakunlevpn/olakunlevpn-filament-skills
```

That's it. The skill is now active for all your Filament projects.

## How to Use

The skill auto-triggers when your agent detects Filament work. You can also invoke it directly:

**Create a resource with delegation pattern:**
```
Create a ProductResource with extracted Schema and Table classes, domain-grouped under Shop
```

**Build a form schema:**
```
Create a form schema for Orders with line items repeater, customer select, and status enum
```

**Design a table:**
```
Create a products table with search, filters, toggleable columns, and ActionGroup
```

**Write an enum:**
```
Create an OrderStatus enum implementing HasLabel, HasColor, and HasIcon
```

**Set up multi-tenancy:**
```
Configure multi-tenancy with Team model, slug-based routing, and tenant-scoped selects
```

**Write tests:**
```
Write Pest tests for the ProductResource covering list, create, edit, delete, and relation managers
```

**Create a widget:**
```
Create a StatsOverview widget showing order count, revenue, and pending orders with charts
```

**Set up custom pages with clusters:**
```
Create a Settings cluster with Branding and Notifications sub-pages
```

**Production deployment:**
```
Set up the production panel configuration with SPA mode, optimize commands, and deployment script
```

## What It Does

Your agent writes Filament v5 code that follows these conventions:

**Resources delegate to dedicated Schema and Table classes:**

```php
class ProductResource extends Resource
{
    public static function form(Schema $schema): Schema
    {
        return ProductSchema::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }
}
```

**Enums implement all three Filament contracts:**

```php
enum OrderStatus: string implements HasLabel, HasColor, HasIcon
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Delivered = 'delivered';

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Processing => 'info',
            self::Delivered => 'success',
        };
    }
}
```

**Tables use v5 method names:**

```php
$table
    ->recordActions([
        ActionGroup::make([ViewAction::make(), EditAction::make(), DeleteAction::make()]),
    ])
    ->groupedBulkActions([DeleteBulkAction::make()])
    ->toolbarActions([CreateAction::make()]);
```

**Tests use Livewire with Filament helpers:**

```php
it('can create a product', function () {
    livewire(CreateProduct::class)
        ->fillForm(['name' => 'Widget', 'price' => 1999])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();
});
```

## What's Covered

**18 areas** of Filament v5 development:

- Project structure and domain grouping
- Resource architecture with delegation pattern
- Form design (sections, slugs, repeaters, tabs, file uploads)
- Table design (columns, filters, actions, toggleable, bulk)
- Infolist view patterns
- Enum design system (HasLabel, HasColor, HasIcon)
- Actions and notifications
- Relation managers
- Widgets and dashboards
- Multi-tenancy (scoping, selects, validation, domain/path routing)
- Multi-panel architecture
- Custom pages and cluster navigation
- Testing with Pest + Livewire
- Import and export patterns
- Authorization (FilamentUser, policies)
- Performance and deployment
- Livewire 4 features (islands, async, wire:sort)
- Strict rules and common mistakes

## Checklist

Before submitting any Filament code:
- Resource delegates to Schema and Table classes
- `$recordTitleAttribute` set on every resource
- `Heroicon::` enum for all icons, `Outlined` for navigation
- All actions from `Filament\Actions\*` namespace
- Table uses `recordActions()`, `groupedBulkActions()`, `toolbarActions()`
- Enums implement HasLabel, HasColor, HasIcon
- Form selects are searchable and preloaded
- Multi-tenant form selects manually scoped
- `FilamentUser` interface implemented
- Model policies on every resource
- `filament:optimize` in production deploy
- No hardcoded text -- language files only
- No published Blade views -- CSS hooks only

## Files

```
SKILL.md        # Main skill file (loaded by AI agents)
REFERENCE.md    # Detailed code examples for every pattern
```

## Contributing

Found a pattern that should be included? Open an issue or submit a pull request.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
