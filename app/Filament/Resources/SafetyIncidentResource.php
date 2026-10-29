<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\HasKaryawanAccess;
use App\Filament\Resources\SafetyIncidentResource\Pages;
use App\Models\SafetyIncident;
use App\Models\Store;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Kejadian K3 (Karyawan Fase 5, 2026-11-02): catat kejadian, penyebab, dan tindak lanjut, lalu tutup. Kejadian tidak bisa dihapus (jejak
 * keselamatan harus utuh). Kejadian cedera yang belum ditutup ditandai di Dasbor HR.
 */
class SafetyIncidentResource extends Resource
{
    use HasKaryawanAccess {
        canDelete as private traitCanDelete;
    }

    protected static function requiredFeature(): ?string
    {
        return 'k3';
    }

    protected static ?string $model = SafetyIncident::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'K3 & Kinerja';

    protected static ?string $navigationLabel = 'Kejadian K3';

    protected static ?string $modelLabel = 'Kejadian K3';

    protected static ?string $pluralModelLabel = 'Kejadian K3';

    protected static ?int $navigationSort = 540;

    /** Jejak keselamatan tidak dihapus; koreksi lewat ubah/tutup. */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return static::limitToOwnStore(parent::getEloquentQuery()->with(['store:id,name', 'user:id,name']));
    }

    public static function form(Form $form): Form
    {
        $isFull = auth()->user()?->isFullAccess() ?? false;

        return $form->schema([
            Forms\Components\Section::make('Kejadian')->columns(2)->schema([
                Forms\Components\Select::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->default(fn () => auth()->user()?->store_id)
                    ->disabled(! $isFull)
                    ->dehydrated()
                    ->required(),
                Forms\Components\DateTimePicker::make('occurred_at')->label('Waktu kejadian')->required()->default(now())->maxDate(now()),
                Forms\Components\Select::make('type')->label('Jenis')->options(SafetyIncident::TYPES)->required()->native(false),
                Forms\Components\Select::make('severity')->label('Tingkat')->options(SafetyIncident::SEVERITIES)->required()->native(false),
                Forms\Components\Select::make('user_id')->label('Karyawan terlibat/terdampak')->options(fn () => static::employeeOptions())->searchable()
                    ->placeholder('Tidak ada/bukan karyawan')->columnSpanFull(),
                Forms\Components\Textarea::make('description')->label('Apa yang terjadi')->required()->rows(3)->columnSpanFull(),
                Forms\Components\Textarea::make('immediate_action')->label('Tindakan saat itu')->rows(2)->columnSpanFull(),
            ]),
            Forms\Components\Section::make('Penyebab & tindak lanjut')->columns(1)->schema([
                Forms\Components\Textarea::make('root_cause')->label('Penyebab')->rows(2),
                Forms\Components\Textarea::make('follow_up')->label('Tindak lanjut/pencegahan')->rows(2)
                    ->helperText('Wajib diisi sebelum kejadian bisa ditutup. Untuk kejadian cedera, cek kewajiban pelaporan ke BPJS Ketenagakerjaan.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')->label('Waktu')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('store.name')->label('Toko')->toggleable(),
                Tables\Columns\TextColumn::make('type')->label('Jenis')->badge()
                    ->formatStateUsing(fn (string $state) => SafetyIncident::TYPES[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'injury' ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('severity')->label('Tingkat')->badge()
                    ->formatStateUsing(fn (string $state) => SafetyIncident::SEVERITIES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { 'berat' => 'danger', 'sedang' => 'warning', default => 'gray' }),
                Tables\Columns\TextColumn::make('user.name')->label('Karyawan')->placeholder('—'),
                Tables\Columns\TextColumn::make('description')->label('Kejadian')->limit(60)->wrap(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'open' ? 'Terbuka' : 'Ditutup')
                    ->color(fn (string $state) => $state === 'open' ? 'warning' : 'success'),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Status')->options(['open' => 'Terbuka', 'closed' => 'Ditutup']),
                Tables\Filters\SelectFilter::make('type')->label('Jenis')->options(SafetyIncident::TYPES),
            ])
            ->actions([
                Tables\Actions\Action::make('close')
                    ->label('Tutup')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (SafetyIncident $record) => $record->isOpen() && static::canEdit($record))
                    ->requiresConfirmation()
                    ->modalDescription('Pastikan penyebab dan tindak lanjut sudah terisi.')
                    ->action(function (SafetyIncident $record) {
                        try {
                            $record->close();
                            Notification::make()->title('Kejadian ditutup')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Belum bisa ditutup')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSafetyIncidents::route('/'),
            'create' => Pages\CreateSafetyIncident::route('/create'),
            'edit' => Pages\EditSafetyIncident::route('/{record}/edit'),
        ];
    }
}
