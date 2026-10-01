# Production Deployment Guide

Since we have merged everything into a single, unified application, deploying to a fresh production instance (like a VPS or Laravel Forge) is now incredibly simple. 

You no longer need to worry about custom packages, symlinks, or private repositories. Your entire app lives in one place on GitHub: `https://github.com/Af1ah/bio-notifier`.

## Prerequisites

On your fresh production server (e.g. Ubuntu 22.04/24.04), install the required dependencies one by one:

**1. Update system packages:**
```bash
sudo apt update && sudo apt upgrade -y
```

**2. Install Web Server (Nginx):**
```bash
sudo apt install nginx -y
```

**3. Install Database (PostgreSQL or MySQL):**
```bash
# For PostgreSQL
sudo apt install postgresql postgresql-contrib -y

# OR for MySQL/MariaDB
sudo apt install mariadb-server -y
```

**4. Install PHP and Required Extensions (adjust version 8.2+ as needed):**
```bash
sudo apt install php8.2-fpm php8.2-cli php8.2-pgsql php8.2-mysql php8.2-mbstring php8.2-xml php8.2-bcmath php8.2-curl php8.2-zip unzip -y
```

**5. Install Composer:**
```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

**6. Install Supervisor (for Background Queues):**
```bash
sudo apt install supervisor -y
```

## Step-by-Step Deployment

### 1. Clone the Repository
SSH into your production server and navigate to your web directory (e.g. `/var/www/html`), then clone your repository:
```bash
git clone https://github.com/Af1ah/bio-notifier.git .
```

### 2. Install Dependencies
Install all required PHP packages optimized for production:
```bash
composer install --optimize-autoloader --no-dev
```

### 3. Environment Configuration
Copy the example environment file and generate your application key:
```bash
cp .env.example .env
php artisan key:generate
```

Now, open the `.env` file using a text editor like `nano`:
```bash
nano .env
```
Update your database credentials to match your production MySQL database:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_production_db_name
DB_USERNAME=your_production_db_user
DB_PASSWORD=your_production_db_password
```

> [!IMPORTANT]
> Make sure you change `APP_ENV=local` to `APP_ENV=production` and `APP_DEBUG=true` to `APP_DEBUG=false` in your `.env` file!

### 4. Run Migrations & Setup Database
Because this is a multi-tenant system, you must run migrations for BOTH the central database (Master Admin) and the tenant databases.

1. **Migrate the central database:**
```bash
php artisan migrate --force
```

2. **Migrate all tenant databases:**
```bash
php artisan tenants:migrate --force
```

3. **Create your initial Master Admin user:**
```bash
php artisan make:filament-user
```

### 5. Optimize Caches
To ensure your production application runs as fast as possible, cache your configurations, routes, and views:
```bash
php artisan optimize
php artisan filament:optimize
```

### 6. Storage Link & Permissions
Ensure Nginx/Apache has permission to read and write to the storage folders, and link the public storage directory:
```bash
php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache
```

### 7. Configure Supervisor for Queue Workers

Bio-Notifier uses the connection's default queue for webhook, application, and attendance recalculation jobs. Run `php artisan queue:work` from the application directory in development; no attendance-specific worker is needed. Restart any existing worker after deploying this change.

Jobs already queued on the old `attendance` queue retain their queue name. During an upgrade, drain those jobs once with `php artisan queue:work --queue=default,attendance --stop-when-empty`, then use the normal worker below. Invalid reversed-date calculations are rejected, not executed. The scheduler still requires its separate cron entry.

1. Create a new configuration file:
```bash
sudo nano /etc/supervisor/conf.d/bio-notifier-worker.conf
```

2. Add the following configuration (replace `/var/www/html` with your exact project path, e.g. `/var/www/bio-notifier`):
```ini
[program:bio-notifier-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work --sleep=3 --tries=3 --timeout=60 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/worker.log
stopwaitsecs=3600
```

