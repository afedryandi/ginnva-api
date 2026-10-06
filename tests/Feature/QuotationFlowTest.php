<?php

namespace Tests\Feature;

use App\Mail\NewQuotationMail;
use App\Mail\QuotationReceivedMail;
use App\Models\FilmProduct;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lead Quotation: form publik (web/app), notifikasi, daftar & status di
 * mobile staff (scoping toko + akses menu), contacted_at, pengingat telat.
 */
class QuotationFlowTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;
    private Vehicle $vehicle;
    private FilmProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. Test 2', 'name' => 'Toko B', 'is_active' => true]);
        $this->vehicle = Vehicle::create(['brand' => 'Toyota', 'model' => 'Raize', 'size_category' => 'M']);
        $this->product = FilmProduct::create([
            'sku' => 'WF-Q1', 'name' => 'Produk Q1', 'product_type' => 'window_film',
            'position' => 'front', 'base_price' => 100_000, 'is_active' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'vehicle_id'     => $this->vehicle->id,
            'customer_name'  => 'Budi',
            'customer_phone' => '081234567890',
            'items'          => [['film_product_id' => $this->product->id]],
        ], $overrides);
    }

    private function staff(string $role, ?int $storeId, ?array $menuAccess = null): User
    {
        $user = User::create([
            'name' => ucfirst($role) . ' ' . uniqid(),
            'email' => uniqid() . '@test.local',
            'password' => 'x',
            'store_id' => $storeId,
            'menu_access' => $menuAccess,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function lead(array $overrides = []): Quotation
    {
        return Quotation::create(array_merge([
            'quotation_number' => 'INQ-TEST-' . uniqid(),
            'vehicle_id'       => $this->vehicle->id,
            'customer_name'    => 'Budi',
            'customer_phone'   => '081234567890',
            'status'           => 'new',
            'source'           => 'customer',
            'store_id'         => $this->store->id,
        ], $overrides));
    }

    public function test_options_returns_brands_vehicles_and_products(): void
    {
        $this->getJson('/api/quotation/options')
            ->assertSuccessful()
            ->assertJsonStructure(['data' => ['brands', 'vehicles', 'products']]);

        // Migrasi sudah menanam data kendaraan, jadi cek keberadaan (bukan urutan).
        $response = $this->getJson('/api/quotation/options');
        $this->assertContains('Toyota', $response->json('data.brands'));
        $this->assertTrue(collect($response->json('data.vehicles'))->contains(fn ($v) => $v['model'] === 'Raize'));
        $this->assertTrue(collect($response->json('data.products'))->contains(fn ($p) => $p['name'] === 'Produk Q1'));
    }

    public function test_submit_without_email_or_store_creates_lead_and_notifies_full_access(): void
    {
        Mail::fake();
        $admin = $this->staff('super_admin', null);

        $response = $this->postJson('/api/quotation/submit', $this->payload())->assertStatus(201);

        $number = $response->json('data.quotation_number');
        $this->assertStringStartsWith('INQ-', $number);

        $quotation = Quotation::where('quotation_number', $number)->firstOrFail();
        $this->assertSame('new', $quotation->status);
        $this->assertSame('customer', $quotation->source);
        $this->assertNull($quotation->store_id);
        $this->assertCount(1, $quotation->items);

        // Tanpa email: customer tidak dikirimi apa pun; admin dapat email lead baru.
        Mail::assertNotSent(QuotationReceivedMail::class);
        Mail::assertSent(NewQuotationMail::class, fn ($m) => $m->hasTo($admin->email));
    }

    public function test_submit_with_email_sends_confirmation_to_customer(): void
    {
        Mail::fake();
        $this->staff('super_admin', null);

        $this->postJson('/api/quotation/submit', $this->payload(['customer_email' => 'budi@example.com']))
            ->assertStatus(201);

        Mail::assertSent(QuotationReceivedMail::class, fn ($m) => $m->hasTo('budi@example.com'));
    }

    public function test_submit_validates_required_fields(): void
    {
        $this->postJson('/api/quotation/submit', $this->payload(['customer_phone' => '']))->assertStatus(422);
        $this->postJson('/api/quotation/submit', $this->payload(['items' => []]))->assertStatus(422);
        $this->postJson('/api/quotation/submit', $this->payload(['vehicle_id' => 999999]))->assertStatus(422);
        $this->postJson('/api/quotation/submit', $this->payload(['customer_email' => 'bukan-email']))->assertStatus(422);
        $this->assertSame(0, Quotation::count());
    }

    public function test_same_email_is_limited_to_three_requests_per_day(): void
    {
        Mail::fake();
        foreach (range(1, 3) as $i) {
            $this->lead(['customer_email' => 'spam@example.com']);
        }

        $this->postJson('/api/quotation/submit', $this->payload(['customer_email' => 'spam@example.com']))
            ->assertStatus(429);
        $this->assertSame(3, Quotation::count());
    }

    public function test_staff_list_is_scoped_to_own_store_plus_unassigned_leads(): void
    {
        $own = $this->lead();
        $unassigned = $this->lead(['store_id' => null]);
        $other = $this->lead(['store_id' => $this->otherStore->id]);

        $ids = collect(
            $this->actingAs($this->staff('kasir', $this->store->id), 'api')
                ->getJson('/api/staff/quotations')
                ->assertSuccessful()
                ->json('data')
        )->pluck('id');

        $this->assertTrue($ids->contains($own->id));
        $this->assertTrue($ids->contains($unassigned->id));
        $this->assertFalse($ids->contains($other->id));

        $all = collect(
            $this->actingAs($this->staff('super_admin', null), 'api')->getJson('/api/staff/quotations')->json('data')
        )->pluck('id');
        $this->assertTrue($all->contains($other->id));
    }

    public function test_staff_without_quotation_menu_access_is_forbidden(): void
    {
        $lead = $this->lead();
        $noAccess = $this->staff('kasir', $this->store->id, ['SomeOtherResource']);

        $this->actingAs($noAccess, 'api')->getJson('/api/staff/quotations')->assertStatus(403);
        $this->actingAs($noAccess, 'api')->getJson("/api/staff/quotations/{$lead->id}")->assertStatus(403);
        $this->actingAs($noAccess, 'api')
            ->patchJson("/api/staff/quotations/{$lead->id}/status", ['status' => 'contacted'])
            ->assertStatus(403);
    }

    public function test_staff_cannot_open_or_update_lead_of_another_store(): void
    {
        $other = $this->lead(['store_id' => $this->otherStore->id]);
        $kasir = $this->staff('kasir', $this->store->id);

        $this->actingAs($kasir, 'api')->getJson("/api/staff/quotations/{$other->id}")->assertStatus(403);
        $this->actingAs($kasir, 'api')
            ->patchJson("/api/staff/quotations/{$other->id}/status", ['status' => 'contacted'])
            ->assertStatus(403);
        $this->assertSame('new', $other->fresh()->status);
    }

    public function test_first_status_change_sets_contacted_at_and_is_not_overwritten(): void
    {
        $lead = $this->lead();
        $kasir = $this->staff('kasir', $this->store->id);

        $this->actingAs($kasir, 'api')
            ->patchJson("/api/staff/quotations/{$lead->id}/status", ['status' => 'contacted'])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'contacted');

        $first = $lead->fresh()->contacted_at;
        $this->assertNotNull($first);

        $this->travel(2)->hours();

        $this->actingAs($kasir, 'api')
            ->patchJson("/api/staff/quotations/{$lead->id}/status", ['status' => 'closed'])
            ->assertSuccessful();

        $this->assertTrue($lead->fresh()->contacted_at->equalTo($first), 'contacted_at hanya mencatat respons PERTAMA.');
    }

    public function test_invalid_status_is_rejected(): void
    {
        $lead = $this->lead();

        $this->actingAs($this->staff('kasir', $this->store->id), 'api')
            ->patchJson("/api/staff/quotations/{$lead->id}/status", ['status' => 'bukan-status'])
            ->assertStatus(422);
    }

    public function test_stale_command_alerts_only_leads_new_for_more_than_24_hours(): void
    {
        $admin = $this->staff('super_admin', null);

        // Lead baru (1 jam): tidak dianggap telat.
        $fresh = $this->lead();
        $this->artisan('quotations:notify-stale')->assertSuccessful();
        $this->assertSame(0, $admin->notifications()->count());

        // Lead 25 jam masih New: dianggap telat. Yang sudah dihubungi tidak.
        $stale = $this->lead();
        DB::table('quotations')->where('id', $stale->id)->update(['created_at' => now()->subHours(25)]);
        $contacted = $this->lead(['status' => 'contacted']);
        DB::table('quotations')->where('id', $contacted->id)->update(['created_at' => now()->subHours(30)]);

        $this->artisan('quotations:notify-stale')->assertSuccessful();

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertStringContainsString('1 lead', $admin->notifications()->first()->data['body']);
        $this->assertNotNull($fresh->fresh());
    }

    public function test_lead_without_store_pushes_to_full_access_and_quotation_staff_only(): void
    {
        Mail::fake();
        $admin = $this->staff('super_admin', null);
        $kasirWithAccess = $this->staff('kasir', $this->store->id);
        $kasirNoAccess = $this->staff('kasir', $this->store->id, ['SomeOtherResource']);
        $inactive = $this->staff('super_admin', null);
        $inactive->update(['is_active' => false]);

        $ids = app(\App\Services\PushNotificationService::class)->unassignedQuotationRecipientIds();

        $this->assertTrue($ids->contains($admin->id));
        $this->assertTrue($ids->contains($kasirWithAccess->id));
        $this->assertFalse($ids->contains($kasirNoAccess->id));
        $this->assertFalse($ids->contains($inactive->id));
    }

    public function test_new_lead_without_store_triggers_push_dispatch(): void
    {
        Mail::fake();
        $admin = $this->staff('super_admin', null);
        \App\Models\DeviceToken::create(['user_id' => $admin->id, 'token' => 'ExponentPushToken[test-admin]']);

        $this->postJson('/api/quotation/submit', $this->payload())->assertStatus(201);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'exp.host')
            && str_contains(json_encode($request->data()), 'Lead Baru'));
    }

    public function test_stale_lead_without_store_is_pushed_too(): void
    {
        $admin = $this->staff('super_admin', null);
        \App\Models\DeviceToken::create(['user_id' => $admin->id, 'token' => 'ExponentPushToken[test-admin]']);

        $stale = $this->lead(['store_id' => null]);
        DB::table('quotations')->where('id', $stale->id)->update(['created_at' => now()->subHours(25)]);
        Http::fake(); // reset catatan request dari pembuatan lead

        $this->artisan('quotations:notify-stale')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'exp.host')
            && str_contains(json_encode($request->data()), 'Lead Belum Di-follow-up'));
    }
}
