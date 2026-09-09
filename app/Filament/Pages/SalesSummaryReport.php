<?php

namespace App\Filament\Pages;

use App\Exports\SalesSummaryExport;
use App\Models\Booking;
use App\Models\VoucherClaim;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * "Ringkasan Penjualan" — diminta 2026-09-08, analog waterfall "Ringkasan
 * Penjualan" Majoo (Pendapatan → Biaya Promosi → Penjualan Bersih →
 * Laba Kotor). BEDA PENTING dari tiruan Majoo mentah: banyak baris di
 * versi Majoo (Ongkos Kirim, Biaya Pelayanan/MDR, Platform, Asuransi,
 * HPP, Refund) TIDAK PERNAH dicatat di sistem Ginnva sama sekali — bukan
 * karena kelupaan, tapi karena memang tidak relevan untuk bisnis jasa
 * PPF/Kaca Film (bukan resto/marketplace), atau karena datanya memang
 * belum pernah ditangkap (HPP per booking, refund, PPN — PPN sudah jadi
 * salah satu dari 3 keputusan bisnis di dokumen "Catatan untuk Atasan").
 *
 * Prinsip halaman ini: baris yang datanya VALID dihitung dari data asli
 * (Rp sungguhan), baris yang TIDAK RELEVAN ditandai "Tidak berlaku",
 * baris yang BELUM BISA dihitung (butuh data/keputusan lebih dulu)
 * ditandai "Belum tersedia" — TIDAK ADA satu pun angka yang ditebak/
 * dipaksa jadi Rp 0 supaya terlihat lengkap seperti tiruan Majoo.
 *
 * Sumber kebenaran pendapatan SAMA PERSIS dengan seluruh laporan
 * Penjualan lain: whereHas('journalEntry') + transaction_amount > 0
 * (booking yang benar-benar sudah diproses ke Jurnal Umum).
 *
 * Promo Voucher DIHITUNG dari VoucherClaim (status=used, di dalam
 * rentang tanggal) × Voucher::discount_amount — ini SATU-SATUNYA
 * "biaya promosi" yang punya nilai Rupiah tersimpan di sistem. Reward
 * (poin) TIDAK dihitung di sini karena Reward tidak punya nilai Rupiah
 * (cuma points_cost), sudah ada laporannya sendiri di Laporan Promo &
 * Loyalti.
 */
class SalesSummaryReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    // Grup sendiri 'Laporan Penjualan' (diubah 2026-09-09 dari 'Laporan'
    // gabungan) -- sejajar dengan grup kategori laporan lain, bukan
    // nested (Filament v3 tidak dukung dropdown bersarang).
    protected static ?string $navigationGroup = 'Laporan Penjualan';

    protected static ?string $navigationLabel = 'Ringkasan Penjualan';

    protected static ?string $title = 'Ringkasan Penjualan';

    // Sort 1 -- baris pertama di grup 'Laporan Penjualan', sama urutan
    // seperti "Ringkasan Penjualan" jadi item pertama di submenu Majoo.
    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.sales-summary-report';

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
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
        ])->columns(2)->statePath('data');
    }

    /**
     * "Ekspor Laporan" (diminta 2026-09-09, analog tombol di halaman
     * Ringkasan Penjualan Majoo) -- Excel & PDF, keduanya dibangun dari
     * getResult() yang SAMA PERSIS yang dipakai halaman web (bukan
     * query terpisah), supaya angka di file export selalu konsisten
     * dengan yang tampil di layar untuk filter tanggal yang sama.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new SalesSummaryExport($this->getResult()),
                    'ringkasan-penjualan-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.sales_summary', ['result' => $result])->setPaper('a4', 'portrait');
                    $filename = 'ringkasan-penjualan-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();

        $bookingsQuery = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
            ->where('transaction_amount', '>', 0);

        $grossSales = (float) (clone $bookingsQuery)->sum('transaction_amount');

        // Promo Voucher -- satu-satunya "biaya promosi" yang punya nilai
        // Rupiah tersimpan (Voucher::discount_amount). used_at dipakai
        // (bukan created_at) karena itu tanggal voucher BENAR-BENAR
        // dipakai transaksi, bukan tanggal diklaim.
        $voucherDiscount = (float) VoucherClaim::query()
            ->where('status', 'used')
            ->whereNotNull('booking_id')
            ->whereBetween('used_at', [$from, $to])
            ->with('voucher:id,discount_amount')
            ->get()
            ->sum(fn (VoucherClaim $claim) => (float) ($claim->voucher->discount_amount ?? 0));

        // Refund/pengembalian tidak pernah dicatat sistem -- bukan 0
        // karena "sudah dicek tidak ada", tapi karena memang tidak ada
        // mekanismenya sama sekali. Ditandai null supaya view tahu ini
        // beda dari "dicek dan hasilnya nol".
        $refund = null;

        $netSales = $grossSales; // tidak dikurangi apa pun karena refund belum ada

        return [
            'from' => $from,
            'to' => $to,
            'grossSales' => $grossSales,
            'voucherDiscount' => $voucherDiscount,
            'refund' => $refund,
            'netSales' => $netSales,
            'bookingCount' => (clone $bookingsQuery)->count(),
        ];
    }
}
