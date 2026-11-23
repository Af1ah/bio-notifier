# Filament 5 upgrade

Updated: 3 November 2026. Local source upgrade; production deployment is separate.

## Versions and recovery point

The previous features were pushed to `origin/work` as `8a7a364` before changing dependencies. The upgrade locks Filament to 5.11.0 and Livewire to 4.4.8. Laravel updates within version 12 to 12.70.2; Tailwind remains on version 4. PHP 8.2 or newer is required.

Follow the [Filament upgrade guide](https://filamentphp.com/docs/5.x/upgrade-guide) and [Livewire upgrade guide](https://livewire.laravel.com/docs/4.x/upgrading). The official `filament/upgrade` tool was installed, run against `app`, and removed afterward. Its resource checks required no automatic source changes. Composer also updated compatible dependencies, including Laravel 12 patch releases; the final versions are recorded in `composer.lock`. Symfony YAML was also patched after Composer identified an advisory.

## Application compatibility

- Rename Livewire's layout and placeholder configuration keys. Keep class-based component generation and enable the version 4 smart-key default.
- Recognize hashed Livewire endpoint paths. Identify update and file requests by their route names so signed tenant context is still verified before choosing a tenant database or session.
- Generate tenant-scoped upload and preview routes from Livewire's endpoint resolver. Validate relative URL signatures, matching Livewire 4's native upload and preview URL generation; keep the signed tenant ID and reject mismatched tenants or modified signatures.
- Preserve existing Filament resources, roles, employee forms, notifications, attendance and payroll behaviour. No business-data migration is required by this framework upgrade. Earlier feature migrations remain a separate deployment requirement.
- Publish the matching Filament assets and rebuild the frontend/PWA assets together. Do not serve the old JavaScript with the upgraded PHP packages.

Regression coverage includes a real HTTP update through Livewire's handler, signed component context, mixed-tenant rejection, native signed uploads/previews, and notification isolation. `composer test` now disables Composer's process timeout so the full suite can finish.

## Release checks

Run `composer install`, `composer test`, `npm ci`, `npm run build`, `composer validate` and `composer check-platform-reqs` using the release lock files. Check deployment proxy/firewall rules for hardcoded `/livewire/` paths: Livewire 4 uses `/livewire-{hash}/` instead. Do not change the application key to change these URLs.

Follow [Deploy.md](../../Deploy.md) for a release. Preserve environment files, keys and tenant data. Refresh application caches and restart the application queue workers after installing the release. Browser checks for login, employee import, attendance dialogs, notifications and installed PWA navigation remain separate from automated tests; run them only when explicitly requested.

## Local validation

- Full `composer test`: 368 passed, 2,405 assertions.
- Frontend/PWA build, Composer validation, platform requirements and install-from-lock dry run passed.
- Composer audit: no security advisories or abandoned packages.
- Route caching preserves hashed endpoints, tenant middleware, native Livewire headers and tenant-scoped file routes; Blade view caching passed.
- The user reports checking most screens locally. Automated checks do not establish full browser, physical device or production acceptance. Production deployment is separate.
- The local `sec` tenant was backed up and its eight previously pending migrations were applied. The missing `device_commands.data_applied` query now succeeds; existing record counts were preserved.
