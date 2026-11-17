<?php

namespace Tests\Feature;

use App\Filament\Pages\LeadFunnelReport;
use App\Filament\Pages\MasterDataCenter;
use App\Filament\Pages\SendNotification;
use App\Filament\Resources\CarouselResource\Pages\ListCarousels;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Resources\FilmProductResource\Pages\ListFilmProducts;
use App\Filament\Resources\NewsResource\Pages\ListNews;
use App\Filament\Resources\NotificationCampaignResource\Pages\ListNotificationCampaigns;
use App\Filament\Resources\PartnerResource\Pages\ListPartners;
use App\Filament\Resources\PartnershipInquiryResource\Pages\ListPartnershipInquiries;
use App\Filament\Resources\ProductInquiryResource\Pages\ListProductInquiries;
use App\Filament\Resources\RewardRedemptionResource\Pages\ListRewardRedemptions;
use App\Filament\Resources\StoreResource\Pages\ListStores;
use App\Filament\Resources\VehicleResource\Pages\ListVehicles;
use App\Models\Store;
use App\Models\User;
use App\Support\CleanUi;
use Filament\Facades\Filament;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Master Data dan Marketing/Konten: seluruh kluster ikut gaya bersih, filter lipat bawaan, tab status untuk antrean lead dan permintaan. */
class MasterDataMarketingUiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Http::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x', 'store_id' => $store->id, 'is_active' => true]);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        return $admin;
    }

    public function test_the_clean_ui_applies_to_every_page_of_both_clusters(): void
    {
        foreach ([
            new ListCustomers(), new ListFilmProducts(), new ListStores(), new ListVehicles(), new MasterDataCenter(),
            new ListProductInquiries(), new ListPartnershipInquiries(), new ListNews(), new ListCarousels(), new ListNotificationCampaigns(),
            new SendNotification(), new LeadFunnelReport(),
        ] as $page) {
            $this->assertTrue(CleanUi::appliesTo($page), $page::class);
            $this->assertFalse(CleanUi::isReportPage($page), $page::class . ' tidak boleh mendapat gaya tabel laporan.');
        }
    }

    public function test_filters_are_folded_above_the_list_on_master_data_and_marketing_screens(): void
    {
        $this->admin();

        foreach ([ListCustomers::class, ListStores::class, ListProductInquiries::class, ListNews::class] as $list) {
            $table = Livewire::test($list)->assertSuccessful()->instance()->getTable();

            $this->assertSame(FiltersLayout::AboveContentCollapsible, $table->getFiltersLayout(), $list);
        }
    }

    public function test_lead_and_request_queues_open_on_all_rows_and_have_status_tabs(): void
    {
        $this->admin();

        foreach ([
            ListProductInquiries::class => ['semua', 'baru', 'dihubungi', 'selesai'],
            ListPartnershipInquiries::class => ['semua', 'baru', 'dihubungi', 'deal', 'ditolak'],
            ListRewardRedemptions::class => ['semua', 'menunggu', 'dikirim', 'batal'],
            ListPartners::class => ['semua', 'aktif', 'nonaktif'],
        ] as $list => $keys) {
            $page = Livewire::test($list)->assertSuccessful()->instance();

            $this->assertSame('semua', $page->getDefaultActiveTab(), $list);
            $this->assertSame($keys, array_keys($page->getTabs()), $list);
        }
    }
}