3. Read the new configuration and start the worker:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start bio-notifier-worker:*
```

After each deployment, restart long-running workers so they load the new application code:

```bash
php artisan queue:restart
sudo supervisorctl status bio-notifier-worker:*
```

When deploying authentication changes, run `php artisan optimize:clear` and
`npm ci && npm run build`, and reload PHP-FPM (or restart `php artisan serve`
in development). Deploy the generated `public/sw.js` with the PHP changes.
Tenant session and remember cookies are now scoped to each organisation;
existing tenant logins require one fresh login after this update. The master
panel keeps its own session. Authenticated HTML is fetched from the server,
never restored from the service-worker page cache; the new worker removes the
old `pages` cache on activation. Do not disable CSRF protection or extend the
session lifetime to work around expired-page errors.

The worker log is written to `storage/logs/worker.log`. Investigate failed jobs before retrying them:

```bash
php artisan queue:failed
php artisan queue:retry <job-uuid>
```

### 8. Configure the Laravel Scheduler

Attendance for the previous workday is queued automatically every day at midnight in the configured attendance timezone. The application schedule does not run by itself; production needs one system cron entry.

Open the web-server user's crontab:

```bash
sudo crontab -u www-data -e
```

Add this line, replacing `/var/www/html` with the deployed project path:

```cron
* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1
```

Confirm that Laravel sees the nightly task:

```bash
php artisan schedule:list
```

The output should contain `attendance:recalculate-nightly` at `00:00` in the configured attendance timezone. The command calculates yesterday, not the newly started day, so employees have had time to complete their final punch.

To verify dispatch safely for one tenant and date:

```bash
php artisan attendance:recalculate-nightly --tenant=TENANT_SHORTNAME --date=2026-09-30
php artisan queue:work --stop-when-empty
```

Monitor the result under **Organisation Management → Recalculation runs**. A successful run must reach `completed = total` with `failed = 0`.

Manual historical backfills remain intentionally bounded to 31 days per command. Split longer periods into non-overlapping ranges:

```bash
php artisan attendance:recalculate TENANT_SHORTNAME 2026-08-01 2026-08-31
php artisan attendance:recalculate TENANT_SHORTNAME 2026-09-01 2026-09-30
```

Before a historical backfill, confirm that the applicable shift rule and assignment were effective throughout the requested dates. Otherwise, those days will correctly calculate as `No shift`.

### 9. Web Server Configuration (Nginx & Multi-Tenancy)

Bio-Notifier uses an isolated domain-based routing system for tenants. To allow clients to have their own domains (like `client1.noti.ariise.cloud`) without breaking other apps on your server, you need to set up a wildcard properly in Nginx.

**DNS Configuration in your Registrar:**
1. Point an A-record for your base domain (e.g. `noti.ariise.cloud`) to your server IP.
2. Point a Wildcard A-record (e.g. `*.noti.ariise.cloud`) to your server IP.

**Nginx Setup:**
1. Create a new Nginx server block configuration:
```bash
sudo nano /etc/nginx/sites-available/bio-notifier
```

2. Add the following standard Nginx setup. Ensure you explicitly list the wildcard in `server_name` so Nginx routes all tenant traffic here!

```nginx
server {
    listen 80;
    listen [::]:80;

    # Explicitly catch the master domain AND all subdomains
    server_name noti.ariise.cloud *.noti.ariise.cloud;
    
    root /var/www/html/public; # IMPORTANT: This MUST point to the /public directory!

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock; # Ensure PHP version matches what you installed
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

3. Enable the site and restart Nginx:
```bash
sudo ln -s /etc/nginx/sites-available/bio-notifier /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
```

### 10. Environment Variables (.env)

Make sure your `.env` contains the correct routing information so the system knows how to build tenant URLs correctly.

```env
APP_URL=https://noti.ariise.cloud
CENTRAL_DOMAIN=noti.ariise.cloud
ATTENDANCE_TIMEZONE=Asia/Kolkata
ATTENDANCE_NIGHTLY_TIME=00:00
```
*Note: Setting `CENTRAL_DOMAIN` guarantees that when you create a tenant named "client1", their URL becomes `client1.noti.ariise.cloud` perfectly without stacking extra domains.*

`ATTENDANCE_TIMEZONE` controls which local midnight starts the nightly calculation. `ATTENDANCE_NIGHTLY_TIME` uses 24-hour `HH:MM` format. After changing either value, refresh cached configuration and restart workers:

```bash
php artisan optimize
php artisan queue:restart
```

### Attendance Deployment Verification

Run these checks after deploying attendance changes:

```bash
php artisan schedule:list
sudo supervisorctl status bio-notifier-worker:*
php artisan queue:failed
```

Then confirm in the tenant panel:

1. New raw punches appear under **Attendance Logs**.
2. The following morning's **Recalculation run** is completed without failures.
3. Completed IN/OUT days appear in **Reports** without manual approval.
4. Only genuine missing-checkout, correction, or qualifying overtime exceptions appear under **Attendance approvals**.
5. Payroll generation remains blocked if attendance is stale or approvals are pending.

## Configuring the Attendance Devices

Once your application is live on your domain (e.g. `https://zkteco.ariise.cloud`), you need to configure your physical ZKTeco attendance devices.

On the device menu, navigate to **Cloud Server Settings** or **ADMS Settings** and enter:
- **Server Address:** `zkteco.ariise.cloud`
- **Server Port:** `443` (if using HTTPS) or `80`
- **Server URL / Domain:** `http://zkteco.ariise.cloud` (or just `zkteco.ariise.cloud` if the device asks for Server Address)

> [!WARNING]
> Do **not** add `/api` to the end of the URL! We recently updated the architecture to handle biometric requests directly on the root domain (e.g., `/iclock/cdata`). If your device firmware asks for a "Server Address", simply enter your domain without `http://` or `/iclock`.

## Docker Support (Local & Development)

Docker support has been added to the project via Laravel Sail. This makes it incredibly easy to spin up the application without installing PHP or PostgreSQL directly on your local machine.

### Prerequisites for Docker
- Docker Engine
- Docker Compose

### Getting Started with Docker

1. **Clone the repository:**
```bash
git clone https://github.com/Af1ah/bio-notifier.git
cd bio-notifier
```

2. **Install Composer Dependencies (using a small Docker container):**
```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php82-composer:latest \
    composer install --ignore-platform-reqs
```

3. **Configure Environment:**
```bash
cp .env.example .env
```
Make sure your `.env` contains the Sail DB settings (e.g. `DB_HOST=pgsql`).

4. **Start the Docker Containers:**
```bash
./vendor/bin/sail up -d
```

5. **Run Migrations & Generate Key:**
```bash
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
```

Your application will now be accessible at `http://localhost`. To stop the containers, simply run:
```bash
./vendor/bin/sail down
```
