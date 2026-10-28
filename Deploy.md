# Production deployment and troubleshooting

Bio-Notifier has two services: a Laravel application for organisations, attendance and payroll, and a Go ADMS gateway for device communication. PostgreSQL stores the central application database, one database per organisation, and a separate gateway database. eBio remains available for organisations configured to use it.

This guide describes the current Linux deployment. The Windows offline and semi-offline editions are plans, not installed deployment targets. Check the release notes and [beta verification and recovery](docs/beta-verification.md) for migrations or one-time changes specific to a release.

## Paths and prerequisites

The examples use these paths; replace them if your installation differs:

- Laravel: `/var/www/bio-notifier`
- Gateway source and Compose file: `/opt/bio-device-gateway`
- Application user: `www-data`

Install PHP and its extensions required by `composer.lock`, Composer, PostgreSQL, Nginx, Supervisor, Docker and the Compose plugin. PHP 8.2 or newer is required; use the actual installed PHP-FPM service and socket version. The gateway image is built from its own Dockerfile. Keep the Laravel and gateway releases compatible and deploy them together when their protocol or schema changes.

The gateway management listener must remain private. Configure the device listener and reverse proxy according to the device protocol and network; verify whether each device actually supports TLS. A browser's HTTPS connection does not prove a terminal supports HTTPS. Set DNS and certificates for the domains used by the web application and the device listener, and route `/iclock/` traffic to the device listener, not the management API. Never expose gateway management endpoints or tokens to devices.

## Before an update

1. Identify the release and inspect both working trees. Preserve existing local changes; do not run `git reset --hard` or overwrite configuration to make a checkout clean.
2. Take a restorable backup of the central, all tenant and gateway databases, private storage, and the original Laravel `APP_KEY` and gateway encryption key. Store keys separately and securely. Test restoration in an isolated environment. A database dump alone cannot decrypt protected data.
3. Check disk space, queued work and service health. Plan a maintenance window when schema changes or incompatible releases require one. Coordinate eBio delivery where applicable.

```bash
cd /var/www/bio-notifier
php artisan --version
php artisan migrate:status
php artisan tenants:list
sudo supervisorctl status
cd /opt/bio-device-gateway
docker compose ps
```

Do not print `.env` files, tokens, biometric data or raw device payloads into tickets or shared logs. Do not replace keys on an existing installation. `php artisan key:generate` is for a new installation only.

## First installation

1. Place the Laravel and gateway sources in their respective directories. Create private environment files from the corresponding `.env.example` files and configure each installation's own domains, database credentials and keys. Set `APP_ENV=production`, `APP_DEBUG=false` and the correct `APP_URL` and `CENTRAL_DOMAIN`. Keep runtime files owned and writable by the appropriate service account.
2. Provision PostgreSQL central and tenant access for Laravel and a dedicated gateway database and least-privilege role. Configure the gateway encryption key, management and event tokens, listener addresses and Laravel event URL. Configure the matching shared gateway connection in the master panel. The local provisioning scripts are explicitly local-only and must not be used as a production installer.
3. In the Laravel directory install locked dependencies and build frontend assets when needed. Configure the web server to serve `public/` and route PHP to the installed PHP-FPM socket.

```bash
cd /var/www/bio-notifier
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan migrate --force
php artisan tenants:migrate --force
php artisan storage:link
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Run application commands as the configured application user where practical; ensure it can write `storage`, `bootstrap/cache`, and published assets. A new installation must generate its application key once before using encrypted data. Set up TLS, scheduled tasks, queue workers and the gateway before registering devices. Create the initial master administrator using the application's supported provisioning process.

## Routine update

Use a reviewed release checkout or artifact; do not assume a Git branch is the running release. Back up first. Preserve the production `.env`, storage, keys, tenant data and gateway `.env`. When copying individual files, preserve their relative paths: copying `app/Services/Devices/DeviceProfilePolicy.php` into `app/` does not update the class used by Laravel. Avoid `rsync --delete` unless the destination and exclusions have been reviewed.

From the Laravel directory, as the application user where appropriate:

```bash
cd /var/www/bio-notifier
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
# Run these when frontend sources or dependencies changed:
npm ci
npm run build
php artisan optimize:clear
php artisan migrate --force
php artisan tenants:migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

The central migration and tenant migration commands are both required for a multi-tenant update. `queue:restart` signals workers to exit after their current job; the process manager must restart them. Reload the installed PHP-FPM service to ensure web processes load the release:

```bash
sudo systemctl reload php8.4-fpm  # Replace 8.4 with the installed version.
sudo supervisorctl status
```

For a gateway update, use its existing configuration and rebuild the container:

```bash
cd /opt/bio-device-gateway
docker compose up -d --build
docker compose ps
docker compose logs --tail=50 gateway
```

The gateway applies its own additive schema at startup. Check startup logs before resuming device operations. Do not roll back binaries or migrations blindly: newer encrypted command or receipt data may be incompatible with an older release. Recovery requires a coordinated, tested plan.

## Workers and scheduler

### Optional Redis reference cache

