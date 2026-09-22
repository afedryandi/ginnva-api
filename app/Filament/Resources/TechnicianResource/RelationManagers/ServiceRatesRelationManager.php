<?php

namespace App\Filament\Resources\TechnicianResource\RelationManagers;

use App\Models\TechnicianServiceRate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * "Tarif per Layanan" (audit Majoo, f34: "Tarif berbeda per teknisi
 * untuk layanan yang sama"), dibangun 2026-09-22 atas keputusan user.
 * Begitu teknisi punya MINIMAL 1 baris di sini, seluruh perhitungan
 * komisinya beralih dari "Komisi per Pekerjaan" flat ke mode
 * per-layanan -- lihat Technician::commissionForBooking().
 */
class ServiceRatesRelationManager extends RelationManager
{
    protected static string $relationship = 'serviceRates';

    protected static ?string $title = 'Tarif per Layanan';

    public function isReadOnly(): bool
    {
        return ! (auth()->user()?->isFullAccess() ?? false);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('service_type')
                ->label('Jenis Layanan')
                ->options(TechnicianServiceRate::SERVICE_TYPE_LABELS)
                ->required()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('technician_id', $this->getOwnerRecord()->id))
                ->native(false),

            Forms\Components\TextInput::make('commission_amount')
                ->label('Komisi per Pekerjaan (Rp)')
                ->numeric()
                ->minValue(0)
                ->prefix('Rp')
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('service_type')
            ->columns([
                Tables\Columns\TextColumn::make('service_type')
                    ->label('Jenis Layanan')
                    ->formatStateUsing(fn (string $state) => TechnicianServiceRate::SERVICE_TYPE_LABELS[$state] ?? $state)
                    ->badge(),

                Tables\Columns\TextColumn::make('commission_amount')
                    ->label('Komisi per Pekerjaan')
                    ->money('IDR'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('Belum ada tarif per layanan')
            ->emptyStateDescription('Teknisi ini masih pakai "Komisi per Pekerjaan" flat di atas. Tambah baris di sini untuk beralih ke tarif berbeda per jenis layanan (PPF/Kaca Film/Detailing/Premium Wash) -- booking dengan beberapa layanan sekaligus akan menjumlahkan tarifnya.');
    }
}
