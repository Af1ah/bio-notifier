# Bio-Notifier Production Deployment Guide

This guide covers the necessary steps to deploy Bio-Notifier in a production environment, ensuring high availability, security, and multi-tenant performance.

## 1. Subdomain Tenancy & Wildcard DNS

Bio-Notifier uses an isolated domain-based routing system for tenants. 
* The Master Admin logs into the root domain (e.g., `https://noti.aflahdev.in/master`).
* Tenant clients log into their specific subdomains (e.g., `https://company.noti.aflahdev.in/admin`).

### DNS Configuration
You must configure a **Wildcard DNS A-Record** in your domain registrar (e.g., Cloudflare, Route53, Namecheap):
- **Type:** `A`
- **Name:** `*`
- **Value:** `YOUR_SERVER_IP_ADDRESS`

### Nginx / Apache Configuration
Ensure your web server is configured to accept wildcard subdomains and pass them to the Laravel `public/index.php` entrypoint.

## 2. Queue Workers (Supervisor)

The eBio Server Webhook is strictly designed as a **Fire & Forget** asynchronous system to prevent data loss and prevent crashing the biometric devices.

You **must** have a background queue worker running at all times.

1. Install Supervisor on your server: `sudo apt install supervisor`
2. Create a configuration file at `/etc/supervisor/conf.d/bio-notifier-worker.conf`:

```ini
[program:bio-notifier-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/bio-notifier/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=5
redirect_stderr=true
stdout_logfile=/path/to/bio-notifier/storage/logs/worker.log
stopwaitsecs=3600
```

*Note: `numprocs=5` ensures you have 5 workers running simultaneously to handle the morning rush hour of device check-ins.*

## 3. Database Constraints & Migrations

Because the system uses `stancl/tenancy`, you must run migrations for **both** the central database and all tenant databases.

```bash
# Migrate the central database (Master Admin, Global settings)
php artisan migrate --force

# Migrate all Tenant databases (Device logs, Employee data)
php artisan tenants:migrate --force
```

**Important:** We enforce strict Database composite unique indexes on `(pin, punched_at)` in the `attendance_logs` table to prevent race-condition data loss. Always ensure tenant migrations run successfully.

### Production Optimizations (Required)
Always run the following commands after deploying to optimize the framework and Filament's assets:
```bash
php artisan optimize
php artisan filament:optimize
```

## 4. Hardware Optimization (2 Core / 4 Core VPS)

If hosting around **50 Tenants / 200 Users per Tenant (10,000 Total Users)**:
* A **2-Core / 8GB RAM VPS** is sufficient.
* Configure PHP-FPM `pm.max_children = 50`.
* Ensure PostgreSQL `max_connections` is set to at least `200`.

If scaling past **100 Tenants**:
* Upgrade to a **4-Core / 16GB RAM VPS**.
* If the WhatsApp HTTP API (WAHA) container runs on the same server, monitor RAM closely. WAHA (specifically the Chromium browser engines used for WhatsApp Web) is RAM-heavy. Consider moving WAHA to a separate lightweight VM to prevent Out-Of-Memory (OOM) crashes affecting the attendance database.
