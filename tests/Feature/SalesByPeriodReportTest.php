<?php

namespace Tests\Feature;

use App\Filament\Pages\SalesByPeriodReport;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Audit Penjualan Per Periode 2026-09-29: batas minggu eksplisit Senin-Minggu (disamakan dengan
 * SalesSnapshotService), dan bucket membawa rentang tanggalnya sendiri untuk drill-down.
 * (Belum pernah dijalankan lokal -- tidak ada PHP; cek hasil CI.)
 */
class SalesByPeriodReportTest extends TestCase
{
    public function test_weekly_bucket_starts_on_monday(): void
    {
        // 2026-09-10 adalah hari Kamis.
        [$key, $label, $end] = SalesByPeriodReport::periodKeyFor(Carbon::parse('2026-09-10'), 'mingguan');

        $this->assertSame('2026-09-07', $key); // Senin
        $this->assertTrue($end->isSameDay('2026-09-13')); // Minggu
        $this->assertStringContainsString('07 Sep', $label);
    }

    public function test_daily_bucket_key_matches_date(): void
    {
        [$key, , $end] = SalesByPeriodReport::periodKeyFor(Carbon::parse('2026-09-10'), 'harian');

        $this->assertSame('2026-09-10', $key);
        $this->assertTrue($end->isSameDay('2026-09-10'));
    }
}
