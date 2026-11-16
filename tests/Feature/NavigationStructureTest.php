<?php

namespace Tests\Feature;

use App\Filament\Clusters\BookingCluster;
use App\Filament\Clusters\InventarisCluster;
use App\Filament\Clusters\KaryawanCluster;
use App\Filament\Clusters\KeuanganCluster;
use App\Filament\Clusters\MarketingKontenCluster;
use App\Filament\Clusters\MasterDataCluster;
use App\Filament\Clusters\PenjualanCluster;
use App\Filament\Clusters\SistemCluster;
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
            KaryawanCluster::getNavigationSort(), KeuanganCluster::getNavigationSort(), PenjualanCluster::getNavigationSort(),
            MasterDataCluster::getNavigationSort(), SistemCluster::getNavigationSort(), \App\Filament\Pages\Panduan::getNavigationSort(),
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

    public function test_stage_b_merged_clusters_hold_their_former_members_and_the_old_clusters_are_gone(): void
    {
        foreach ([
            \App\Filament\Resources\FilmProductResource::class, \App\Filament\Resources\CustomerResource::class, \App\Filament\Resources\StoreResource::class,
            \App\Filament\Resources\VehicleResource::class, \App\Filament\Pages\MasterDataCenter::class,
        ] as $class) {
            $this->assertSame(MasterDataCluster::class, $class::getCluster(), $class);
        }

        foreach ([
            \App\Filament\Pages\PeakSalesTimeReport::class, \App\Filament\Pages\StockTurnoverReport::class, \App\Filament\Pages\SalesDashboard::class,
        ] as $class) {
            $this->assertSame(PenjualanCluster::class, $class::getCluster(), $class);
        }

        foreach ([
            \App\Filament\Resources\SpendPromoResource::class, \App\Filament\Resources\RewardResource::class, \App\Filament\Pages\SendNotification::class,
            \App\Filament\Resources\NotificationDeliveryResource::class,
        ] as $class) {
            $this->assertSame(MarketingKontenCluster::class, $class::getCluster(), $class);
        }

        $this->assertSame(SistemCluster::class, \App\Filament\Resources\GuideNoteResource::getCluster());
        $this->assertNull(\App\Filament\Pages\Panduan::getCluster(), 'Panduan terbuka untuk semua staf, jadi tidak boleh di kluster Sistem yang khusus akses penuh.');

        foreach (['Pelanggan', 'Produk', 'AnalisaLaporan', 'Promosi', 'Notifikasi'] as $old) {
            $this->assertFalse(class_exists('App\\Filament\\Clusters\\' . $old . 'Cluster'), $old);
        }
    }

    public function test_no_top_bar_dropdown_groups_remain(): void
    {
        foreach ([PenjualanCluster::class, MasterDataCluster::class, SistemCluster::class, MarketingKontenCluster::class] as $cluster) {
            $this->assertNull($cluster::getNavigationGroup(), $cluster);
        }
    }
}
