<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    public function sendMessage(?string $number, string $message): bool
    {
        if (empty($number)) {
            return false;
        }

        $instanceName = config('services.whatsapp.instance_name', env('WHATSAPP_INSTANCE_NAME'));
        $apiKey = config('services.whatsapp.api_key', env('WHATSAPP_API_KEY'));
        
        if (!$instanceName || !$apiKey) {
            Log::warning('WhatsApp service is not configured properly.');
            return false;
        }

        // Clean number: remove any non-numeric characters (keep the country code, e.g., 91xxxxxxxxxx)
        $number = preg_replace('/\D/', '', $number);

        $url = "https://wa.ariise.cloud/api/sendText";

        try {
            $response = Http::withHeaders([
                'X-Api-Key' => $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($url, [
                'session' => $instanceName,
                'chatId' => $number . '@c.us',
                'text' => $message,
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('WhatsApp message failed: ' . $e->getMessage());
            return false;
        }
    }
}
