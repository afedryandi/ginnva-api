<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChecklistTemplateResource\Pages;
use App\Models\ChecklistTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Template Checklist Karyawan (Karyawan Fase 3, 2026-10-31): butir standar onboarding dan offboarding yang disalin ke checklist tiap
 * karyawan saat dimulai.
 */
class ChecklistTemplateResource extends Resource
{
    protected static ?string $model = ChecklistTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Kebijakan & Checklist';

    protected static ?string $navigationLabel = 'Template Checklist';

    protected static ?string $modelLabel = 'Butir Checklist';

    protected static ?string $pluralModelLabel = 'Template Checklist Karyawan';

    protected static ?int $navigationSort = 340;

    public static function canViewAny(): bool
    {
        if (! config('features.employee_checklists')) {
            return false;
        }

        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'create', true) ?? false);
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'update', true) ?? false);
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'delete', true) ?? false);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('kind')->label('Jenis')->options(ChecklistTemplate::KINDS)->required()->native(false),
                    Forms\Components\TextInput::make('sort_order')->label('Urutan')->numeric()->integer()->default(100)->minValue(0),
                    Forms\Components\TextInput::make('title')->label('Butir')->required()->maxLength(200)->columnSpanFull(),
                    Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('kind')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ChecklistTemplate::KINDS[$state] ?? $state),
                Tables\Columns\TextColumn::make('title')->label('Butir')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('sort_order')->label('Urutan'),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('kind')->label('Jenis')->options(ChecklistTemplate::KINDS),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChecklistTemplates::route('/'),
            'create' => Pages\CreateChecklistTemplate::route('/create'),
            'edit' => Pages\EditChecklistTemplate::route('/{record}/edit'),
        ];
    }
}
