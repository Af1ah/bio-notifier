<?php

namespace App\Filament\Tenant\Resources\EmployeeLeaves\Pages;

use App\Filament\Tenant\LeaveActionErrors;
use App\Filament\Tenant\Resources\EmployeeLeaves\EmployeeLeaveResource;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveRangeService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class ListEmployeeLeaves extends ListRecords
{
    protected static string $resource = EmployeeLeaveResource::class;

    #[Locked]
    public string $rangeSignature = '';

    #[Locked]
    public string $rangeRequestKey = '';

    public string $rangePreview = '';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make(), Action::make('requestRange')->label('Add leave range')->visible(fn () => (int) auth()->user()?->privilege === 14)->mountUsing(function (Schema $schema) {
            $this->rangeSignature = '';
            $this->rangePreview = '';
            $this->rangeRequestKey = (string) Str::uuid();
            $schema->fill();
        })->steps([
            Step::make('Leave dates')->schema([
                Select::make('user_id')->label('Employee')->options(fn () => User::employees()->pluck('name', 'id'))->searchable()->required(),
                Select::make('leave_type_id')->label('Leave category')->options(fn () => LeaveType::where('active', true)->pluck('name', 'id'))->searchable()->required(),
                DatePicker::make('starts_on')->required()->native(false), DatePicker::make('ends_on')->required()->native(false)->afterOrEqual('starts_on'),
                Select::make('duration')->options(['full' => 'Full day', 'half' => 'Half day'])->default('full')->required(), Select::make('state')->label('Status')->options(['pending' => 'Pending', 'approved' => 'Approved'])->default('pending')->required(), Textarea::make('notes')->maxLength(1000),
            ])->afterValidation(function (Step $component) {
                $result = LeaveActionErrors::run($component->getChildSchema(), fn () => app(LeaveRangeService::class)->preview($component->getChildSchema()->getState(), auth()->user()), 'starts_on');
                $this->rangeSignature = $result['signature'];
                $this->rangePreview = collect($result['rows'])->map(fn ($r) => $r['date'].' — '.$r['kind'].($r['kind'] === 'leave' ? ' — '.($r['paid_half_units'] / 2).' paid, '.($r['unpaid_half_units'] / 2).' unpaid days' : ''))->implode("\n");
            }),
            Step::make('Review coverage')->schema([Text::make(fn () => $this->rangePreview), Text::make('Holidays and rostered off days are skipped. Coverage is checked again when you save. Salary deductions are calculated in payroll.')]),
        ])->action(function (array $data, Schema $schema) {
            $result = LeaveActionErrors::run($schema, fn () => app(LeaveRangeService::class)->submit($data, $this->rangeRequestKey, $this->rangeSignature, auth()->user()), 'starts_on');
            Notification::make()->title(count($result['leave_ids']).' leave dates saved')->success()->send();
        })];
    }
}
