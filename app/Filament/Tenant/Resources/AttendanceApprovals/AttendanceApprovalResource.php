<?php

namespace App\Filament\Tenant\Resources\AttendanceApprovals;

use App\Filament\Tenant\Resources\AttendanceApprovals\Pages\ListAttendanceApprovals;
use App\Filament\Tenant\Resources\AttendanceApprovals\Schemas\AttendanceApprovalForm;
use App\Filament\Tenant\Resources\AttendanceApprovals\Tables\AttendanceApprovalsTable;
use App\Models\AttendanceApproval;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AttendanceApprovalResource extends Resource
{
    protected static ?string $model = AttendanceApproval::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static \UnitEnum|string|null $navigationGroup = 'Organisation Management';

    protected static ?string $navigationLabel = 'Attendance approvals';

    protected static ?string $pluralModelLabel = 'Attendance approvals';

    public static function form(Schema $schema): Schema
    {
        return AttendanceApprovalForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttendanceApprovalsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttendanceApprovals::route('/'),
        ];
    }
}
