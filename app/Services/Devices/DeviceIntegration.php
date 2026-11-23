<?php

namespace App\Services\Devices;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class DeviceIntegration
{
    public const GATEWAY = 'adms_gateway';

    public const EBIO = 'ebio';

    public const DESKTOP = 'desktop_connector';

    public static function authorizeManagement(): void
    {
        // Background ingestion and queued device work have no signed-in operator.
        $actor = auth('web')->user();
        abort_if($actor instanceof User && ! $actor->canManageDevices(), 403);
    }

    public static function options(): array
    {
        return [self::GATEWAY => 'ADMS gateway', self::EBIO => 'eBioServer', self::DESKTOP => 'Desktop connector'];
    }

    public static function usesEbio(?Organisation $organisation = null): bool
    {
        $organisation ??= tenant();

        return $organisation instanceof Organisation
            && ($organisation->device_integration ?? self::EBIO) === self::EBIO;
    }

    public static function requireEbio(Organisation $organisation): void
    {
        // Queue payloads may have been created before the company changed provider.
        $current = $organisation->exists ? $organisation->fresh() : $organisation;

        if (! $current || ! self::usesEbio($current)) {
            throw new AuthorizationException('This device action is unavailable for this company.');
        }
    }
}
