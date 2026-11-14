<?php

namespace Tests\Feature;

use App\Exports\NotificationCampaignExport;
use App\Filament\Pages\NotificationCatalogPage;
use App\Filament\Pages\SendNotification;
use App\Filament\Resources\NotificationCampaignResource\Pages\ListNotificationCampaigns;
use App\Filament\Resources\NotificationDeliveryResource\Pages\ListNotificationDeliveries;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\CustomerNotification;
use App\Models\DeviceToken;
use App\Models\NotificationCampaign;
use App\Models\NotificationDelivery;
use App\Models\Store;
use App\Models\User;
use App\Services\NotificationCampaignService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Audit menu Notifikasi: segmen penerima, pratinjau yang tidak basi, kirim uji, duplikat kampanye, kolom/filter Log Pengiriman,
 * "Terakhir terkirim" di Katalog, dan filter jenis pesan di API riwayat pelanggan.
 */
class NotificationAuditBatchTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config([
            'marketing.promo_max_campaigns_per_week' => 5,
            'marketing.promo_quiet_hours' => ['enabled' => false, 'start' => '21:00', 'end' => '08:00', 'timezone' => 'Asia/Jakarta'],
        ]);
        Http::fake(['exp.host/*' => fn (Request $request) => Http::response([
            'data' => collect($request->data())->map(fn () => ['status' => 'ok', 'id' => 'T-' . uniqid()])->all(),
        ])]);
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    private function admin(): User
    {
        $user = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]);
        $user->assignRole('super_admin');
        $this->actingAs($user, 'web');

        return $user;
    }

    private function customer(string $name, array $extra = [], ?string $token = null): Customer
    {
        $customer = Customer::create(array_merge(['name' => $name, 'email' => strtolower($name) . '@test.local', 'phone_number' => '8' . random_int(100000000, 999999999), 'promo_opt_in' => true], $extra));

        if ($token) {
            DeviceToken::create(['customer_id' => $customer->id, 'token' => $token, 'platform' => 'android']);
        }

        return $customer;
    }

    private function booking(Customer $customer, ?string $createdAt = null): Booking
    {
        $booking = Booking::create([
            'booking_number' => 'BKG-T-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $this->store->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-09', 'status' => 'completed',
        ]);

        if ($createdAt) {
            Booking::whereKey($booking->id)->update(['created_at' => $createdAt]);
        }

        return $booking;
    }

    public function test_segment_resolves_by_group_booking_history_and_inactivity(): void
    {
        $group = CustomerGroup::create(['name' => 'Korporat']);
        $inGroup = $this->customer('Anggota', ['customer_group_id' => $group->id]);
        $outside = $this->customer('Luar');
        $recent = $this->customer('Baru');
        $this->booking($recent, now()->subDays(10)->toDateTimeString());
        $lapsed = $this->customer('Lama');
        $this->booking($lapsed, now()->subMonths(8)->toDateTimeString());
        $never = $this->customer('Belum');
        $service = app(NotificationCampaignService::class);

        $this->assertSame([$inGroup->id], $service->resolveSegment(['group_ids' => [$group->id]]));
        $this->assertEqualsCanonicalizing([$recent->id, $lapsed->id], $service->resolveSegment(['booking' => 'has']));
        $this->assertContains($never->id, $service->resolveSegment(['booking' => 'never']));
        $this->assertNotContains($lapsed->id, $service->resolveSegment(['booking' => 'never']));
        $this->assertSame([$lapsed->id], $service->resolveSegment(['inactive_months' => 6]), 'Hanya yang pernah booking tapi tidak dalam 6 bulan terakhir.');
        $this->assertNotContains($outside->id, $service->resolveSegment(['inactive_months' => 6]));
    }

    public function test_sending_to_a_segment_records_the_criteria_and_reaches_only_matching_customers(): void
    {
        $this->admin();
        $group = CustomerGroup::create(['name' => 'Member']);
        $this->customer('Anggota', ['customer_group_id' => $group->id], 'ExponentPushToken[a]');
        $this->customer('Luar', [], 'ExponentPushToken[b]');

        Livewire::test(SendNotification::class)
            ->fillForm([
                'audience' => 'customer', 'category' => 'promo', 'title' => 'Promo Member', 'body' => 'Diskon', 'broadcast' => false,
                'target_mode' => 'segment', 'segment_group_ids' => [$group->id], 'segment_booking' => 'any',
            ])
            ->call('send');

        $campaign = NotificationCampaign::firstOrFail();
        $this->assertSame(NotificationCampaign::SENT, $campaign->status);
        $this->assertSame([$group->id], $campaign->segment['group_ids']);
        $this->assertSame(1, $campaign->recipients);
        $this->assertSame(1, $campaign->devices_sent);
        $this->assertStringContainsString('Member', NotificationCampaignService::segmentLabel($campaign->segment));
    }

    public function test_an_empty_segment_fails_with_a_clear_reason_and_the_preview_warns(): void
    {
        $this->admin();
        $group = CustomerGroup::create(['name' => 'Kosong']);
        $state = ['audience' => 'customer', 'category' => 'info', 'title' => 'Tes', 'body' => 'Isi', 'broadcast' => false, 'target_mode' => 'segment', 'segment_group_ids' => [$group->id]];

        $preview = app(NotificationCampaignService::class)->preview($state);
        $this->assertSame(0, $preview['recipients']);
        $this->assertTrue($preview['blocked']);

        Livewire::test(SendNotification::class)->fillForm($state)->call('send');

        $campaign = NotificationCampaign::firstOrFail();
        $this->assertSame(NotificationCampaign::FAILED, $campaign->status);
        $this->assertStringContainsString('segmen', $campaign->message);
    }

    public function test_a_segment_without_any_criteria_is_blocked_in_the_preview(): void
    {
        $preview = app(NotificationCampaignService::class)->preview(['audience' => 'customer', 'category' => 'info', 'broadcast' => false, 'target_mode' => 'segment']);

        $this->assertTrue($preview['blocked']);
        $this->assertSame(0, $preview['recipients']);
    }

    public function test_the_preview_disappears_when_the_target_or_category_changes(): void
    {
        $this->admin();
        $this->customer('Ya', [], 'ExponentPushToken[a]');

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'category' => 'promo', 'title' => 'T', 'body' => 'B', 'broadcast' => true])
            ->call('previewRecipients')
            ->assertSet('preview.recipients', 1)
            ->set('data.category', 'info')
            ->assertSet('preview', null);
    }

    public function test_test_send_goes_only_to_the_logged_in_users_own_devices(): void
    {
        $admin = $this->admin();

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'category' => 'promo', 'title' => 'Promo', 'body' => 'Isi', 'broadcast' => true])
            ->callAction('testSend');
        Http::assertNothingSent();

        DeviceToken::create(['user_id' => $admin->id, 'token' => 'ExponentPushToken[staff]', 'platform' => 'android']);
        $this->customer('Pelanggan', [], 'ExponentPushToken[cust]');

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'category' => 'promo', 'title' => 'Promo', 'body' => 'Isi', 'broadcast' => true])
            ->callAction('testSend');

        Http::assertSent(function (Request $request) {
            $tokens = collect($request->data())->pluck('to')->all();

            return $tokens === ['ExponentPushToken[staff]'] && str_starts_with((string) collect($request->data())->first()['title'], '[UJI]');
        });
        $this->assertSame(0, NotificationCampaign::count(), 'Kirim uji tidak membuat kampanye.');
        $this->assertSame(0, CustomerNotification::count());
    }

    public function test_duplicating_a_campaign_prefills_the_form_including_its_segment(): void
    {
        $this->admin();
        $campaign = NotificationCampaign::create([
            'audience' => 'customer', 'category' => 'promo', 'title' => 'Promo Lama', 'body' => 'Isi lama', 'status' => NotificationCampaign::SENT,
            'segment' => ['group_ids' => [3], 'booking' => 'has', 'inactive_months' => 6],
        ]);

        Livewire::withQueryParams(['dari' => $campaign->id])
            ->test(SendNotification::class)
            ->assertFormSet(['title' => 'Promo Lama', 'body' => 'Isi lama', 'category' => 'promo', 'broadcast' => false, 'target_mode' => 'segment', 'segment_inactive_months' => 6, 'segment_booking' => 'has']);

        Livewire::test(ListNotificationCampaigns::class)
            ->assertTableActionHasUrl('duplicate', SendNotification::getUrl(['dari' => $campaign->id]), $campaign);
    }

    public function test_deliveries_can_be_filtered_by_campaign_and_searched_by_recipient(): void
    {
        $this->admin();
        $a = NotificationCampaign::create(['audience' => 'customer', 'category' => 'info', 'title' => 'A', 'body' => 'x', 'status' => NotificationCampaign::SENT]);
        $b = NotificationCampaign::create(['audience' => 'customer', 'category' => 'info', 'title' => 'B', 'body' => 'x', 'status' => NotificationCampaign::SENT]);
        $budi = $this->customer('Budi');
        $siti = $this->customer('Siti');
        $one = NotificationDelivery::create(['customer_id' => $budi->id, 'campaign_id' => $a->id, 'token' => 't1', 'title' => 'A', 'status' => NotificationDelivery::DELIVERED, 'sent_at' => now()]);
        $two = NotificationDelivery::create(['customer_id' => $siti->id, 'campaign_id' => $b->id, 'token' => 't2', 'title' => 'B', 'status' => NotificationDelivery::DELIVERED, 'sent_at' => now()]);

        Livewire::test(ListNotificationDeliveries::class)
            ->filterTable('campaign_id', $a->id)
            ->assertCanSeeTableRecords([$one])
            ->assertCanNotSeeTableRecords([$two]);

        Livewire::test(ListNotificationDeliveries::class)
            ->searchTable('Siti')
            ->assertCanSeeTableRecords([$two])
            ->assertCanNotSeeTableRecords([$one]);
    }

    public function test_catalog_shows_when_a_notification_title_was_last_sent(): void
    {
        $this->admin();
        NotificationDelivery::create(['token' => 't', 'title' => 'Booking Dikonfirmasi', 'status' => NotificationDelivery::DELIVERED, 'sent_at' => now()->subDays(3)]);
        NotificationDelivery::create(['token' => 't', 'title' => 'Judul Lama', 'status' => NotificationDelivery::DELIVERED, 'sent_at' => now()->subDays(200)]);

        $lastSent = (new NotificationCatalogPage())->getLastSent();

        $this->assertArrayHasKey('Booking Dikonfirmasi', $lastSent);
        $this->assertArrayNotHasKey('Judul Lama', $lastSent, 'Hanya 90 hari terakhir.');
    }

    public function test_history_api_filters_by_category(): void
    {
        $customer = $this->customer('Api');
        CustomerNotification::create(['customer_id' => $customer->id, 'title' => 'Promo', 'body' => 'x', 'category' => 'promo']);
        CustomerNotification::create(['customer_id' => $customer->id, 'title' => 'Info', 'body' => 'x', 'category' => 'info']);
        $this->actingAs($customer, 'customer');

        $this->assertCount(2, $this->getJson('/api/customer/notifications')->assertOk()->json('data'));
        $promo = $this->getJson('/api/customer/notifications?category=promo')->assertOk()->json('data');
        $this->assertCount(1, $promo);
        $this->assertSame('Promo', $promo[0]['title']);
        $this->assertCount(2, $this->getJson('/api/customer/notifications?category=asal')->assertOk()->json('data'), 'Nilai tak dikenal diabaikan.');
    }

    public function test_campaign_export_summarises_target_and_results(): void
    {
        $campaign = NotificationCampaign::create([
            'audience' => 'customer', 'category' => 'promo', 'title' => 'Promo', 'body' => 'x', 'status' => NotificationCampaign::SENT,
            'segment' => ['booking' => 'never'], 'recipients' => 4, 'sent_at' => now(),
        ]);
        $export = new NotificationCampaignExport();
        $row = $export->map($export->query()->first());

        $this->assertSame($campaign->title, $row[1]);
        $this->assertSame('Promosi', $row[3]);
        $this->assertStringStartsWith('Segmen: belum pernah booking', $row[4]);
        $this->assertSame(4, $row[6]);
    }
}
