<?php

namespace App\Filament\Employee\Pages;

use App\Models\User;
use App\Services\EmployeePortal\DepartmentReview;
use App\Services\EmployeePortal\PortalAccess;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;

abstract class DepartmentReviewPage extends PortalPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.employee.pages.department-review';

    public static function canAccess(): bool
    {
        $employee = auth('employee')->user();

        return $employee instanceof User && DepartmentReview::hasScope($employee);
    }

    public static function getNavigationGroup(): string
    {
        return __('department-review.navigation');
    }

    public function boot(): void
    {
        abort_unless(DepartmentReview::hasScope(PortalAccess::employee()), 403);
    }
}
