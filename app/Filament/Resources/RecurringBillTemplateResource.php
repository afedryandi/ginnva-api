<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RecurringBillTemplateResource\Pages;
use App\Models\ChartOfAccount;
use App\Models\RecurringBillTemplate;
use App\Models\Store;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * "Template tagihan rutin yg auto-generate Biaya/AP" (audit Majoo,
 * f48), dibangun 2026-09-22 atas keputusan user. TERBATAS full-access
 * -- sama filosofi PayableResource ("Catat Tagihan Manual"), template
 * ini juga akan memposting jurnal baru otomatis tiap bulan.
 *
 * Generate sungguhan lewat App\Console\Commands\GenerateRecurringBills
 * (terjadwal harian, lihat routes/console.php) -- resource ini cuma
 * CRUD definisinya.
 */
class RecurringBillTemplateResource extends Resource
{
    protected static ?string $model = RecurringBillTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationLabel = 'Template Tagihan Rutin';

    protected static ?string $modelLabel = 'Template Tagihan Rutin';

    protected static ?string $pluralModelLabel = 'Template Tagihan Rutin';

    protected static ?int $navigationSort = 6;

    private static function accessGate(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::accessGate();
    }

    public static function canCreate(): bool
    {
        return static::accessGate();
    }

    public static function canView($record): bool
    {
        return static::accessGate();
    }

    public static function canEdit($record): bool
    {
        return static::accessGate();
    }

    public static function canDelete($record): bool
    {
        return static::accessGate();
    }

    public static function canDeleteAny(): bool
    {
        return static::accessGate();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nama Template')
                ->placeholder('mis. Sewa Toko Bulanan, Langganan Software XYZ')
                ->required()
                ->maxLength(150),

            Forms\Components\TextInput::make('supplier_name')
                ->label('Supplier / Penerima')
                ->required()
                ->maxLength(150),

            Forms\Components\Select::make('store_id')
                ->label('Toko (opsional)')
                ->options(fn () => Store::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->placeholder('Tidak terikat 1 toko (perusahaan)')
                ->searchable(),

            Forms\Components\Select::make('chart_of_account_id')
                ->label('Akun Beban (Debit)')
                ->options(fn () => ChartOfAccount::where('is_active', true)->where('is_postable', true)
                    ->whereIn('type', ['beban_operasional', 'beban_lain', 'beban_pokok'])
                    ->orderBy('code')
                    ->get()
                    ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                ->required()
                ->searchable()
                ->helperText('Akun yang didebit tiap kali tagihan ini di-generate (Kredit-nya selalu 2110 Hutang Usaha).'),

            Forms\Components\TextInput::make('amount')
                ->label('Nominal per Periode')
                ->numeric()
                ->prefix('Rp')
                ->minValue(0.01)
                ->required(),

            Forms\Components\TextInput::make('day_of_month')
                ->label('Tanggal Generate Tiap Bulan')
                ->numeric()
                ->minValue(1)
                ->maxValue(31)
                ->required()
                ->helperText('Kalau bulan itu tidak punya tanggal sebesar ini (mis. 31 di Februari), otomatis jatuh ke tanggal terakhir bulan itu.'),

            Forms\Components\DatePicker::make('next_run_date')
                ->label('Mulai Generate Dari')
                ->native(false)
                ->required()
                ->default(now()->addMonthNoOverflow()->startOfMonth())
                ->helperText('Generate PERTAMA akan terjadi pada tanggal ini (atau setelahnya, saat cron harian jalan) -- generate BERIKUTNYA otomatis dijadwalkan 1 bulan setelahnya.'),

            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->default(true)
                ->helperText('Nonaktifkan untuk menghentikan auto-generate tanpa menghapus riwayat/template ini.'),

            Forms\Components\Textarea::make('notes')
                ->label('Catatan (opsional)')
                ->rows(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Template')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('supplier_name')
                    ->label('Supplier')
                    ->searchable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('Perusahaan'),

                Tables\Columns\TextColumn::make('chartOfAccount.name')
                    ->label('Akun Beban'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Nominal')
                    ->money('IDR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('next_run_date')
                    ->label('Generate Berikutnya')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->defaultSort('next_run_date')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->modalDescription('Menghapus template ini TIDAK menghapus tagihan yang sudah pernah di-generate sebelumnya, cuma menghentikan generate berikutnya. Nonaktifkan saja kalau cuma mau menjeda sementara.'),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRecurringBillTemplates::route('/'),
            'create' => Pages\CreateRecurringBillTemplate::route('/create'),
            'edit' => Pages\EditRecurringBillTemplate::route('/{record}/edit'),
        ];
    }
}
