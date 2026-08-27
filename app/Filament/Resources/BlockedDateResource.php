<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BlockedDateResource\Pages;
use App\Models\Booking;
use App\Models\BlockedDate;
use App\Models\Store;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BlockedDateResource extends Resource
{
    protected static ?string $model = BlockedDate::class;

    protected static ?string $navigationIcon = 'heroicon-o-x-circle';

    protected static ?string $navigationGroup = 'Booking';

    protected static ?string $navigationLabel = 'Tanggal Tidak Tersedia';

    protected static ?string $modelLabel = 'Tanggal Blokir';

    protected static ?string $pluralModelLabel = 'Tanggal Tidak Tersedia';

    protected static ?int $navigationSort = 40;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        $isSuperAdmin = auth()->user()?->isFullAccess();

        return $form->schema([
            Forms\Components\Select::make('store_id')
                ->label('Toko / Workshop')
                ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                ->required()
                ->live()
                ->default(fn () => $isSuperAdmin ? null : auth()->user()?->store_id)
                ->disabled(! $isSuperAdmin),

            Forms\Components\DatePicker::make('date')
                ->label('Tanggal Mulai')
                ->required()
                ->live()
                ->minDate(today())
                // Composite unique (store_id + date) di database — tanpa
                // validasi ini, coba blokir tanggal yang sudah diblokir
                // untuk toko yang sama akan kena error database mentah
                // alih-alih pesan yang jelas. Cuma menjaga tanggal MULAI —
                // kalau dipakai sebagai rentang (date_end diisi), tanggal
                // tengah/akhir yang bentrok dicek terpisah di
                // CreateBlockedDate::handleRecordCreation().
                ->unique(
                    table: 'blocked_dates',
                    column: 'date',
                    modifyRuleUsing: fn ($rule, Forms\Get $get) => $rule->where('store_id', $get('store_id')),
                )
                ->validationMessages([
                    'unique' => 'Tanggal ini sudah diblokir untuk toko ini.',
                ]),

            // SEBELUMNYA tidak bisa blokir rentang tanggal sekaligus — staff
            // harus submit form satu-satu per tanggal untuk skenario libur
            // multi-hari (Lebaran, renovasi, dst), repetitif dan rawan
            // kelewatan. Field virtual ini (tidak disimpan langsung, lihat
            // dehydrated(false)) memicu CreateBlockedDate::
            // handleRecordCreation() untuk membuat 1 baris BlockedDate per
            // tanggal dalam rentang. Lihat audit modul Tanggal Tidak
            // Tersedia 2026-08-27.
            Forms\Components\DatePicker::make('date_end')
                ->label('Sampai Tanggal (opsional — untuk blokir beberapa hari sekaligus)')
                ->live()
                ->minDate(fn (Forms\Get $get) => $get('date') ?: today())
                // TETAP dehydrated (tidak dibuang dari $data) — dibaca
                // langsung di CreateBlockedDate::handleRecordCreation()
                // untuk membentuk rentang tanggal, lalu dibuang manual
                // sebelum dikirim ke BlockedDate::create() di sana (kolom
                // ini tidak ada di tabel blocked_dates).
                ->dehydrated(),

            // Info non-blocking — supaya staff tahu dulu ada berapa booking
            // confirmed di rentang ini SEBELUM menutup slot baru, bukan
            // baru sadar setelah blokir tersimpan. Blokir tidak membatalkan
            // booking yang sudah ada (by design), ini murni kesadaran.
            Forms\Components\Placeholder::make('overlap_warning')
                ->label('')
                ->columnSpanFull()
                ->content(function (Forms\Get $get) {
                    $storeId = $get('store_id');
                    $startStr = $get('date');

                    if (! $storeId || ! $startStr) {
                        return '';
                    }

                    $start = \Illuminate\Support\Carbon::parse($startStr);
                    $end = $get('date_end') ? \Illuminate\Support\Carbon::parse($get('date_end')) : $start->copy();

                    if ($end->lt($start)) {
                        return '';
                    }

                    $lines = [];
                    $day = $start->copy();
                    while ($day->lte($end)) {
                        $count = Booking::confirmedOverlapCount((int) $storeId, $day);
                        if ($count > 0) {
                            $lines[] = $day->format('d M Y') . ": {$count} booking confirmed (tidak ikut dibatalkan, cuma info)";
                        }
                        $day->addDay();
                    }

                    if (empty($lines)) {
                        return '';
                    }

                    return new \Illuminate\Support\HtmlString(
                        '<div style="color:#b45309">⚠️ ' . implode('<br>⚠️ ', array_map('e', $lines)) . '</div>'
                    );
                }),

            Forms\Components\TextInput::make('reason')
                ->label('Alasan (opsional)')
                ->placeholder('Contoh: Libur nasional, Workshop penuh, Maintenance')
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->sortable(),

                Tables\Columns\TextColumn::make('date')
                    ->label('Tanggal')
                    ->date('l, d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Alasan')
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->isFullAccess()),

                // Sebelumnya tidak ada — daftar terus bertambah tanpa
                // batas waktu, tanggal blokir yang sudah lewat (tidak
                // relevan lagi) tetap nongkrong di tabel selamanya. Aktif
                // default (sama pola dengan filter "upcoming" di
                // BookingResource), staff bisa matikan kalau memang perlu
                // lihat histori. Lihat audit modul Tanggal Tidak Tersedia
                // 2026-08-27.
                Tables\Filters\Filter::make('upcoming')
                    ->label('Hanya yang akan datang')
                    ->query(fn (Builder $query) => $query->whereDate('date', '>=', today()))
                    ->default(),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('date');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBlockedDates::route('/'),
            'create' => Pages\CreateBlockedDate::route('/create'),
        ];
    }
}
