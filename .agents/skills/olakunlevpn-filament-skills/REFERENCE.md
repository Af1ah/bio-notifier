# Filament PHP v5 -- Detailed Reference

Complete code examples for every pattern in SKILL.md.

## Resource with Delegation

```php
use App\Filament\Resources\Shop\Products\Schemas\ProductSchema;
use App\Filament\Resources\Shop\Products\Tables\ProductsTable;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;
    protected static ?string $slug = 'products';
    protected static ?string $recordTitleAttribute = 'name';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;
    protected static ?string $navigationGroup = 'Shop';
    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return ProductSchema::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\VariantsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'view' => Pages\ViewProduct::route('/{record}'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
```

## Schema Class (Form)

```php
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Illuminate\Support\Str;

class ProductSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('products.general'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, ?string $state) =>
                            $set('slug', Str::slug($state))
                        ),

                    TextInput::make('slug')
                        ->required()
                        ->maxLength(255)
                        ->disabled()
                        ->dehydrated()
                        ->unique(ignoreRecord: true)
                        ->visibleOn(Operation::Create),

                    Select::make('category_id')
                        ->relationship('category', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),

                    TextInput::make('price')
                        ->required()
                        ->numeric()
                        ->prefix('$'),

                    Toggle::make('is_active')
                        ->default(true),
                ]),

            Section::make(__('products.description'))
                ->schema([
                    RichEditor::make('description')
                        ->columnSpanFull(),
                ]),

            Section::make(__('products.media'))
                ->schema([
                    FileUpload::make('image')
                        ->image()
                        ->disk('public')
                        ->directory('products')
                        ->visibility('public'),
                ]),
        ]);
    }
}
```

## Table Class

```php
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->circular(),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category.name')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('price')
                    ->money('USD')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('category_id')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('is_active'),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->groupedBulkActions([
                DeleteBulkAction::make(),
                ExportAction::make()->exporter(ProductExporter::class),
            ])
            ->toolbarActions([
                CreateAction::make(),
            ]);
    }
}
```

## Enum with Filament Contracts

```php
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum OrderStatus: string implements HasLabel, HasColor, HasIcon
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('orders.status.draft'),
            self::Pending => __('orders.status.pending'),
            self::Processing => __('orders.status.processing'),
            self::Shipped => __('orders.status.shipped'),
            self::Delivered => __('orders.status.delivered'),
            self::Cancelled => __('orders.status.cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Pending => 'warning',
            self::Processing => 'info',
            self::Shipped => 'primary',
            self::Delivered => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function getIcon(): string|BackedEnum
    {
        return match ($this) {
            self::Draft => Heroicon::OutlinedPencilSquare,
            self::Pending => Heroicon::OutlinedClock,
            self::Processing => Heroicon::OutlinedArrowPath,
            self::Shipped => Heroicon::OutlinedTruck,
            self::Delivered => Heroicon::OutlinedCheckCircle,
            self::Cancelled => Heroicon::OutlinedXCircle,
        };
    }
}
```

## Multi-Tenancy Setup

```php
// PanelProvider
public function panel(Panel $panel): Panel
{
    return $panel
        ->id('app')
        ->path('app')
        ->tenant(Team::class, slugAttribute: 'slug')
        ->tenantRegistration(RegisterTeam::class)
        ->tenantProfile(EditTeamProfile::class)
        ->tenantMiddleware([ApplyTenantScopes::class], isPersistent: true);
}

// User Model
class User extends Authenticatable implements FilamentUser, HasTenants
{
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class);
    }

    public function getTenants(Panel $panel): Collection
    {
        return $this->teams;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->teams()->whereKey($tenant)->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'admin') {
            return $this->is_admin && $this->hasVerifiedEmail();
        }
        return true;
    }
}

// CRITICAL: Manually scope form selects
Select::make('author_id')
    ->relationship(
        name: 'author',
        titleAttribute: 'name',
        modifyQueryUsing: fn (Builder $query) => $query->whereBelongsTo(Filament::getTenant()),
    )

// Tenant-aware validation
TextInput::make('email')->scopedUnique()->scopedExists()
```

## Testing

