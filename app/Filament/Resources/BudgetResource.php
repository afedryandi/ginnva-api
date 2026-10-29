<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BudgetResource\Pages;
use App\Models\Budget;
use App\Models\ChartOfAccount;
use App\Models\Store;
use App\Services\BudgetService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Anggaran (Keuangan Fase 4, 2026-11-07): anggaran per bulan per akun beban, untuk seluruh perusahaan atau satu toko. Dibandingkan dengan
 * realisasi di laporan Anggaran vs Realisasi; hanya peringatan, tidak memblokir transaksi. Terbatas full-access dan Spv Finance dengan izin.
 */
class BudgetResource extends Resource
{
    protected static ?string $model = Budget::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationGroup = 'Jurnal & Akun';

    protected static ?string $navigationLabel = 'Anggaran';

    protected static ?string $modelLabel = 'Anggaran';

    protected static ?string $pluralModelLabel = 'Anggaran';

    protected static ?int $navigationSort = 208;

    public static function canViewAny(): bool
    {
        if (! config('features.finance')) {
            return false;
        }

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
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'update', false));
    }

    public static function canDelete($record): bool
    {
        return static::canEdit($record);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\DatePicker::make('period_month')->label('Bulan')->required()->native(false)->displayFormat('F Y')
                    ->default(now()->startOfMonth())->helperText('Pilih tanggal mana pun di bulan anggaran.'),
                Forms\Components\Select::make('store_id')->label('Toko')->options(fn () => Store::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->placeholder('Seluruh perusahaan')->searchable(),
                Forms\Components\Select::make('chart_of_account_id')->label('Akun beban')
                    ->options(fn () => ChartOfAccount::whereIn('type', ['beban_pokok', 'beban_operasional', 'beban_lain'])->where('is_postable', true)->where('is_active', true)->orderBy('code')->get()
                        ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                    ->required()->searchable()
                    ->unique(table: 'budgets', column: 'chart_of_account_id', ignoreRecord: true, modifyRuleUsing: fn ($rule, Forms\Get $get) => $rule
                        ->where('period_month', \Illuminate\Support\Carbon::parse($get('period_month') ?: now())->startOfMonth()->toDateString())
                        ->where('store_id', $get('store_id'))),
                Forms\Components\TextInput::make('amount')->label('Anggaran')->numeric()->required()->minValue(0)->prefix('Rp'),
                Forms\Components\Textarea::make('notes')->label('Catatan')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('period_month')->label('Bulan')->date('F Y')->sortable(),
                Tables\Columns\TextColumn::make('store.name')->label('Toko')->placeholder('Seluruh perusahaan'),
                Tables\Columns\TextColumn::make('account.display_name')->label('Akun'),
                Tables\Columns\TextColumn::make('amount')->label('Anggaran')->money('IDR', locale: 'id'),
            ])
            ->defaultSort('period_month', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('copy')
                    ->label('Salin dari bulan lalu')
                    ->icon('heroicon-o-document-duplicate')
                    ->visible(fn () => static::canCreate())
                    ->form([
                        Forms\Components\DatePicker::make('month')->label('Bulan tujuan')->required()->native(false)->displayFormat('F Y')->default(now()->startOfMonth()),
                        Forms\Components\Select::make('store_id')->label('Toko')->options(fn () => Store::where('is_active', true)->orderBy('name')->pluck('name', 'id'))->placeholder('Seluruh perusahaan'),
                    ])
                    ->action(function (array $data) {
                        $copied = app(BudgetService::class)->copyFromPrevious(\Illuminate\Support\Carbon::parse($data['month']), $data['store_id'] ?? null, auth()->id());
                        Notification::make()->title($copied > 0 ? "{$copied} anggaran disalin" : 'Tidak ada anggaran baru untuk disalin')->success()->send();
                    }),
            ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['account', 'store']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBudgets::route('/'),
            'create' => Pages\CreateBudget::route('/create'),
            'edit' => Pages\EditBudget::route('/{record}/edit'),
        ];
    }
}