The default application cache, sessions and queues remain database-backed. `REFERENCE_CACHE_ENABLED=true` enables only 30-second branch, department and task-group dropdown caches. Redis access is limited to the `reference` connection; it uses `REDIS_REFERENCE_HOST`, `REDIS_REFERENCE_PORT`, `REDIS_REFERENCE_DB` (default 2), and optional `REDIS_REFERENCE_USERNAME` / `REDIS_REFERENCE_PASSWORD`. Credentials fall back to the corresponding REDIS variables. It uses a 0.2-second connection/read timeout and falls back to the live database if Redis is unavailable. Names may take up to 30 seconds to refresh; payroll validation and employee selection still read the database. Set `REFERENCE_CACHE_ENABLED=false` and refresh cached configuration to disable this optional path.

Tenant Redis stores prefix values and locks with the organisation identity. Tagged tenant flushes are scoped; a raw tenant Redis-store flush is blocked because FLUSHDB ignores key prefixes. Keep Redis private and do not share a destructive maintenance command across applications. This feature does not require changing the Redis process's memory or eviction settings; review those separately for the complete shared service. Do not change CACHE_STORE, SESSION_DRIVER or QUEUE_CONNECTION to enable this limited cache.

Run the existing default Laravel worker for eBio and other application work, plus gateway queue workers. The gateway source contains `scripts/workers.conf.example`; copy and adapt its paths, user, process names and log locations. Its lanes are `device-ingest`, `device-commands`, `attendance-live`, and `exports-notifications` on the `gateway` connection. Keep timeouts below the queue retry interval and allow workers to finish when stopping them. Service names vary by installation; inspect them before restarting.

The scheduler must run every minute, either through an existing supervised scheduler or one cron entry for the application user:

```cron
* * * * * cd /var/www/bio-notifier && php artisan schedule:run >> /dev/null 2>&1
```

Use one scheduler mechanism for the installation. Verify scheduling and workers:

```bash
cd /var/www/bio-notifier
php artisan schedule:list
php artisan queue:failed
sudo supervisorctl status
```

`gateway:recover` is scheduled for bounded recovery. Inspect the failure and durable receipt/command state before replay or retry. Avoid bulk retries, queue deletion or resetting device stamps as a first troubleshooting step.

## Add a device

For an organisation using ADMS, add the terminal once from **Devices → Add device** with its actual serial number, name, location and time zone. The application creates and syncs its gateway binding automatically. Configure the terminal with the device server address from **Connection setup**; use the transport and port supported by that terminal. Select supported verification methods in device settings; a selected scanner type does not establish biometric algorithm compatibility. Refresh device details and check command outcomes when testing a new model.

For an eBio organisation, use its eBio connection and webhook setup. Copy its current webhook URL from the application into eBio and set the expected response to `Success`. Newly created organisations receive credentials automatically; rotating one invalidates the old URL. Do not reset eBio's historical sync date without investigating first.

## After deployment

- Confirm Laravel, PHP-FPM, Supervisor workers, scheduler and gateway container are running. Confirm migrations completed for the central database and every tenant.
- Check the device's last activity and inspect receipt/command status. A queued or acknowledged command is not proof that a physical operation succeeded; verify with the terminal when required.
- Confirm a new punch reaches the correct organisation and device, then confirm attendance processing. Review failed jobs and pending or quarantined receipts without exposing sensitive payloads.
- For payroll changes, check an existing paid payslip and an older month. Do not regenerate or modify processed payslips merely to verify a deployment.

## Troubleshooting

**Page fails after a PHP release:** Check the Laravel log and PHP-FPM log, file ownership and published assets. Run locked `composer install --no-dev`, clear caches and reload the correct PHP-FPM service. A missing development-only provider can indicate stale package discovery/autoload metadata. Do not run `composer update` on production to fix it.

**A device is offline or appears connected after unplugging:** Check the displayed last activity, timezone, current poll interval, device server address and gateway logs. A recent heartbeat indicates recent contact, not a continuously open connection. Confirm the serial and binding belong to the intended organisation before interpreting the status.

**A device or command is missing:** Confirm the organisation uses the gateway, its device serial matches the saved binding, the binding is enabled and synced, and the command is permitted for the device's observed profile. Refresh device details and inspect the command result. Capabilities and compatible fingerprint/face algorithms differ; a check box or INFO response does not prove a template can be transferred. Check the deployed files in their actual relative paths and clear caches after updating.

**A command stays pending or fails:** Check the device's recent polls, the device-commands worker, gateway container and command expiry. Distinguish queued, sent, acknowledgement, returned data and applied data. A read can be acknowledged without returning its data; an ambiguous outcome should be investigated before resending a door, reset or destructive command.

**Attendance is missing:** Check gateway and Laravel receipt state, worker health, quarantine and tenant ownership. For eBio, check webhook URL, exact `Success` response, worker and receipt state. Preserve raw punches and manual corrections. Replay retained evidence only after identifying the problem; clearing device logs or resetting stamps can lose data or resend history.

**Older salary slips do not appear:** Select the payroll month and check the status, employee and organisation filters. Paid slips can be hidden by a status filter. Verify records exist before generating anything again; processed payroll should not be rewritten as a visibility fix.

**Times differ:** Inspect the organisation and device time zones, whether a field represents UTC or local wall time, and the terminal clock. Change display or device time settings only after identifying the source; do not bulk-shift raw historical punches.

**Gateway won't start or deliver:** Check `docker compose ps`, gateway logs, private listener addresses, database reachability, configured tokens and encryption key, Laravel event endpoint, disk space and worker health. A successful HTTP response alone does not establish that a receipt was applied. See the gateway README and [ADMS history and clear](docs/developer/adms-stamps-history-and-clear-2026-10-20.md) for recovery boundaries.
