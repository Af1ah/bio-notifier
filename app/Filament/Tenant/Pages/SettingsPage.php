<?php

namespace App\Filament\Tenant\Pages;

use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Pages\Page;

abstract class SettingsPage extends Page
{
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    public static function canAccess(): bool
    {
        return tenancy()->initialized && (bool) auth('web')->user()?->has_login;
    }

    public function getSubNavigation(): array
    {
        return array_map(
            fn (string $page) => NavigationItem::make($page::getNavigationLabel())
                ->icon($page::getNavigationIcon())
                ->url($page::getUrl())
                ->isActiveWhen(fn (): bool => static::class === $page),
            [OrganisationSettings::class, Backups::class, Integrations::class],
        );
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }
}
