<?php

namespace App\Filament\Resources\TechnicianResource\RelationManagers;

use App\Models\TechnicianServiceRate;
use App\Models\TechnicianTraining;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * "Pelatihan & Sertifikat" (Karyawan Fase 4, 2026-10-31): pelatihan internal atau dari produsen film beserta masa berlaku dan berkas
 * sertifikat (disk privat).
 */
class TrainingsRelationManager extends RelationManager
{
    protected static string $relationship = 'trainings';

    protected static ?string $title = 'Pelatihan & Sertifikat';

    public function isReadOnly(): bool
    {
        return ! (auth()->user()?->isFullAccess() ?? false);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->label('Nama pelatihan/sertifikat')->required()->maxLength(160)->columnSpanFull(),
            Forms\Components\TextInput::make('provider')->label('Penyelenggara')->maxLength(160)->placeholder('mis. Ginnva internal / nama produsen'),
            Forms\Components\Select::make('service_type')
                ->label('Layanan terkait')
                ->options(TechnicianServiceRate::SERVICE_TYPE_LABELS)
                ->native(false)
                ->placeholder('Umum (mis. K3)'),
            Forms\Components\DatePicker::make('held_on')->label('Tanggal pelatihan')->required()->maxDate(today()),
            Forms\Components\DatePicker::make('valid_until')->label('Berlaku sampai')->helperText('Kosongkan bila tidak kedaluwarsa.')->afterOrEqual('held_on'),
            Forms\Components\FileUpload::make('certificate_path')
                ->label('Berkas sertifikat (opsional)')
                ->disk('local')
                ->visibility('private')
                ->directory('technician-certificates')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                ->maxSize(10240)
                ->columnSpanFull(),
            Forms\Components\Textarea::make('notes')->label('Catatan')->rows(2)->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Pelatihan')->wrap(),
                Tables\Columns\TextColumn::make('provider')->label('Penyelenggara')->placeholder('—'),
                Tables\Columns\TextColumn::make('service_type')
                    ->label('Layanan')
                    ->formatStateUsing(fn (?string $state) => $state ? (TechnicianServiceRate::SERVICE_TYPE_LABELS[$state] ?? $state) : 'Umum')
                    ->badge(),
                Tables\Columns\TextColumn::make('held_on')->label('Tanggal')->date('d M Y'),
                Tables\Columns\TextColumn::make('valid_until')
                    ->label('Berlaku sampai')
                    ->date('d M Y')
                    ->placeholder('Tidak kedaluwarsa')
                    ->badge()
                    ->color(fn (TechnicianTraining $record) => $record->isExpired() ? 'danger' : ($record->expiresSoon() ? 'warning' : 'gray')),
            ])
            ->defaultSort('held_on', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('Belum ada pelatihan tercatat');
    }
}
