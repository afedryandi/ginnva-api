<?php

namespace App\Filament\Resources\TechnicianResource\RelationManagers;

use App\Models\TechnicianServiceRate;
use App\Models\TechnicianSkill;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * "Keterampilan" (Karyawan Fase 4, 2026-10-31): tingkat teknisi pada tiap layanan. Dipakai sebagai peringatan penugasan di Booking dan
 * pembinaan; bukan dasar komisi. Dinilai dan diubah hanya oleh akses penuh.
 */
class SkillsRelationManager extends RelationManager
{
    protected static string $relationship = 'skills';

    protected static ?string $title = 'Keterampilan per Layanan';

    public function isReadOnly(): bool
    {
        return ! (auth()->user()?->isFullAccess() ?? false);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('service_type')
                ->label('Layanan')
                ->options(TechnicianServiceRate::SERVICE_TYPE_LABELS)
                ->required()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('technician_id', $this->getOwnerRecord()->id))
                ->native(false),

            Forms\Components\Select::make('level')
                ->label('Tingkat')
                ->options(TechnicianSkill::LEVELS)
                ->required()
                ->native(false)
                ->helperText('Pemula = masih didampingi. Mandiri = boleh mengerjakan sendiri. Mahir = bisa melatih orang lain.'),

            Forms\Components\DatePicker::make('assessed_on')->label('Tanggal Dinilai')->required()->default(today())->maxDate(today()),

            Forms\Components\Textarea::make('notes')->label('Catatan penilaian')->rows(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('service_type')
            ->columns([
                Tables\Columns\TextColumn::make('service_type')
                    ->label('Layanan')
                    ->formatStateUsing(fn (string $state) => TechnicianServiceRate::SERVICE_TYPE_LABELS[$state] ?? $state)
                    ->badge(),
                Tables\Columns\TextColumn::make('level')
                    ->label('Tingkat')
                    ->formatStateUsing(fn (int $state) => TechnicianSkill::LEVELS[$state] ?? $state)
                    ->badge()
                    ->color(fn (int $state) => match ($state) {
                        TechnicianSkill::EXPERT => 'success',
                        TechnicianSkill::INDEPENDENT => 'info',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('assessed_on')->label('Dinilai')->date('d M Y'),
                Tables\Columns\TextColumn::make('assessor.name')->label('Oleh')->placeholder('—'),
                Tables\Columns\TextColumn::make('notes')->label('Catatan')->limit(40)->placeholder('—'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(fn (array $data) => $data + ['assessed_by' => auth()->id()]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(fn (array $data) => $data + ['assessed_by' => auth()->id()]),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('Keterampilan belum dinilai')
            ->emptyStateDescription('Tambah satu baris per layanan. Selama belum ada, teknisi ini tidak ikut diperiksa untuk peringatan penugasan.');
    }
}
