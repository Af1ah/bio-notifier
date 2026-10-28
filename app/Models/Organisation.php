<?php

namespace App\Models;

use App\Services\Devices\DeviceIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Organisation extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    protected $table = 'organisations';

    protected $fillable = [
        'name',
        'shortname',
        'db_name',
        'email',
        'phone',
        'logo',
        'brand_color',
        'status',
        'device_integration',
        'gateway_source_cidr',
        'timezone',
        'ebio_url',
        'ebio_webhook_token',
        'ebio_aes_password',
        'ebio_soap_username',
        'ebio_soap_password',
    ];

    protected $casts = [
        'ebio_aes_password' => 'encrypted',
        'ebio_soap_username' => 'encrypted',
        'ebio_soap_password' => 'encrypted',
    ];

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'shortname',
            'db_name',
            'email',
            'phone',
            'logo',
            'brand_color',
            'status',
            'device_integration',
            'gateway_source_cidr',
            'ebio_url',
            'ebio_webhook_token',
            'ebio_aes_password',
            'ebio_soap_username',
            'ebio_soap_password',
        ];
    }

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    public function departments()
    {
        return $this->hasMany(Department::class);
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where('shortname', $value)->orWhere('id', $value)->firstOrFail();
    }

    protected static function booted()
    {
        static::updating(function ($tenant) {
            // Virtual-column event decoding resets originals before this event. Compare persisted settings.
            $previous = $tenant->fresh()?->timezone ?: config('attendance.timezone', 'Asia/Kolkata');
            $next = $tenant->timezone ?: config('attendance.timezone', 'Asia/Kolkata');
            if ($previous === $next) {
                return;
            }
            // Legacy attendance columns contain local wall times, not UTC instants.
            // A normal settings update must never reinterpret an existing payroll calendar.
            $hasHistory = $tenant->run(function () {
                foreach (['attendance_logs', 'attendance_days', 'manual_attendance_entries'] as $table) {
                    if (Schema::hasTable($table) && DB::table($table)->exists()) {
                        return true;
                    }
                }

                return false;
            });
            if ($hasHistory) {
                throw ValidationException::withMessages(['timezone' => 'Attendance already exists. Changing its timezone requires a reviewed history migration.']);
            }
        });
        static::creating(function ($tenant) {
            $tenant->device_integration ??= DeviceIntegration::GATEWAY;
            $tenant->ebio_webhook_token = bin2hex(random_bytes(32));
        });
        static::created(function ($tenant) {
            // Extract central domain from CENTRAL_DOMAIN env, fallback to APP_URL host
            $centralDomain = env('CENTRAL_DOMAIN');
            if (empty($centralDomain)) {
                $centralDomain = parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST);
            }

            $tenant->domains()->create([
                'domain' => $tenant->shortname.'.'.ltrim($centralDomain, '.'),
            ]);

            // Also create a localhost domain for easy local testing
            if ($centralDomain !== 'localhost') {
                $tenant->domains()->create([
                    'domain' => $tenant->shortname.'.localhost',
                ]);
            }
        });
    }
}
