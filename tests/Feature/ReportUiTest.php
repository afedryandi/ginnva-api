<?php

namespace Tests\Feature;

use App\Filament\Pages\LayananReport;
use App\Filament\Pages\PeakSalesTimeReport;
use App\Filament\Pages\SalesDashboard;
use App\Filament\Pages\SalesSummaryReport;
use App\Filament\Pages\VoidReport;
use App\Filament\Resources\SalesResource\Pages\ListSales;
use App\Support\CleanUi;
use Tests\TestCase;

/** Kluster Laporan: semua halaman (laporan kustom, Dashboard Penjualan, Detail Penjualan) ikut gaya bersih, dan gaya tabel laporan hanya untuk kluster ini. */
class ReportUiTest extends TestCase
{
    public function test_every_page_of_the_report_cluster_gets_the_clean_ui_and_report_table_styles(): void
    {
        foreach ([new SalesSummaryReport(), new LayananReport(), new VoidReport(), new PeakSalesTimeReport(), new SalesDashboard(), new ListSales()] as $page) {
            $this->assertTrue(CleanUi::appliesTo($page), $page::class);
            $this->assertTrue(CleanUi::isReportPage($page), $page::class);
        }
    }

    public function test_report_table_styles_do_not_leak_to_other_menus(): void
    {
        $this->assertFalse(CleanUi::isReportPage(new \App\Filament\Resources\BookingResource\Pages\ListBookings()));
        $this->assertFalse(CleanUi::isReportPage(new \App\Filament\Pages\HrDashboard()));
        $this->assertFalse(CleanUi::isReportPage(null));
    }

    public function test_the_report_style_partial_targets_plain_report_tables_only(): void
    {
        $css = view('filament.partials.report-styles')->render();

        $this->assertStringContainsString('table.w-full:not(.fi-ta-table)', $css);
        $this->assertStringContainsString('thead th', $css);
        $this->assertStringContainsString('tabular-nums', $css);
    }
}
