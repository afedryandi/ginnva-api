<?php

namespace Tests\Feature;

use App\Exports\RefundReportExport;
use App\Filament\Pages\CouponReport;
use App\Filament\Pages\JenisOrderReport;
use App\Filament\Pages\LayananReport;
use App\Filament\Pages\PeakProductTimeReport;
use App\Filament\Pages\PeakSalesTimeReport;
use App\Filament\Pages\PersediaanDetailReport;
use App\Filament\Pages\PromoLoyaltyReport;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tiga laporan kembar disembunyikan lewat saklar (keputusan pemilik 2026-11-04): Laporan Jenis Order (= Laporan Jasa), Laporan Voucher
 * (= Laporan Promo), dan Lap. Detail Persediaan (tumpang tindih dengan Daftar Stok). Export Laporan Refund juga tidak lagi memuat kolom
 * No. Jurnal bila modul Keuangan mati.
 */
class HiddenReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('super_admin')), 'web');
    }

    public function test_the_duplicate_reports_close_when_their_switches_are_off_and_their_originals_stay(): void
    {
        config(['features.report_jenis_order' => false, 'features.report_voucher' => false, 'features.report_persediaan_detail' => false]);

        $this->assertFalse(JenisOrderReport::canAccess());
        $this->assertFalse(CouponReport::canAccess());
        $this->assertFalse(PersediaanDetailReport::canAccess());
        $this->assertTrue(LayananReport::canAccess(), 'Laporan Jasa tetap terbuka.');
        $this->assertTrue(PromoLoyaltyReport::canAccess(), 'Laporan Promo tetap terbuka.');

        config(['features.report_jenis_order' => true, 'features.report_voucher' => true, 'features.report_persediaan_detail' => true]);

        $this->assertTrue(JenisOrderReport::canAccess());
        $this->assertTrue(CouponReport::canAccess());
        $this->assertTrue(PersediaanDetailReport::canAccess());
    }

    public function test_the_refund_export_drops_the_journal_column_without_the_finance_module(): void
    {
        config(['features.finance' => false]);
        $without = (new RefundReportExport(['refunds' => []]))->headings();
        $this->assertNotContains('No. Jurnal', $without);
        $this->assertCount(9, $without);

        config(['features.finance' => true]);
        $with = (new RefundReportExport(['refunds' => []]))->headings();
        $this->assertContains('No. Jurnal', $with);
        $this->assertCount(10, $with);
    }
    public function test_the_peak_time_reports_close_with_their_switch(): void
    {
        config(['features.report_peak_time' => false]);
        $this->assertFalse(PeakSalesTimeReport::canAccess());
        $this->assertFalse(PeakProductTimeReport::canAccess());

        config(['features.report_peak_time' => true]);
        $this->assertTrue(PeakSalesTimeReport::canAccess());
        $this->assertTrue(PeakProductTimeReport::canAccess());
    }
}
