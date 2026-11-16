<?php

namespace Tests\Feature;

use App\Filament\Clusters\BookingCluster;
use App\Filament\Clusters\InventarisCluster;
use App\Filament\Clusters\KaryawanCluster;
use App\Filament\Clusters\KeuanganCluster;
use App\Filament\Clusters\MarketingKontenCluster;
use App\Filament\Pages\BayZoneUtilizationReport;
use App\Filament\Pages\DashboardHome;
use App\Filament\Pages\FinanceDashboard;
use App\Filament\Pages\HrDashboard;
use App\Filament\Pages\LeadFunnelReport;
use App\Filament\Pages\ProductSalesReport;
use App\Filament\Pages\SalesDashboard;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\SpkResource;
use App\Filament\Resources\TransactionApprovalRequestResource;
use Tests\TestCase;

/** Tahap A rapi navigasi (2026-11-16): penamaan seragam, urutan bilah atas unik, grup satu-item digabung, laporan Bay disembunyikan. */
class NavigationStructureTest extends TestCase
{
    public function test_top_bar_clusters_have_unique_sort_order(): void
    {
        $sorts = [
            BookingCluster::getNavigationSort(), InventarisCluster::getNavigationSort(), MarketingKontenCluster::getNavigationSort(),
            KaryawanCluster::getNavigationSort(), KeuanganCluster::getNavigationSort(),
        ];

        $this->assertSame($sorts, array_values(array_unique($sorts)));
    }

    public function test_stock_cluster_is_called_inventaris_everywhere(): void
    {
        $this->assertSame('Inventaris', InventarisCluster::getNavigationLabel());
    }

    public function test_dashboards_have_distinct_consistent_names(): void
    {
        $labels = [
            DashboardHome::getNavigationLabel(), SalesDashboard::getNavigationLabel(), HrDashboard::getNavigationLabel(),
            FinanceDashboard::getNavigationLabel(), LeadFunnelReport::getNavigationLabel(),
        ];

        $this->assertSame(['Dashboard', 'Dashboard Penjualan', 'Dashboard HR', 'Dashboard Keuangan', 'Dashboard Lead'], $labels);
        $this->assertCount(count($labels), array_unique($labels));
    }

    public function test_the_order_flow_groups_quotation_booking_spk_invoice_and_transaction_approval(): void
    {
        $this->assertSame('Order & Lead', InvoiceResource::getNavigationGroup());
        $this->assertSame('Order & Lead', TransactionApprovalRequestResource::getNavigationGroup());
        $this->assertSame(SpkResource::getNavigationGroup(), InvoiceResource::getNavigationGroup());
        $this->assertGreaterThan(SpkResource::getNavigationSort(), InvoiceResource::getNavigationSort());
    }

    public function test_single_item_report_group_is_merged_and_the_bay_report_is_hidden_by_default(): void
    {
        $this->assertSame('Laporan Penjualan', ProductSalesReport::getNavigationGroup());

        $this->assertFalse(BayZoneUtilizationReport::shouldRegisterNavigation());
    }
}
