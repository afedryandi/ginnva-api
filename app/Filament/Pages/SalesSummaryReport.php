<?php

namespace App\Filament\Pages;

use App\Exports\SalesSummaryExport;
use App\Models\VoucherClaim;
use App\Services\SalesSnapshotService;
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
 * HPP) TIDAK PERNAH dicatat di sistem Ginnva sama sekali — bukan
 * karena kelupaan, tapi karena memang tidak relevan untuk bisnis jasa
 * PPF/Kaca Film (bukan resto/marketplace), atau karena datanya memang
 * belum pernah ditangkap (HPP per booking, PPN — PPN sudah jadi
 * salah satu dari 3 keputusan bisnis di dokumen "Catatan untuk Atasan").
 * Refund SEKARANG dihitung sungguhan (2026-09-09, lihat RefundService)
 * -- SEMPAT blocked, sudah tidak lagi.
 *
 * Prinsip halaman ini: baris yang datanya VALID dihitung dari data asli
 * (Rp sungguhan), baris yang TIDAK RELEVAN ditandai "Tidak berlaku",
 * baris yang BELUM BISA dihitung (butuh data/keputusan lebih dulu)
 * ditandai "Belum tersedia" — TIDAK ADA satu pun angka yang ditebak/
 * dipaksa jadi Rp 0 supaya terlihat lengkap seperti tiruan Majoo.
 *
 * Sumber kebenaran pendapatan (gross/refund/net/jumlah transaksi) SEJAK
 * 2026-09-11 pakai App\Services\SalesSnapshotService — SAMA PERSIS yang
 * dipakai SalesDashboard, termasuk scoping toko-nya (SEBELUMNYA halaman
 * ini punya query duplikat sendiri yang lupa di-scope ke store_id untuk
 * grossSales/bookingCount, cuma refund yang di-scope — bug ditemukan
 * saat audit, lihat memory project_sales_dashboard_audit).
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
        // ?from=&to= (audit Dashboard Penjualan 2026-09-11, temuan #7) —
        // dibaca dari query string kalau ada (dipakai tombol "Lihat &
        // Export" di Dashboard Penjualan supaya rentang tanggal yang
        // sedang dilihat di sana ikut terbawa ke sini, bukan cuma
        // melempar ke bulan berjalan). Sengaja BUKAN Livewire #[Url] —
        // halaman ini cuma butuh baca sekali saat mount, tidak perlu
        // filternya balik nulis ke URL tiap form berubah.
        $this->form->fill([
            'from' => $this->queryDateOrDefault(request()->query('from'), now()->startOfMonth()),
            'to' => $this->queryDateOrDefault(request()->query('to'), now()->endOfMonth()),
        ]);
    }

    private function queryDateOrDefault(mixed $value, Carbon $default): string
    {
        if (! is_string($value) || $value === '') {
            return $default->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return $default->toDateString();
        }
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

        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;
        // BUG DIPERBAIKI 2026-09-11 (ditemukan saat audit): sebelumnya
        // grossSales/bookingCount/voucherDiscount TIDAK di-scope ke toko
        // sama sekali (cuma refund yang di-scope) — manajer toko melihat
        // angka company-wide, dan netSales = gross(semua cabang) −
        // refund(cabang sendiri) MATEMATISNYA SALAH, bukan cuma bocor.
        // Sekarang pakai SalesSnapshotService (SATU sumber kebenaran yang
        // sama dengan SalesDashboard) supaya gross/refund/net/count
        // konsisten ter-scope bareng, bukan query terpisah yang gampang
        // menyimpang lagi ke depan. null = seluruh cabang (full-access
        // saja) — belum ada filter cabang di halaman ini (lihat memory
        // project_sales_dashboard_audit, temuan B).
        $storeId = $isFullAccess ? null : $user?->store_id;

        $snapshot = app(SalesSnapshotService::class)->summarize($from, $to, $storeId);

        // Promo Voucher -- satu-satunya "biaya promosi" yang punya nilai
        // Rupiah tersimpan (Voucher::discount_amount). used_at dipakai
        // (bukan created_at) karena itu tanggal voucher BENAR-BENAR
        // dipakai transaksi, bukan tanggal diklaim. Di-scope ke toko lewat
        // relasi booking, sama pola dengan refund.
        $voucherDiscount = (float) VoucherClaim::query()
            ->where('status', 'used')
            ->whereNotNull('booking_id')
            ->whereBetween('used_at', [$from, $to])
            ->when($storeId, fn ($q) => $q->whereHas('booking', fn ($q2) => $q2->where('store_id', $storeId)))
            ->with('voucher:id,discount_amount')
            ->get()
            ->sum(fn (VoucherClaim $claim) => (float) ($claim->voucher->discount_amount ?? 0));

        return [
            'from' => $from,
            'to' => $to,
            'grossSales' => $snapshot['revenue'],
            'voucherDiscount' => $voucherDiscount,
            'refund' => $snapshot['refund'],
            'netSales' => $snapshot['net'],
            'bookingCount' => $snapshot['count'],
        ];
    }
}
