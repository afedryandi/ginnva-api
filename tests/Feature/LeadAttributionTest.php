<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductInquiryResource\Pages\CreateProductInquiry;
use App\Filament\Resources\ProductInquiryResource\Pages\ListProductInquiries;
use App\Models\FilmProduct;
use App\Models\PartnershipInquiry;
use App\Models\ProductInquiry;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Marketing/Konten Fase 0 "Ukur" (2026-10-22): setiap lead menyimpan sumbernya (kanal, UTM, click id, halaman asal, perujuk), data klien
 * disaring, dan lead manual dicatat staf lewat API dan panel tanpa memicu notifikasi "inquiry baru".
 */
class LeadAttributionTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;
    private FilmProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');

        $this->vehicle = Vehicle::create(['brand' => 'Toyota', 'model' => 'Raize', 'size_category' => 'M']);
        $this->product = FilmProduct::create([
            'sku' => 'WF-A1', 'name' => 'Produk A1', 'product_type' => 'window_film',
            'position' => 'front', 'base_price' => 100_000, 'is_active' => true,
        ]);
    }

    private function attribution(array $over = []): array
    {
        return ['attribution' => array_merge([
            'utm_source' => 'instagram',
            'utm_medium' => 'paid',
            'utm_campaign' => 'ppf-h10',
            'utm_content' => 'image-1',
            'click_id' => 'IwAR0abc_123-xyz',
            'landing_path' => '/ppf',
            'referrer' => 'https://l.instagram.com/',
        ], $over)];
    }

    private function staff(string $role, ?array $menuAccess = null): User
    {
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'menu_access' => $menuAccess, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    // ------------------------------------------------------------- lead publik menyimpan sumber

    public function test_product_inquiry_from_the_web_stores_the_attribution(): void
    {
        $this->postJson('/api/inquiry/submit', ['customer_name' => 'Budi', 'customer_contact' => '0812', 'message' => 'Tanya'] + $this->attribution())->assertStatus(201);

        $inquiry = ProductInquiry::firstOrFail();
        $this->assertSame('web_form', $inquiry->channel);
        $this->assertSame('ppf-h10', $inquiry->utm_campaign);
        $this->assertSame('instagram', $inquiry->utm_source);
        $this->assertSame('paid', $inquiry->utm_medium);
        $this->assertSame('image-1', $inquiry->utm_content);
        $this->assertSame('IwAR0abc_123-xyz', $inquiry->click_id);
        $this->assertSame('/ppf', $inquiry->landing_path);
        $this->assertSame('https://l.instagram.com/', $inquiry->referrer);
    }

    public function test_the_mobile_app_is_recorded_as_the_app_channel(): void
    {
        $this->withHeaders(['X-App-Platform' => 'android'])
            ->postJson('/api/inquiry/submit', ['customer_name' => 'Budi', 'customer_contact' => '0812'])->assertStatus(201);

        $this->assertSame('app', ProductInquiry::firstOrFail()->channel);
    }

    public function test_quotation_and_partnership_leads_store_the_attribution_too(): void
    {
        $this->postJson('/api/quotation/submit', [
            'vehicle_id' => $this->vehicle->id, 'customer_name' => 'Budi', 'customer_phone' => '081234567890',
            'items' => [['film_product_id' => $this->product->id]],
        ] + $this->attribution(['utm_campaign' => 'kaca-film-promo']))->assertStatus(201);

        $this->postJson('/api/partnership/submit', [
            'applicant_name' => 'Sinta', 'phone_number' => '0813', 'email' => 'sinta@example.com', 'city' => 'Bandung',
        ] + $this->attribution(['utm_campaign' => 'franchise-q4']))->assertStatus(201);

        $q = Quotation::firstOrFail();
        $this->assertSame('web_form', $q->channel);
        $this->assertSame('kaca-film-promo', $q->utm_campaign);

        $p = PartnershipInquiry::firstOrFail();
        $this->assertSame('web_form', $p->channel);
        $this->assertSame('franchise-q4', $p->utm_campaign);
    }

    public function test_leads_without_attribution_still_work_and_only_get_a_channel(): void
    {
        $this->postJson('/api/inquiry/submit', ['customer_name' => 'Budi', 'customer_contact' => '0812'])->assertStatus(201);

        $inquiry = ProductInquiry::firstOrFail();
        $this->assertSame('web_form', $inquiry->channel);
        $this->assertNull($inquiry->utm_campaign);
        $this->assertNull($inquiry->click_id);
    }

    // ------------------------------------------------------------- penyaringan data klien

    public function test_client_values_are_cleaned_truncated_and_unknown_keys_ignored(): void
    {
        $payload = ['customer_name' => 'Budi', 'customer_contact' => '0812', 'attribution' => [
            'utm_campaign' => str_repeat('x', 400),
            'utm_source' => "insta\ngram\r\n",
            'utm_medium' => ['array', 'bukan string'],
            'click_id' => 'abc<script>123&;"',
            'landing_path' => '/ppf?email=budi@example.com&token=rahasia#bagian',
            'referrer' => 'https://contoh.id/halaman?q=pribadi',
            'is_admin' => true,
            'channel' => 'walk_in',
        ]];

        $this->postJson('/api/inquiry/submit', $payload)->assertStatus(201);

        $i = ProductInquiry::firstOrFail();
        $this->assertSame(150, mb_strlen($i->utm_campaign));
        $this->assertSame('insta gram', $i->utm_source);
        $this->assertNull($i->utm_medium, 'Bukan string: dibuang.');
        $this->assertSame('abcscript123', $i->click_id);
        $this->assertSame('/ppf', $i->landing_path, 'Query dan fragmen dibuang.');
        $this->assertSame('https://contoh.id/halaman', $i->referrer);
        $this->assertSame('web_form', $i->channel, 'Kanal ditentukan server, bukan klien.');
    }

    // ------------------------------------------------------------- lead manual oleh staf

    public function test_staff_can_log_a_whatsapp_lead_through_the_api(): void
    {
        $staff = $this->staff('kasir');

        $response = $this->actingAs($staff, 'api')->postJson('/api/staff/leads', [
            'customer_name' => 'Rina', 'customer_contact' => '0812999', 'message' => 'Tanya PPF full body',
            'channel' => 'whatsapp', 'interest' => 'ppf', 'campaign' => 'ppf-h10',
        ])->assertStatus(201);

        $inquiry = ProductInquiry::where('inquiry_number', $response->json('data.inquiry_number'))->firstOrFail();
        $this->assertSame('whatsapp', $inquiry->channel);
        $this->assertSame('ppf', $inquiry->interest);
        $this->assertSame('ppf-h10', $inquiry->utm_campaign);
        $this->assertSame($staff->id, $inquiry->created_by_user_id);
        $this->assertSame('new', $inquiry->status);
    }

    public function test_the_staff_lead_endpoint_validates_the_channel_and_interest(): void
    {
        $staff = $this->staff('kasir');
        $base = ['customer_name' => 'Rina', 'customer_contact' => '0812999'];

        $this->actingAs($staff, 'api')->postJson('/api/staff/leads', $base)->assertStatus(422);
        $this->actingAs($staff, 'api')->postJson('/api/staff/leads', $base + ['channel' => 'web_form'])->assertStatus(422);
        $this->actingAs($staff, 'api')->postJson('/api/staff/leads', $base + ['channel' => 'whatsapp', 'interest' => 'mobil-baru'])->assertStatus(422);
        $this->assertSame(0, ProductInquiry::count());
    }

    public function test_staff_without_the_menu_cannot_log_leads_and_guests_are_rejected(): void
    {
        $noMenu = $this->staff('kasir', ['SomeOtherResource']);

        $this->actingAs($noMenu, 'api')->postJson('/api/staff/leads', ['customer_name' => 'R', 'customer_contact' => '1', 'channel' => 'whatsapp'])->assertStatus(403);
        $this->postJson('/api/staff/leads', ['customer_name' => 'R', 'customer_contact' => '1', 'channel' => 'whatsapp'])->assertStatus(401);
    }

    public function test_manual_leads_do_not_trigger_the_new_inquiry_notification_but_public_ones_do(): void
    {
        $admin = $this->staff('super_admin');
        $staff = $this->staff('kasir');

        $this->actingAs($staff, 'api')->postJson('/api/staff/leads', ['customer_name' => 'Rina', 'customer_contact' => '0812', 'channel' => 'telepon'])->assertStatus(201);
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $admin->id)->count(), 'Lead manual tidak memberi tahu orang lain.');

        $this->postJson('/api/inquiry/submit', ['customer_name' => 'Budi', 'customer_contact' => '0813'])->assertStatus(201);
        $this->assertGreaterThan(0, DB::table('notifications')->where('notifiable_id', $admin->id)->count(), 'Lead publik tetap memberi tahu.');
    }

    // ------------------------------------------------------------- panel

    public function test_the_panel_can_log_a_manual_lead_and_filter_by_channel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = $this->staff('super_admin');
        $this->actingAs($admin, 'web');

        Livewire::test(CreateProductInquiry::class)
            ->fillForm(['customer_name' => 'Dewi', 'customer_contact' => '0812777', 'channel' => 'walk_in', 'interest' => 'detailing', 'message' => 'Datang ke toko'])
            ->call('create')
            ->assertHasNoFormErrors();

        $manual = ProductInquiry::where('customer_name', 'Dewi')->firstOrFail();
        $this->assertSame('walk_in', $manual->channel);
        $this->assertSame($admin->id, $manual->created_by_user_id);

        $web = ProductInquiry::create(['customer_name' => 'Web', 'customer_contact' => '1', 'status' => 'new', 'channel' => 'web_form']);

        Livewire::test(ListProductInquiries::class)
            ->assertCanSeeTableRecords([$manual, $web])
            ->filterTable('channel', 'walk_in')
            ->assertCanSeeTableRecords([$manual])
            ->assertCanNotSeeTableRecords([$web]);
    }

    public function test_the_manual_lead_form_requires_the_channel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->staff('super_admin'), 'web');

        Livewire::test(CreateProductInquiry::class)
            ->fillForm(['customer_name' => 'Dewi', 'customer_contact' => '0812777'])
            ->call('create')
            ->assertHasFormErrors(['channel' => 'required']);
    }
}
