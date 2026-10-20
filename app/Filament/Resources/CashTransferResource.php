<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CashTransferResource\Pages;
use App\Models\CashTransfer;
use App\Models\ChartOfAccount;
use App\Models\PaymentAccount;
use App\Models\Store;
use App\Services\CashTransferService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Pemindahan Kas (Keuangan Fase 1, 2026-11-05): pindahkan uang antar akun kas/bank, mis. setor tunai toko ke bank atau memindahkan saldo lama
 * akun 1101 ke rekening baru. Tidak diedit atau dihapus; salah catat dibatalkan dengan alasan (jurnal pembalik). Terbatas full-access dan
 * Spv Finance dengan izin.
 */
class CashTransferResource extends Resource
{
    protected static ?string $model = CashTransfer::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationGroup = 'Jurnal & Akun';

    protected static ?string $navigationLabel = 'Pemindahan Kas';

    protected static ?string $modelLabel = 'Pemindahan Kas';

    protected static ?string $pluralModelLabel = 'Pemindahan Kas';

    protected static ?int $navigationSort = 206;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || ($user?->canAccessStaffArea() && $user->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'view', false));
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'create', false));
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /** @return array<int, string> id akun kas => "1101 — Kas di Tangan (nama rekening)" */
    private static function cashOptions(): array
    {
        $paymentNames = PaymentAccount::pluck('name', 'chart_of_account_id');

        return ChartOfAccount::where('is_cash', true)->where('is_postable', true)->where('is_active', true)->orderBy('code')->get()
            ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name . (isset($paymentNames[$a->id]) ? " ({$paymentNames[$a->id]})" : '')])
            ->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\Select::make('from_account_id')->label('Dari')->options(fn () => static::cashOptions())->required()->searchable()->different('to_account_id'),
                Forms\Components\Select::make('to_account_id')->label('Ke')->options(fn () => static::cashOptions())->required()->searchable()->different('from_account_id'),
                Forms\Components\TextInput::make('amount')->label('Nominal')->numeric()->required()->minValue(0.01)->maxValue(99999999999.99)->prefix('Rp'),
                Forms\Components\DatePicker::make('transfer_date')->label('Tanggal')->required()->default(today())->maxDate(today())->native(false),
                Forms\Components\Select::make('store_id')->label('Toko (opsional)')->options(fn () => Store::where('is_active', true)->orderBy('name')->pluck('name', 'id'))->searchable()
                    ->placeholder('Tidak terkait toko tertentu'),
                Forms\Components\Textarea::make('notes')->label('Catatan')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('transfer_number')->label('No.')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('transfer_date')->label('Tanggal')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('fromAccount.display_name')->label('Dari'),
                Tables\Columns\TextColumn::make('toAccount.display_name')->label('Ke'),
                Tables\Columns\TextColumn::make('amount')->label('Nominal')->money('IDR', locale: 'id'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (CashTransfer $record) => $record->isVoided() ? 'Dibatalkan' : 'Aktif')
                    ->color(fn (string $state) => $state === 'Aktif' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('notes')->label('Catatan')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->defaultSort('transfer_date', 'desc')
            ->actions([
                Tables\Actions\Action::make('void')
                    ->label('Batalkan')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (CashTransfer $record) => ! $record->isVoided() && static::canCreate())
                    ->form([Forms\Components\Textarea::make('reason')->label('Alasan pembatalan')->required()->rows(2)->maxLength(500)])
                    ->requiresConfirmation()
                    ->action(function (CashTransfer $record, array $data) {
                        try {
                            app(CashTransferService::class)->void($record, auth()->id(), $data['reason']);
                            Notification::make()->title('Pemindahan dibatalkan')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Tidak bisa dibatalkan')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['fromAccount', 'toAccount']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCashTransfers::route('/'),
            'create' => Pages\CreateCashTransfer::route('/create'),
        ];
    }
}
