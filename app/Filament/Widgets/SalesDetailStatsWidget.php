<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SalesResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stat card di atas tabel "Detail Penjualan" — diminta 2026-09-09,
 * dilengkapi 2026-09-10 jadi kartu ala "Daftar Invoice" Majoo:
 * Total Invoice / Lunas / Belum Lunas / Void / Total Diterima.
 *
 * TIDAK LAGI dipakai sebagai header widget di ListSales (audit
 * 2026-09-11, temuan #3) — kartu yang BENAR-BENAR tampil di halaman
 * "Detail Penjualan" sekarang dirender lewat `Table::header()` di
 * SalesResource (lihat `->header()` di sana), yang bisa baca
 * `$livewire->getFilteredTableQuery()` supaya REAKTIF ikut filter yang
 * sedang aktif — sebelumnya widget ini SELALU menampilkan total
 * KESELURUHAN data yang bisa diakses user, tidak peduli filter apa pun
 * yang dipilih di tabel bawahnya (batasan itu didokumentasikan di sini
 * karena saat itu belum ketemu cara resmi mengatasinya).
 *
 * Class ini DIPERTAHANKAN (dipakai `aggregate()`-nya oleh SalesResource)
 * supaya agregasi SQL cuma ada SATU implementasi — dipanggil dengan
 * query BERBEDA (base scope vs filtered) tergantung pemakainya.
 */
class SalesDetailStatsWidget extends StatsOverviewWidget
{
    // Audit framework 2026-09-14, "Auto-polling widget Livewire" --
    // sebelumnya ikut default Filament (poll tiap 60 detik walau
    // halaman idle), tidak perlu real-time untuk widget ini.
    protected static ?string $pollingInterval = null;

    protected static ?int $sort = -1;

    /**
     * SEBELUMNYA cuma cek SalesResource::canViewAny() — komentar class di
     * atas sudah jelas bilang widget ini TIDAK LAGI dipakai sebagai
     * header widget (diganti Table::header() reaktif di SalesResource),
     * cuma dipertahankan untuk method statis aggregate()-nya. Tapi tanpa
     * canView() ini, widget tetap ikut ter-render sebagai widget viewable
     * biasa di Dashboard utama /admin (folder ini di-auto-discover
     * panel-wide) — kartu "Total Invoice/Lunas/Belum Lunas/Void/Total
     * Diterima" nongol duluan (sort=-1) padahal tidak pernah dimaksudkan
     * tampil di sana. Audit 2026-09-14 ("urutan metrics Dashboard") —
     * sama kelas bug dengan 4 chart laporan yang sebelumnya dipindah ke
     * App\Filament\ReportWidgets, tapi class ini TIDAK dipindah (tetap di
     * sini) karena aggregate()-nya masih dipanggil statis dari
     * SalesResource — cukup dimatikan sebagai widget, bukan dipindah
     * namespace.
     */
    public static function canView(): bool
    {
        return false;
    }

    /**
     * Agregasi SQL murni (COUNT/SUM/CASE, ->toBase()->first()) — DB yang
     * hitung, bukan PHP yang tarik semua baris lalu hitung (audit
     * 2026-09-11, temuan #2: SEBELUMNYA ->get([...]) + Collection sum/
     * filter). Ambang "belum lunas" (selisih > 0.009) direplikasi PERSIS
     * dengan logika PHP yang dipakai kolom 'payment_status' & SalesExport.
     *
     * @return array{total_count:int, total_revenue:float, total_received:float, void_count:int, void_amount:float, belum_lunas_count:int, belum_lunas_amount:float, lunas_count:int, lunas_amount:float}
     */
    public static function aggregate(Builder $query): array
    {
        // reorder() kosongkan ORDER BY yang mungkin sudah menempel di
        // $query (mis. dari getFilteredTableQuery() yang membawa sort
        // aktif tabel) — tidak relevan untuk 1 baris hasil agregat, dan
        // menghindari kombinasi ORDER BY + agregat tanpa GROUP BY yang
        // walau tidak error, tidak ada gunanya dieksekusi DB.
        $agg = $query
            ->reorder()
            ->selectRaw(
                'COUNT(*) as total_count,'
                . ' COALESCE(SUM(bookings.transaction_amount), 0) as total_revenue,'
                . ' COALESCE(SUM(COALESCE(bookings.amount_received, bookings.transaction_amount)), 0) as total_received,'

                . ' COUNT(CASE WHEN bookings.status = \'cancelled\' THEN 1 END) as void_count,'
                . ' COALESCE(SUM(CASE WHEN bookings.status = \'cancelled\' THEN bookings.transaction_amount ELSE 0 END), 0) as void_amount,'

                . ' COUNT(CASE WHEN bookings.status != \'cancelled\''
                . ' AND (bookings.transaction_amount - COALESCE(bookings.amount_received, bookings.transaction_amount)) > 0.009'
                . ' THEN 1 END) as belum_lunas_count,'
                . ' COALESCE(SUM(CASE WHEN bookings.status != \'cancelled\''
                . ' AND (bookings.transaction_amount - COALESCE(bookings.amount_received, bookings.transaction_amount)) > 0.009'
                . ' THEN (bookings.transaction_amount - COALESCE(bookings.amount_received, bookings.transaction_amount)) ELSE 0 END), 0) as belum_lunas_amount,'

                . ' COUNT(CASE WHEN bookings.status != \'cancelled\''
                . ' AND (bookings.transaction_amount - COALESCE(bookings.amount_received, bookings.transaction_amount)) <= 0.009'
                . ' THEN 1 END) as lunas_count,'
                . ' COALESCE(SUM(CASE WHEN bookings.status != \'cancelled\''
                . ' AND (bookings.transaction_amount - COALESCE(bookings.amount_received, bookings.transaction_amount)) <= 0.009'
                . ' THEN bookings.transaction_amount ELSE 0 END), 0) as lunas_amount'
            )
            ->toBase()
            ->first();

        return [
            'total_count' => (int) $agg->total_count,
            'total_revenue' => (float) $agg->total_revenue,
            'total_received' => (float) $agg->total_received,
            'void_count' => (int) $agg->void_count,
            'void_amount' => (float) $agg->void_amount,
            'belum_lunas_count' => (int) $agg->belum_lunas_count,
            'belum_lunas_amount' => (float) $agg->belum_lunas_amount,
            'lunas_count' => (int) $agg->lunas_count,
            'lunas_amount' => (float) $agg->lunas_amount,
        ];
    }

    protected function getStats(): array
    {
        $agg = static::aggregate(SalesResource::getEloquentQuery());

        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');

        return [
            Stat::make('Total Invoice', $rupiah($agg['total_revenue']))
                ->description(number_format($agg['total_count'], 0, ',', '.') . ' invoice'),
            Stat::make('Lunas', $rupiah($agg['lunas_amount']))
                ->description(number_format($agg['lunas_count'], 0, ',', '.') . ' invoice')
                ->color('success'),
            Stat::make('Belum Lunas', $rupiah($agg['belum_lunas_amount']))
                ->description(number_format($agg['belum_lunas_count'], 0, ',', '.') . ' invoice')
                ->color($agg['belum_lunas_count'] > 0 ? 'warning' : 'gray'),
            Stat::make('Void', $rupiah($agg['void_amount']))
                ->description(number_format($agg['void_count'], 0, ',', '.') . ' invoice')
                ->color($agg['void_count'] > 0 ? 'danger' : 'gray'),
            Stat::make('Total Diterima', $rupiah($agg['total_received'])),
        ];
    }
}
