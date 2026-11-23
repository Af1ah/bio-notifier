<?php

namespace App\Filament\Employee\Pages;

use App\Filament\Employee\Actions\RequestCorrectionAction;
use App\Models\AttendanceDay;
use App\Services\Attendance\AttendanceDayDisplay;
use App\Services\EmployeePortal\PortalAccess;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Validator;

class Attendance extends PortalPage implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.employee.pages.attendance';

    protected function getHeaderActions(): array
    {
        return [RequestCorrectionAction::make()];
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => AttendanceDay::where('user_id', PortalAccess::employee()->id)->whereNotIn('status', ['off', 'holiday'])->with('approvals'))
            ->columns([
                TextColumn::make('work_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('status')->label('Attendance')->formatStateUsing(fn (AttendanceDay $record) => AttendanceDayDisplay::forDay($record)['label'])
                    ->color(fn (AttendanceDay $record) => match (AttendanceDayDisplay::forDay($record)['status']) {
                        'present' => 'success', 'half_day' => 'warning', 'absent' => 'danger', default => 'gray'
                    }),
                TextColumn::make('worked')->label('Worked')->state(fn (AttendanceDay $record) => AttendanceDayDisplay::forDay($record)['clock'].'h'),
                TextColumn::make('approvals.state')->label('Review')->listWithLineBreaks()->visibleFrom('md'),
            ])->filters([
                Filter::make('date')->schema([
                    DatePicker::make('from')->label('From'),
                    DatePicker::make('to')->label('To')->afterOrEqual('from'),
                ])->query(function ($query, array $data) {
                    $data = Validator::make($data, ['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'.(filled($data['from'] ?? null) ? '|after_or_equal:from' : '')])->validate();

                    return $query->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('work_date', '>=', $date))
                        ->when($data['to'] ?? null, fn ($q, $date) => $q->whereDate('work_date', '<=', $date));
                }),
                SelectFilter::make('review_state')->label('Review status')->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'])
                    ->query(fn ($query, array $data) => $query->when($data['value'] ?? null, fn ($q, $value) => $q->whereHas('approvals', fn ($approval) => $approval->where('state', $value)))),
                SelectFilter::make('review_type')->label('Review type')->options(['attendance' => 'Attendance', 'overtime' => 'Overtime'])
                    ->query(fn ($query, array $data) => $query->when($data['value'] ?? null, fn ($q, $value) => $q->whereHas('approvals', fn ($approval) => $approval->where('type', $value)))),
            ])->recordActions([ActionGroup::make([RequestCorrectionAction::make()])->label('Actions')])
            ->defaultSort('work_date', 'desc')->defaultKeySort()->paginated([10, 25, 50])->defaultPaginationPageOption(10)->enforcePaginationPageOptions()
            ->emptyStateHeading('No attendance for these filters')->emptyStateDescription('Choose another date range or request a correction.');
    }
}
