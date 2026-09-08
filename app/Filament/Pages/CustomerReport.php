<?php

namespace App\Filament\Pages;

use App\Models\Customer;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * "Laporan Pelanggan" — diminta 2026-09-08, analog "Laporan Pelanggan"
 * Majoo. CustomerResource yang sudah ada cuma daftar akun mentah, tidak
 * ada analitik (repeat vs baru, total belanja). Halaman ini mengagregasi
 * data yang SUDAH ADA (tidak ada tabel/kolom baru).
 *
 * DIBATASI ke pelanggan yang PUNYA AKUN (Customer::bookings(), via
 * customer_id) — booking walk-in tanpa akun (cuma customer_name string)
 * TIDAK bisa diandalkan untuk deteksi "pelanggan yang sama" (nama bisa
 * beda ketik antar kunjungan), jadi sengaja tidak dipaksa masuk supaya
 * tidak menyesatkan (under-count "repeat" itu lebih aman daripada
 * over-count karena salah cocokkan nama).
 *
 * SEBELUMNYA di cluster Marketing/Konten — dipindah ke PenjualanCluster
 * (diminta 2026-09-08, permintaan susulan) supaya SEMUA laporan ngumpul
 * di 1 tab Penjualan, tidak tercecer ke cluster lain.
 */
class CustomerReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    // Dikelompokkan di bawah heading sidebar 'Laporan' (diminta
    // 2026-09-08) -- Dashboard Penjualan (SalesDashboard) SENGAJA tidak
    // ikut, tetap berdiri sendiri di atas grup ini.
    protected static ?string $navigationGroup = 'Laporan';

    protected static ?string $navigationLabel = 'Laporan Pelanggan';

    protected static ?string $title = 'Laporan Pelanggan';

    protected static ?int $navigationSort = 25;

    protected static string $view = 'filament.pages.customer-report';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required(),
            DatePicker::make('to')->label('Sampai')->native(false)->required(),
        ])->columns(2)->statePath('data');
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();

        $newCustomers = Customer::whereBetween('created_at', [$from, $to])->count();

        // "Repeat" = pelanggan yang punya >1 booking BERBAYAR (bukan
        // sekadar >1 pengajuan booking apa pun) SEPANJANG WAKTU (bukan
        // dibatasi rentang tanggal filter — status "repeat customer"
        // itu sifat kumulatif, bukan per-periode), dihitung lewat
        // whereHas('journalEntry') sama filter dengan Laporan Jasa/
        // SalesResource.
        $topCustomers = Customer::query()
            ->withCount(['bookings as bookings_in_period' => fn ($q) => $q
                ->whereHas('journalEntry', fn ($q2) => $q2->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
                ->where('transaction_amount', '>', 0)])
            ->withSum(['bookings as spend_in_period' => fn ($q) => $q
                ->whereHas('journalEntry', fn ($q2) => $q2->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
                ->where('transaction_amount', '>', 0)], 'transaction_amount')
            ->withCount(['bookings as bookings_all_time' => fn ($q) => $q
                ->whereHas('journalEntry')
                ->where('transaction_amount', '>', 0)])
            ->having('bookings_in_period', '>', 0)
            ->orderByDesc('spend_in_period')
            ->limit(20)
            ->get();

        // ->get()->count() (bukan ->count() langsung) — count() Query
        // Builder tidak selalu aman dikombinasikan dengan having() di atas
        // kolom hasil withCount() (bukan kolom GROUP BY sungguhan), jadi
        // ambil koleksinya dulu baru dihitung supaya query yang benar-
        // benar dieksekusi PERSIS sama dengan yang dipakai $topCustomers.
        $repeatCount = Customer::query()
            ->withCount(['bookings as bookings_all_time' => fn ($q) => $q
                ->whereHas('journalEntry')
                ->where('transaction_amount', '>', 0)])
            ->having('bookings_all_time', '>', 1)
            ->get()
            ->count();

        return [
            'newCustomers' => $newCustomers,
            'repeatCount' => $repeatCount,
            'topCustomers' => $topCustomers,
        ];
    }
}