```php
use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team);
    actingAs($this->user);
    Filament::setTenant($this->team);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();
});

// List page
it('can list products', function () {
    $products = Product::factory()->count(5)->create();

    livewire(ListProducts::class)
        ->assertOk()
        ->assertCanSeeTableRecords($products);
});

it('can search products', function () {
    $product = Product::factory()->create(['name' => 'Unique Widget']);

    livewire(ListProducts::class)
        ->searchTable('Unique Widget')
        ->assertCanSeeTableRecords([$product]);
});

it('can filter by category', function () {
    $category = Category::factory()->create();
    $products = Product::factory()->count(3)->create(['category_id' => $category->id]);
    $other = Product::factory()->count(2)->create();

    livewire(ListProducts::class)
        ->filterTable('category_id', $category->id)
        ->assertCanSeeTableRecords($products)
        ->assertCanNotSeeTableRecords($other);
});

// Create page
it('can create a product', function () {
    $data = Product::factory()->make();

    livewire(CreateProduct::class)
        ->fillForm([
            'name' => $data->name,
            'price' => $data->price,
            'category_id' => $data->category_id,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    assertDatabaseHas(Product::class, ['name' => $data->name]);
});

it('validates required fields', function (array $data, array $errors) {
    livewire(CreateProduct::class)
        ->fillForm($data)
        ->call('create')
        ->assertHasFormErrors($errors);
})->with([
    'name required' => [['name' => null], ['name' => 'required']],
    'price required' => [['price' => null], ['price' => 'required']],
]);

// Edit page
it('can update a product', function () {
    $product = Product::factory()->create();

    livewire(EditProduct::class, ['record' => $product->id])
        ->assertSchemaStateSet(['name' => $product->name])
        ->fillForm(['name' => 'Updated Name'])
        ->call('save')
        ->assertNotified();

    expect($product->fresh()->name)->toBe('Updated Name');
});

// Delete via action
it('can delete a product', function () {
    $product = Product::factory()->create();

    livewire(EditProduct::class, ['record' => $product->id])
        ->callAction(DeleteAction::class)
        ->assertNotified()
        ->assertRedirect();

    assertDatabaseMissing(Product::class, ['id' => $product->id]);
});

// Table row action
it('can trigger table action', function () {
    $product = Product::factory()->create();

    livewire(ListProducts::class)
        ->callAction(TestAction::make('archive')->table($product))
        ->assertNotified();
});

// Bulk action
it('can bulk delete products', function () {
    $products = Product::factory()->count(3)->create();

    livewire(ListProducts::class)
        ->selectTableRecords($products->pluck('id')->toArray())
        ->callAction(TestAction::make('delete')->table()->bulk())
        ->assertNotified();
});

// Relation manager
it('can list related variants', function () {
    $product = Product::factory()->has(Variant::factory()->count(3))->create();

    livewire(VariantsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords($product->variants);
});
```

## Panel Configuration (Production)

```php
public function panel(Panel $panel): Panel
{
    return $panel
        ->id('admin')
        ->path('admin')
        ->login()
        ->spa()
        ->unsavedChangesAlerts()
        ->databaseTransactions()
        ->databaseNotifications()
        ->colors(['primary' => Color::Indigo])
        ->viteTheme('resources/css/filament/admin/theme.css')
        ->discoverResources(
            in: app_path('Filament/Resources'),
            for: 'App\\Filament\\Resources'
        )
        ->discoverPages(
            in: app_path('Filament/Pages'),
            for: 'App\\Filament\\Pages'
        )
        ->discoverWidgets(
            in: app_path('Filament/Widgets'),
            for: 'App\\Filament\\Widgets'
        )
        ->discoverClusters(
            in: app_path('Filament/Clusters'),
            for: 'App\\Filament\\Clusters'
        )
        ->middleware([
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
            DisableBladeIconComponents::class,
            DispatchServingFilamentEvent::class,
        ])
        ->authMiddleware([Authenticate::class]);
}
```

## Widget (Stats Overview)

```php
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OrderStatsWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        return [
            Stat::make(__('widgets.total_orders'), Order::count())
                ->description(__('widgets.all_time'))
                ->descriptionIcon(Heroicon::OutlinedShoppingCart)
                ->color('primary'),

            Stat::make(__('widgets.revenue'), '$' . number_format(Order::sum('total') / 100, 2))
                ->description(__('widgets.this_month'))
                ->descriptionIcon(Heroicon::OutlinedCurrencyDollar)
                ->color('success')
                ->chart([7, 3, 4, 5, 6, 3, 5]),

            Stat::make(__('widgets.pending'), Order::where('status', 'pending')->count())
                ->description(__('widgets.needs_attention'))
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color('warning'),
        ];
    }
}
```

## Custom Page with Cluster

```php
// Cluster
namespace App\Filament\Clusters\Settings;

use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

class SettingsCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}

// Custom Page in Cluster
namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use Filament\Actions\Action;
use Filament\Pages\Page;

class ManageBranding extends Page
{
    protected static ?string $cluster = SettingsCluster::class;
    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()->is_admin;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->action(fn () => $this->save())
                ->icon(Heroicon::OutlinedCheck),
        ];
    }
}
```

## Production Deployment

```bash
# Full deploy script
php artisan migrate --force
php artisan optimize
php artisan filament:optimize
php artisan icons:cache
```

**Never in local dev:**
```bash
# These prevent new component discovery
php artisan filament:optimize        # NO
php artisan filament:cache-components # NO
```
