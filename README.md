# Bio-Notifier

Bio-Notifier manages attendance, employee records, leave and payroll for multiple organisations. Each organisation has its own tenant database. Laravel and Filament provide the administration interface; a separate Go ADMS gateway receives device traffic and queues commands. Organisations already using eBio can continue to use its integration.

Device verification and template compatibility vary by terminal and firmware. A queued command, a gateway receipt, and a terminal acknowledgement are separate stages; physical acceptance must be verified on the device when needed.

## Deployment and operations

See [Deploy.md](Deploy.md) for the Linux installation, paired Laravel and gateway updates, worker and scheduler setup, device registration, and troubleshooting. The gateway source has its own README and Compose configuration. Keep its management interface private and preserve its encryption key alongside the Laravel application key in secure, recoverable backups.

The Windows local-first and semi-offline approaches are proposals, not available installers. See [local-first options](docs/developer/local-first-options.md).

## Development

The Laravel project requires PHP 8.2 or newer, Composer, and its installed database and frontend dependencies. Configure a local `.env`, install dependencies and run central and tenant migrations appropriate to the local environment. The Go gateway is maintained separately and uses its own configuration and PostgreSQL database. Do not use production credentials for tests.

```bash
composer install
npm ci
npm run build
php artisan test
```

Automated tests use isolated test fixtures; see [beta verification and recovery](docs/beta-verification.md) for their scope and limitations. Pending beta work is tracked in [beta feature tasks](docs/beta-feature-tasks.md).

## License

The project is licensed under the [MIT license](LICENSE).
