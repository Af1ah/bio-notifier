<?php

namespace App\Filament\Tenant\Resources\PortalPunches;

use App\Filament\Tenant\Resources\PortalPunches\Pages\ManagePortalPunches;
use App\Models\PortalPunch;
use App\Services\EmployeePortal\PunchApprovalService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PortalPunchResource extends Resource
{
    protected static ?string $model = PortalPunch::class;

    protected static ?string $navigationLabel = 'Manual punch approvals';

    protected static ?string $modelLabel = 'manual punch';

    protected static ?string $pluralModelLabel = 'Manual punch approvals';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-camera';

    protected static string|\UnitEnum|null $navigationGroup = 'Organisation Management';

    private static function details(): array
    {
        return [
            ViewEntry::make('photo')->label('Captured photo')->view('filament.infolists.punch-photo')->columnSpanFull(),
            TextEntry::make('user.name')->label('Employee'),
            TextEntry::make('kind')->label('Punch')->formatStateUsing(fn ($state) => $state === 'in' ? 'Check in' : 'Check out'),
            TextEntry::make('punched_at')->label('Recorded at')->dateTime('d M Y, h:i:s A'),
            TextEntry::make('evidence.location_name')->label('Work location'),
            TextEntry::make('evidence.distance_meters')->label('Distance from pin')->suffix(' m'),
            TextEntry::make('evidence.accuracy_meters')->label('GPS accuracy')->suffix(' m'),
            TextEntry::make('evidence.latitude')->label('Coordinates')->state(fn (PortalPunch $record) => $record->evidence['latitude'].', '.$record->evidence['longitude'])
                ->url(fn (PortalPunch $record) => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($record->evidence['latitude'].','.$record->evidence['longitude']))->openUrlInNewTab(),
            TextEntry::make('state')->label('Status'),
            TextEntry::make('reviewer.name')->label('Reviewed by')->placeholder('—'),
        ];
    }

    public static function table(Table $table): Table
    {
        $review = fn (bool $approve) => Action::make($approve ? 'approve' : 'reject')->label($approve ? 'Approve' : 'Reject')
            ->color($approve ? 'success' : 'danger')->icon($approve ? 'heroicon-o-check' : 'heroicon-o-x-mark')
            ->visible(fn (PortalPunch $record) => $record->state === 'pending')->modalWidth('3xl')->requiresConfirmation()
            ->schema([...self::details(), Textarea::make('review_note')->label('Reason')->maxLength(1000)->required(! $approve)])
            ->action(fn (PortalPunch $record, array $data) => app(PunchApprovalService::class)->decide($record, auth('web')->user(), $approve, $data['review_note'] ?? null))
            ->successNotificationTitle($approve ? 'Punch approved' : 'Punch rejected');

        return $table->modifyQueryUsing(fn ($query) => $query->with(['user', 'reviewer']))->columns([
            TextColumn::make('user.name')->label('Employee')->searchable(),
            TextColumn::make('kind')->label('Punch')->formatStateUsing(fn ($state) => $state === 'in' ? 'Check in' : 'Check out'),
            TextColumn::make('punched_at')->label('Recorded at')->dateTime('d M, h:i A')->sortable(),
            TextColumn::make('evidence.location_name')->label('Work location')->visibleFrom('md'),
            TextColumn::make('state')->badge()->color(fn ($state) => match ($state) {
                'approved' => 'success', 'rejected' => 'danger', default => 'warning'
            }),
        ])->filters([SelectFilter::make('state')->label('Status')->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'])->default('pending')])
            ->recordActions([ActionGroup::make([ViewAction::make()->schema([...self::details(), TextEntry::make('review_note')->label('Review note')->placeholder('—')])->modalWidth('3xl'), $review(true), $review(false)])])
            ->recordAction('view')->defaultSort('punched_at', 'desc')->defaultKeySort()
            ->paginated([10, 25, 50])->defaultPaginationPageOption(10)->enforcePaginationPageOptions();
    }

    public static function getPages(): array
    {
        return ['index' => ManagePortalPunches::route('/')];
    }
}
