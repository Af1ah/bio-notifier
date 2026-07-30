# Development Guide: Toggling Tenancy Modes

Bio-Notifier currently supports two routing methods for multi-tenancy:
1. **Path-Based Routing** (e.g., `noti.ariise.cloud/tenant1/admin`) - *Currently Active*
2. **Domain-Based Routing** (e.g., `tenant1.noti.ariise.cloud/admin`) - *Commented Out*

If you wish to switch from the current Path-Based routing back to Domain-Based routing (for proper subdomains), follow these exact steps:

### 1. Update `app/Providers/Filament/TenantPanelProvider.php`

Open the file and locate the `panel()` method.

**Change the paths and domains:**
```php
// UNCOMMENT these two lines:
->domain('{tenant}.' . $centralDomain)
->path('admin')

// COMMENT OUT this line:
// ->path('{tenant}/admin')
```

**Change the Middleware:**
```php
// UNCOMMENT this line:
\Stancl\Tenancy\Middleware\InitializeTenancyByDomain::class,

// COMMENT OUT this line:
// \App\Http\Middleware\InitializeTenancyByShortname::class,
```

### 2. Update `routes/web.php`

Open `routes/web.php` and locate the `/{tenant}/impersonate` route.

1. **Comment out** the entire `--- PATH-BASED TENANCY ---` block.
2. **Uncomment** the entire `--- DOMAIN-BASED TENANCY ---` block.
3. In the route declaration at the very bottom of that closure, remove the `\App\Http\Middleware\InitializeTenancyByShortname::class` from the middleware array.

```php
// Change this:
})->name('tenant.impersonate')->middleware(['web', \App\Http\Middleware\InitializeTenancyByShortname::class, 'signed']);

// Back to this:
})->name('tenant.impersonate')->middleware(['web', 'signed']);
```

### 3. Clear Caches
After making these changes, run the following commands to flush the routing cache:
```bash
php artisan optimize:clear
php artisan route:clear
```
