<?php

namespace Tests\Feature;

use App\Filament\Pages\SendNotification;
use App\Filament\Resources\NotificationCampaignResource;
use App\Filament\Resources\NotificationCampaignResource\Pages\ListNotificationCampaigns;
use App\Filament\Resources\NotificationTemplateResource\Pages\ListNotificationTemplates;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\DeviceToken;
use App\Models\NotificationCampaign;
use App\Models\NotificationDelivery;
use App\Models\NotificationTemplate;
use App\Models\Partner;
use App\Models\User;
use App\Services\NotificationCampaignService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Notifikasi Fase 5 (2026-10-21): kampanye tercatat dengan hasilnya, kirim terjadwal, pratinjau penerima, templat.
 */
class NotificationCampaignTest extends TestCase
{
    use RefreshDatabase;

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
    }

    private function admin(): User
    {
        $user = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]);
        $user->assignRole('super_admin');
        $this->actingAs($user, 'web');

        return $user;
    }

    private function customer(string $name, bool $optIn, ?string $token = null): Customer
    {
        $customer = Customer::create(['name' => $name, 'email' => strtolower($name) . '@test.local', 'phone_number' => '0812' . random_int(10000000, 99999999), 'promo_opt_in' => $optIn]);

        if ($token) {
            DeviceToken::create(['customer_id' => $customer->id, 'token' => $token, 'platform' => 'android']);
        }

        return $customer;
    }

    private function wib(string $dateTime): Carbon
    {
        return Carbon::parse($dateTime, 'Asia/Jakarta');
    }

    private function infoState(array $extra = []): array
    {
        return array_merge(['audience' => 'customer', 'category' => 'info', 'title' => 'Toko tutup', 'body' => 'Libur nasional', 'broadcast' => true], $extra);
    }

    // ------------------------------------------------------------- kampanye tercatat

    public function test_an_immediate_send_is_recorded_as_a_campaign_with_its_result_and_linked_deliveries(): void
    {
        $this->admin();
        $this->customer('Ya', true, 'ExponentPushToken[a]');
        $this->customer('Tidak', false, 'ExponentPushToken[b]');

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'category' => 'promo', 'title' => 'Promo', 'body' => 'Diskon', 'broadcast' => true])
            ->call('send');

        $campaign = NotificationCampaign::firstOrFail();
        $this->assertSame(NotificationCampaign::SENT, $campaign->status);
        $this->assertSame('promo', $campaign->category);
        $this->assertSame(1, $campaign->recipients);
        $this->assertSame(1, $campaign->devices_sent);
        $this->assertNotNull($campaign->campaign_key);
        $this->assertNotNull($campaign->sent_at);
        $this->assertSame(1, NotificationDelivery::where('campaign_id', $campaign->id)->count(), 'Catatan pengiriman tertaut ke kampanye.');
        $this->assertNull(\App\Services\PushNotificationService::$deliveryCampaignId, 'Konteks kampanye dibersihkan setelah kirim.');
    }

    public function test_a_refused_send_is_recorded_as_a_failed_campaign_with_the_reason(): void
    {
        config(['marketing.promo_quiet_hours.enabled' => true]);
        $this->travelTo($this->wib('2026-11-02 23:00'));
        $this->admin();
        $this->customer('Ya', true, 'ExponentPushToken[a]');

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'category' => 'promo', 'title' => 'Promo', 'body' => 'Diskon', 'broadcast' => true])
            ->call('send')
            ->assertNotified('Gagal mengirim notifikasi');

        $campaign = NotificationCampaign::firstOrFail();
        $this->assertSame(NotificationCampaign::FAILED, $campaign->status);
        $this->assertStringContainsString('jam tenang', $campaign->message);
        Http::assertNothingSent();
    }

    public function test_the_campaign_list_shows_receipt_results_per_campaign(): void
    {
        $this->admin();
        $campaign = NotificationCampaign::create(['audience' => 'customer', 'category' => 'info', 'title' => 'Info', 'body' => 'Isi', 'status' => 'sent', 'sent_at' => now()]);
        foreach (['delivered', 'delivered', 'accepted', 'dead_token'] as $i => $status) {
            NotificationDelivery::create(['campaign_id' => $campaign->id, 'token' => "t{$i}", 'title' => 'Info', 'status' => $status, 'sent_at' => now()]);
        }

        Livewire::test(ListNotificationCampaigns::class)->assertSuccessful()->assertCanSeeTableRecords([$campaign]);

        $row = NotificationCampaignResource::getEloquentQuery()->findOrFail($campaign->id);
        $this->assertSame([2, 1, 1], [$row->delivered_count, $row->waiting_count, $row->problem_count]);
    }

    // ------------------------------------------------------------- terjadwal

    public function test_scheduling_creates_a_scheduled_campaign_and_sends_nothing_yet(): void
    {
        $this->travelTo($this->wib('2026-11-02 09:00'));
        $admin = $this->admin();
        $this->customer('Ya', true, 'ExponentPushToken[a]');

        Livewire::test(SendNotification::class)
            ->fillForm($this->infoState(['schedule' => true, 'scheduled_at' => '2026-11-02 12:00:00']))
            ->call('send')
            ->assertNotified('Kampanye dijadwalkan');

        $campaign = NotificationCampaign::firstOrFail();
        $this->assertSame(NotificationCampaign::SCHEDULED, $campaign->status);
        $this->assertTrue($campaign->scheduled_at->equalTo($this->wib('2026-11-02 12:00')), 'Waktu diisi dalam WIB.');
        $this->assertSame($admin->id, $campaign->created_by);
        $this->assertSame(0, CustomerNotification::count());
        Http::assertNothingSent();
    }

    public function test_a_schedule_too_close_or_for_a_promo_at_night_is_refused(): void
    {
        config(['marketing.promo_quiet_hours.enabled' => true]);
        $this->travelTo($this->wib('2026-11-02 09:00'));
        $this->admin();

        Livewire::test(SendNotification::class)
            ->fillForm($this->infoState(['schedule' => true, 'scheduled_at' => '2026-11-02 09:01:00']))
            ->call('send')
            ->assertNotified('Waktu kirim terlalu dekat');

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'category' => 'promo', 'title' => 'Promo', 'body' => 'Diskon', 'broadcast' => true, 'schedule' => true, 'scheduled_at' => '2026-11-02 22:00:00'])
            ->call('send')
            ->assertNotified('Waktu kirim jatuh pada jam tenang');

        // Informasi boleh dijadwalkan malam hari.
        Livewire::test(SendNotification::class)
            ->fillForm($this->infoState(['schedule' => true, 'scheduled_at' => '2026-11-02 22:00:00']))
            ->call('send')
            ->assertNotified('Kampanye dijadwalkan');

        $this->assertSame(1, NotificationCampaign::count());
    }

    public function test_the_scheduler_sends_only_due_campaigns_once_and_attributes_them_to_the_creator(): void
    {
        $admin = $this->admin();
        $this->customer('Ya', true, 'ExponentPushToken[a]');
        $service = app(NotificationCampaignService::class);
        $due = $service->create($this->infoState(), now()->subMinute(), $admin->id);
        $later = $service->create($this->infoState(['title' => 'Nanti']), now()->addHour(), $admin->id);

        $this->artisan('notifications:send-scheduled')->assertSuccessful();
        $this->artisan('notifications:send-scheduled')->assertSuccessful();

        $this->assertSame(NotificationCampaign::SENT, $due->fresh()->status);
        $this->assertSame(NotificationCampaign::SCHEDULED, $later->fresh()->status);
        $this->assertSame(1, CustomerNotification::where('title', 'Toko tutup')->count(), 'Satu kali kirim walau perintah dijalankan dua kali.');
        $this->assertSame($admin->id, CustomerNotification::where('title', 'Toko tutup')->value('sent_by'));
        Http::assertSentCount(1);
    }

    public function test_a_scheduled_promo_that_became_blocked_is_marked_failed(): void
    {
        $admin = $this->admin();
        $this->customer('Ya', true, 'ExponentPushToken[a]');
        $campaign = app(NotificationCampaignService::class)->create(
            ['audience' => 'customer', 'category' => 'promo', 'title' => 'Promo', 'body' => 'Diskon', 'broadcast' => true],
            now()->subMinute(),
            $admin->id
        );
        config(['marketing.promo_quiet_hours' => ['enabled' => true, 'start' => '00:00', 'end' => '23:59', 'timezone' => 'Asia/Jakarta']]);

        $this->artisan('notifications:send-scheduled')->assertSuccessful();

        $this->assertSame(NotificationCampaign::FAILED, $campaign->fresh()->status);
        $this->assertStringContainsString('jam tenang', $campaign->fresh()->message);
        Http::assertNothingSent();
    }

    public function test_a_campaign_stuck_in_sending_is_marked_failed(): void
    {
        $stuck = NotificationCampaign::create(['audience' => 'customer', 'category' => 'info', 'title' => 'Macet', 'body' => 'Isi', 'status' => NotificationCampaign::SENDING]);
        \Illuminate\Support\Facades\DB::table('notification_campaigns')->where('id', $stuck->id)->update(['updated_at' => now()->subHour()]);
        $fresh = NotificationCampaign::create(['audience' => 'customer', 'category' => 'info', 'title' => 'Baru', 'body' => 'Isi', 'status' => NotificationCampaign::SENDING]);

        $this->artisan('notifications:send-scheduled')->assertSuccessful();

        $this->assertSame(NotificationCampaign::FAILED, $stuck->fresh()->status);
        $this->assertSame(NotificationCampaign::SENDING, $fresh->fresh()->status);
    }

    public function test_a_scheduled_campaign_can_be_cancelled_and_is_then_never_sent(): void
    {
        $admin = $this->admin();
        $this->customer('Ya', true, 'ExponentPushToken[a]');
        $campaign = app(NotificationCampaignService::class)->create($this->infoState(), now()->subMinute(), $admin->id);

        Livewire::test(ListNotificationCampaigns::class)
            ->callTableAction('cancel', $campaign)
            ->assertNotified('Kampanye dibatalkan');

        $this->artisan('notifications:send-scheduled')->assertSuccessful();

        $this->assertSame(NotificationCampaign::CANCELLED, $campaign->fresh()->status);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------- pratinjau

    public function test_the_preview_counts_recipients_and_exclusions_without_sending(): void
    {
        $this->admin();
        $this->customer('Ya', true, 'ExponentPushToken[a]');
        $this->customer('Ya Dua', true, 'ExponentPushToken[b]');
        $this->customer('Tidak', false, 'ExponentPushToken[c]');

        $component = Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'category' => 'promo', 'title' => 'Promo', 'body' => 'Diskon', 'broadcast' => true])
            ->call('previewRecipients');

        $preview = $component->get('preview');
        $this->assertSame(2, $preview['recipients']);
        $this->assertSame(2, $preview['devices']);
        $this->assertSame(1, $preview['excluded']);
        $this->assertFalse($preview['blocked']);
        $component->assertSee('Pratinjau penerima');

        $this->assertSame(0, CustomerNotification::count());
        $this->assertSame(0, NotificationCampaign::count());
        Http::assertNothingSent();
    }

    public function test_the_preview_warns_about_quiet_hours_and_the_weekly_limit(): void
    {
        $this->admin();
        $this->customer('Ya', true, 'ExponentPushToken[a]');
        config(['marketing.promo_quiet_hours.enabled' => true, 'marketing.promo_max_campaigns_per_week' => 1]);
        $this->travelTo($this->wib('2026-11-02 22:00'));
        CustomerNotification::create(['customer_id' => null, 'title' => 'Lama', 'body' => 'x', 'category' => 'promo', 'campaign_key' => 'k1']);

        $preview = app(NotificationCampaignService::class)->preview(['audience' => 'customer', 'category' => 'promo', 'broadcast' => true]);

        $this->assertTrue($preview['blocked']);
        $this->assertCount(2, $preview['warnings']);
        $this->assertStringContainsString('jam tenang', $preview['warnings'][0]);
        $this->assertStringContainsString('Batas promosi tercapai', $preview['warnings'][1]);
    }

    public function test_the_preview_for_information_and_partners(): void
    {
        $this->admin();
        $this->customer('A', false, 'ExponentPushToken[a]');
        $this->customer('B', false);
        $partner = Partner::createAccount(['business_name' => 'Mitra', 'email' => uniqid() . '@example.com', 'password' => 'rahasia123']);
        DeviceToken::create(['user_id' => $partner->user_id, 'token' => 'ExponentPushToken[p]', 'platform' => 'android']);
        $service = app(NotificationCampaignService::class);

        $info = $service->preview(['audience' => 'customer', 'category' => 'info', 'broadcast' => true]);
        $this->assertSame([1, 1], [$info['recipients'], $info['devices']], 'Hanya pelanggan yang punya perangkat.');
        $this->assertNull($info['excluded']);

        $partners = $service->preview(['audience' => 'partner', 'broadcast_partner' => true]);
        $this->assertSame([1, 1], [$partners['recipients'], $partners['devices']]);
    }

    // ------------------------------------------------------------- templat

    public function test_a_message_can_be_saved_as_a_template_and_reused(): void
    {
        $admin = $this->admin();

        Livewire::test(SendNotification::class)
            ->fillForm($this->infoState(['title' => 'Libur Lebaran', 'body' => 'Toko tutup 3 hari', 'deep_link_route' => '/news']))
            ->callAction('saveTemplate', ['name' => 'Libur'])
            ->assertNotified('Template disimpan');

        $template = NotificationTemplate::firstOrFail();
        $this->assertSame(['Libur', 'customer', 'info', 'Libur Lebaran', $admin->id], [$template->name, $template->audience, $template->category, $template->title, $template->created_by]);
        $this->assertSame('/news', $template->data['deep_link_route']);

        Livewire::test(SendNotification::class)
            ->set('data.template_id', $template->id)
            ->assertSet('data.title', 'Libur Lebaran')
            ->assertSet('data.body', 'Toko tutup 3 hari')
            ->assertSet('data.category', 'info')
            ->assertSet('data.deep_link_route', '/news');
    }

    public function test_a_template_needs_a_title_and_a_message(): void
    {
        $this->admin();

        Livewire::test(SendNotification::class)
            ->callAction('saveTemplate', ['name' => 'Kosong'])
            ->assertNotified('Isi judul dan pesan dulu');

        $this->assertSame(0, NotificationTemplate::count());
    }

    public function test_templates_can_be_listed_and_deleted(): void
    {
        $this->admin();
        $template = NotificationTemplate::create(['name' => 'Libur', 'audience' => 'customer', 'category' => 'info', 'title' => 'T', 'body' => 'B']);

        Livewire::test(ListNotificationTemplates::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$template])
            ->callTableAction('delete', $template);

        $this->assertSame(0, NotificationTemplate::count());
    }
}
