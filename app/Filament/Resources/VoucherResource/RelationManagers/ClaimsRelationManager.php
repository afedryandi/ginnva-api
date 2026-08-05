<?php

namespace App\Filament\Resources\VoucherResource\RelationManagers;

use App\Models\Booking;
use App\Models\Customer;
use App\Services\VoucherService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Assign kode voucher FISIK (sudah tercetak, dipegang staff/kasir) ke akun
 * customer — menggantikan alur klaim self-service digital yang lama.
 * Customer TIDAK melakukan aksi apa pun; kode yang di-assign di sini
 * otomatis muncul read-only di "Voucher Saya" pada mobile app-nya.
 */
class ClaimsRelationManager extends RelationManager
{
    protected static string $relationship = 'claims';

    protected static ?string $title = 'Kode Voucher Ter-assign';

    protected static ?string $modelLabel = 'Kode Voucher';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('holder_name')
                    ->label('Pemegang')
                    ->description(fn ($record) => $record->customer?->phone_number ?? $record->walkin_phone)
                    ->badge(fn ($record) => ! $record->customer_id)
                    ->color(fn ($record) => $record->customer_id ? null : 'gray')
                    ->formatStateUsing(fn ($record) => $record->customer_id
                        ? $record->holder_name
                        : ($record->holder_name ? "{$record->holder_name} (Walk-in)" : '— (Walk-in)')),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => $state === 'used' ? 'success' : 'warning')
                    ->formatStateUsing(fn (string $state) => $state === 'used' ? 'Terpakai' : 'Aktif'),

                Tables\Columns\TextColumn::make('booking.booking_number')
                    ->label('Booking Terkait')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Di-assign')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('assign_code')
                    ->label('Assign Kode Voucher')
                    ->icon('heroicon-o-plus')
                    ->form([
                        Forms\Components\Radio::make('holder_type')
                            ->label('Pemegang Voucher')
                            ->options([
                                'app'     => 'Customer App (sudah install & login)',
                                'walk_in' => 'Walk-in (belum/tidak install app)',
                            ])
                            ->default('app')
                            ->live()
                            ->required(),

                        Forms\Components\Select::make('customer_id')
                            ->label('Customer')
                            // Sebelumnya pakai options() statis yang me-load
                            // SEMUA customer ke form setiap modal dibuka —
                            // makin berat begitu basis customer tumbuh
                            // (apalagi pasca Grand Opening). getSearchResultsUsing
                            // bikin ini AJAX search lazy, cuma query pas user ngetik.
                            ->getSearchResultsUsing(fn (string $search) => Customer::query()
                                ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                                    ->orWhere('phone_number', 'like', "%{$search}%"))
                                ->orderBy('name')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (Customer $c) => [$c->id => "{$c->name} — {$c->phone_number}"]))
                            ->getOptionLabelUsing(function ($value) {
                                $customer = Customer::find($value);
                                return $customer ? "{$customer->name} — {$customer->phone_number}" : null;
                            })
                            ->searchable()
                            ->required()
                            ->visible(fn (Forms\Get $get) => $get('holder_type') === 'app'),

                        Forms\Components\TextInput::make('walkin_name')
                            ->label('Nama')
                            ->required()
                            ->maxLength(255)
                            ->visible(fn (Forms\Get $get) => $get('holder_type') === 'walk_in'),

                        Forms\Components\TextInput::make('walkin_phone')
                            ->label('No. HP (opsional)')
                            ->tel()
                            ->maxLength(30)
                            ->visible(fn (Forms\Get $get) => $get('holder_type') === 'walk_in'),

                        Forms\Components\TextInput::make('code')
                            ->label('Kode Voucher Fisik')
                            ->required()
                            ->maxLength(30)
                            ->helperText('Salin persis kode yang tertera di voucher fisik.'),

                        // Opsional — kalau diisi, voucher ini tercatat dipakai
                        // di booking mana (untuk laporan/audit). Tidak wajib
                        // karena voucher kadang diserahkan sebelum booking
                        // instalasi dibuat (mis. customer baru bayar DP).
                        Forms\Components\Select::make('booking_id')
                            ->label('Booking Terkait (opsional)')
                            ->helperText('Isi kalau voucher ini diserahkan bersamaan booking instalasi yang sudah ada.')
                            ->getSearchResultsUsing(fn (string $search) => Booking::query()
                                ->where('booking_number', 'like', "%{$search}%")
                                ->orderByDesc('created_at')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (Booking $b) => [$b->id => "#{$b->booking_number} — {$b->service_type}"]))
                            ->getOptionLabelUsing(function ($value) {
                                $booking = Booking::find($value);
                                return $booking ? "#{$booking->booking_number} — {$booking->service_type}" : null;
                            })
                            ->searchable(),
                    ])
                    ->action(function (array $data) {
                        try {
                            if ($data['holder_type'] === 'walk_in') {
                                app(VoucherService::class)->assignToWalkin(
                                    $this->getOwnerRecord(),
                                    $data['code'],
                                    $data['walkin_name'] ?? null,
                                    $data['walkin_phone'] ?? null,
                                    $data['booking_id'] ?? null
                                );
                            } else {
                                app(VoucherService::class)->assignToCustomer(
                                    $this->getOwnerRecord(),
                                    $data['code'],
                                    $data['customer_id'],
                                    $data['booking_id'] ?? null
                                );
                            }
                        } catch (RuntimeException $e) {
                            Notification::make()
                                ->title('Gagal assign voucher')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        } catch (QueryException $e) {
                            // Kode voucher yang sama diklaim 2x nyaris
                            // bersamaan (mis. 2 admin/2 tab) bisa lolos cek
                            // duplikat di VoucherService sebelum salah satu
                            // commit — dibackstop oleh unique constraint di
                            // DB, tapi sebelumnya exception itu tidak
                            // ketangkap di sini sama sekali, jadi staff
                            // lihat error 500 mentah alih-alih pesan yang
                            // jelas.
                            Notification::make()
                                ->title('Gagal assign voucher')
                                ->body(str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'UNIQUE')
                                    ? 'Kode voucher ini baru saja diklaim oleh proses lain. Silakan gunakan kode lain.'
                                    : 'Terjadi kesalahan database. Silakan coba lagi.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Voucher berhasil di-assign')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                // Dipakai saat customer (app maupun walk-in) datang booking
                // instalasi dan menyerahkan/menyebutkan kode voucher fisik
                // ini — staff cukup cari kodenya (kolom "Kode" searchable)
                // lalu tandai di sini, TIDAK perlu customer punya akun app.
                Tables\Actions\Action::make('mark_used')
                    ->label('Tandai Terpakai')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn ($record) => $record->status === 'active')
                    ->requiresConfirmation()
                    ->modalDescription('Tandai kode voucher ini sebagai sudah dipakai? Aksi ini menandakan customer sudah pakai voucher fisiknya saat booking.')
                    ->action(function ($record) {
                        $record->update(['status' => 'used', 'used_at' => now()]);

                        Notification::make()
                            ->title('Voucher ditandai terpakai')
                            ->success()
                            ->send();
                    }),

                // Kembalikan claimed_count supaya pelacakan stok tetap
                // akurat kalau staff salah input kode dan perlu hapus.
                // Lock row voucher-nya — SEBELUMNYA decrement() langsung
                // tanpa lock, jadi 2 admin hapus klaim berbeda untuk
                // voucher yang sama nyaris bersamaan bisa kehilangan salah
                // satu pengurangan (lost update), claimed_count jadi lebih
                // besar dari klaim yang sebenarnya masih ada.
                Tables\Actions\DeleteAction::make()
                    ->before(function ($record) {
                        DB::transaction(function () use ($record) {
                            $record->voucher()->lockForUpdate()->first()?->decrement('claimed_count');
                        });
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
