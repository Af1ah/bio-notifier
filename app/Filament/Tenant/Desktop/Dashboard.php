<?php

namespace App\Filament\Tenant\Desktop;

use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Widgets\WidgetConfiguration;

class Dashboard extends BaseDashboard
{
    #[Unexposed]
    public function getWidgets(): array
    {
        return array_map(static function ($widget): WidgetConfiguration {
            if ($widget instanceof WidgetConfiguration) {
                return $widget->widget::make([...$widget->getProperties(), 'lazy' => false]);
            }

            return $widget::make(['lazy' => false]);
        }, parent::getWidgets());
    }
}
