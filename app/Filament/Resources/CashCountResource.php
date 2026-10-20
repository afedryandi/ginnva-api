<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CashCountResource\Pages;
use App\Models\CashCount;
use App\Models\PaymentAccount;
use App\Services\CashCountService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Hitung Kas Harian (Keuangan Fase 4, 2026-11-07): hitung uang fisik di laci toko dan bandingkan dengan saldo sistem pada hari itu. Hitungan
 * tidak diedit atau dihapus. Selisih dijelaskan di catatan, dan hanya akses penuh yang menjurnalkannya (kekurangan ke 7900, kelebihan ke 7200).
 */
class CashCountResource extends Resource
{
    protected static ?string $model = CashCount::class;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationGroup = 'Jurnal & Akun';

    protected static ?string $navigationLabel = 'Hitung Kas Harian';

    protected static ?string $modelLabel = 'Hitung Kas';

    protected static ?string $pluralModelLabel = 'Hitung Kas Harian';

    protected static ?int $navigationSort = 207;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || ($user?->canAccessStaffArea() && $user->hasMenuAccess(static::class));
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['paymentAccount', 'counter']);
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        $user = auth()->user();

        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\Select::make('payment_account_id')
                    ->label('Kas yang dihitung')
                    ->options(fn () => PaymentAccount::where('is_active', true)->whereIn('kind', ['kas', 'kas_kecil'])
                        ->when($user && ! $user->isFullAccess(), fn ($q) => $q->where('store_id', $user->store_id))
                        ->orderBy('name')->pluck('name', 'id'))
                    ->required()->searchable(),
                Forms\Components\DatePicker::make('count_date')->label('Tanggal hitung')->required()->default(today())->maxDate(today())->native(false)
                    ->helperText('Dibandingkan dengan saldo sistem sampai akhir tanggal ini.'),
                Forms\Components\TextInput::make('counted_amount')->label('Uang yang dihitung')->numeric()->required()->minValue(0)->prefix('Rp'),
                Forms\Components\Textarea::make('notes')->label('Catatan')->rows(2)->columnSpanFull()
                    ->helperText('Wajib bila ada selisih dengan saldo sistem: jelaskan kemungkinan penyebabnya.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('count_date')->label('Tanggal')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('paymentAccount.name')->label('Kas'),
                Tables\Columns\TextColumn::make('counted_amount')->label('Dihitung')->money('IDR', locale: 'id'),
                Tables\Columns\TextColumn::make('system_balance')->label('Saldo sistem')->money('IDR', locale: 'id'),
                Tables\Columns\TextColumn::make('difference')->label('Selisih')->money('IDR', locale: 'id')->badge()
                    ->color(fn (CashCount $record) => ! $record->hasDifference() ? 'success' : ((float) $record->difference < 0 ? 'danger' : 'warning')),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (CashCount $record) => ! $record->hasDifference() ? 'Cocok' : ($record->isAdjusted() ? 'Dijurnalkan' : 'Belum dijurnalkan'))
                    ->color(fn (string $state) => match ($state) { 'Cocok' => 'success', 'Dijurnalkan' => 'gray', default => 'warning' }),
                Tables\Columns\TextColumn::make('counter.name')->label('Penghitung')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('notes')->label('Catatan')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->defaultSort('count_date', 'desc')
            ->filters([
                Tables\Filters\Filter::make('with_difference')->label('Ada selisih')->query(fn (Builder $query) => $query->where('difference', '!=', 0)),
                Tables\Filters\Filter::make('unadjusted')->label('Selisih belum dijurnalkan')->query(fn (Builder $query) => $query->where('difference', '!=', 0)->whereNull('adjusted_at')),
            ])
            ->actions([
                Tables\Actions\Action::make('adjust')
                    ->label('Jurnal selisih')
                    ->icon('heroicon-o-document-check')
                    ->color('warning')
                    ->visible(fn (CashCount $record) => $record->hasDifference() && ! $record->isAdjusted() && (auth()->user()?->isFullAccess() ?? false))
                    ->requiresConfirmation()
                    ->modalDescription(fn (CashCount $record) => ((float) $record->difference < 0 ? 'Kekurangan' : 'Kelebihan') . ' Rp' . number_format(abs((float) $record->difference), 0, ',', '.') . ' akan dijurnalkan. Pastikan sudah diselidiki.')
                    ->action(function (CashCount $record) {
                        try {
                            app(CashCountService::class)->adjust($record, auth()->id());
                            Notification::make()->title('Selisih dijurnalkan')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Tidak bisa dijurnalkan')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCashCounts::route('/'),
            'create' => Pages\CreateCashCount::route('/create'),
        ];
    }
}
