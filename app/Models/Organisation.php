<?php

namespace App\Models;

use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;

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
}
