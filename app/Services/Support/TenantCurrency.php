<?php

namespace App\Services\Support;

final class TenantCurrency
{
    // These currencies use the application's existing two-decimal money scale.
    public const OPTIONS = [
        'INR' => 'INR — Indian rupee',
        'USD' => 'USD — US dollar',
        'EUR' => 'EUR — Euro',
        'GBP' => 'GBP — British pound',
        'AED' => 'AED — UAE dirham',
        'SAR' => 'SAR — Saudi riyal',
        'QAR' => 'QAR — Qatari riyal',
        'AUD' => 'AUD — Australian dollar',
        'CAD' => 'CAD — Canadian dollar',
        'SGD' => 'SGD — Singapore dollar',
        'MYR' => 'MYR — Malaysian ringgit',
        'CNY' => 'CNY — Chinese yuan',
        'CHF' => 'CHF — Swiss franc',
        'NZD' => 'NZD — New Zealand dollar',
        'ZAR' => 'ZAR — South African rand',
        'PKR' => 'PKR — Pakistani rupee',
        'BDT' => 'BDT — Bangladeshi taka',
        'NPR' => 'NPR — Nepalese rupee',
        'LKR' => 'LKR — Sri Lankan rupee',
        'HKD' => 'HKD — Hong Kong dollar',
    ];

    public static function get(): string
    {
        $currency = tenant()?->currency ?? 'INR';

        return isset(self::OPTIONS[$currency]) ? $currency : 'INR';
    }

    public static function format(?int $minor, ?string $currency = null): string
    {
        return ($currency ?? self::get()).' '.number_format(($minor ?? 0) / 100, 2);
    }
}
