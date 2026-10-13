## Matrix ESP32 bridge

Start with [the project handoff and pin configuration](docs/matrix-project-handoff.md) for the Matrix firmware, gateway, deployment, decisions and remaining work.

# Biomatrix

Biomatrix is a multi-tenant Laravel application for direct biometric-device attendance management. It is the Matrix companion tool and supports ZKTeco ADMS polling, Matrix COSEC HTTP devices, and Hikvision ISAPI devices.

## Device connectivity

- ZKTeco devices call the ADMS endpoints under `/iclock`. The application identifies the tenant by device serial number and queues incoming attendance payloads.
- Matrix COSEC devices are managed through their HTTP API and can use the Matrix push endpoints at `/login` and `/matrix/*`.
- Hikvision devices use ISAPI through the `shaykhnazar/hikvision-isapi` package.
- User profiles and fingerprint templates are transferred using device commands. Select users in the tenant panel and use **Push to Device** to choose profile data and biometric templates.

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
```

Use a queue worker for received attendance payloads and direct-device command jobs:

```bash
php artisan queue:work
```

## Upgrading from the eBio build

Run migrations after deployment. The cleanup migration removes the retired eBio credential columns from the central organisations table. eBio SOAP jobs and webhook endpoints are no longer part of the application.
