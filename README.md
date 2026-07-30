# Bio-Notifier

Bio-Notifier is a powerful, modern, multi-tenant middleware designed to seamlessly bridge the gap between physical biometric attendance hardware (eSSL / eBio Server) and real-time communication platforms. 

Built on Laravel and the Filament admin panel, it acts as a centralized notification engine that intercepts attendance punches and instantly alerts employees via WhatsApp. Furthermore, it aggregates this data to compile comprehensive attendance reports.

## 🚀 Key Features

### 💬 Real-Time WhatsApp Notifications (WAHA API)
- **eSSL / eBio Server Integration:** Captures real-time attendance webhooks pushed directly from eSSL and eBio Servers.
- **Instant Alerts:** Automatically triggers WhatsApp messages to employees the moment they punch in or punch out on the biometric device.
- **WAHA API Support:** Fully integrated with the WhatsApp HTTP API (WAHA) for stable, session-based messaging.

### 📊 Report Compilation & Analytics
- **Comprehensive Reporting Dashboard:** Database-driven, highly configurable reporting system.
- **Automated Compilations:** Generates attendance reports including working hours, present/absent statistics, and late marks.
- **Filament Integration:** Data visualizations and tables built natively into the beautiful Filament admin dashboard.

### 🏢 Multi-Tenancy Architecture (Subdomain Routing)
- Robust multi-tenant environment powered by `stancl/tenancy` ensuring complete PostgreSQL database isolation.
- **Automated Subdomain Provisioning:** Instantly spins up a new isolated domain (e.g., `company.bionotifier.com/admin`) upon company creation.
- **Zero Cross-Data Bleed:** Central Master Admin panel and Tenant Admin panels operate on completely decoupled routing networks.

### 🔌 Hardware Integration & Full SOAP Device Sync
- **eBio Server Webhooks (Fire & Forget):** Asynchronous queue-based architecture capable of handling thousands of simultaneous device pings in <10ms to prevent device lockups.
- **Biometric Pushing & Pulling:** Push employee details to devices natively.
- **Advanced Device Commands:** Supports deleting users from specific devices, setting employee expiration dates, and triggering remote device fetches/reboots via SOAP API integration.
- **Biometric Face & Fingerprint Uploads:** Support for extracting and syncing Base64 biometric templates (Face ID & Fingerprints) across mixed hardware.
- **ZKTeco / Hikvision / Matrix Support:** Direct fallback integration capabilities for pushing user data back to legacy hardware.

## 🛠️ Technology Stack
- **Framework:** [Laravel](https://laravel.com/) (PHP 8.2+)
- **Admin Interface:** [Filament v3/v4](https://filamentphp.com/)
- **Multi-Tenancy:** [Stancl/Tenancy](https://tenancyforlaravel.com/)
- **Database:** PostgreSQL (with schema isolation) / MySQL
- **WhatsApp API:** WAHA (WhatsApp HTTP API)

## ⚙️ Setup & Installation

For production deployments, please refer to the detailed [Deploy.md](./Deploy.md) guide included in this repository. 
For a complete architectural overview and implementation guidelines, refer to the [Guide.md](./Guide.md) file.

### Local Development (via Docker/Sail)

Docker support has been added to the project via Laravel Sail.

1. **Clone the repository:**
   ```bash
   git clone https://github.com/Af1ah/bio-notifier.git bio-notifier
   cd bio-notifier
   ```

2. **Install Composer Dependencies:**
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
   # Make sure to update WHATSAPP_API_KEY and WHATSAPP_INSTANCE_NAME
   ```

4. **Start the Environment & Run Migrations:**
   ```bash
   ./vendor/bin/sail up -d
   ./vendor/bin/sail artisan key:generate
   ./vendor/bin/sail artisan migrate
   ```

## 📝 License
This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
