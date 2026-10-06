<?php

namespace Tests\Feature;

use App\Filament\Resources\WarrantyResource\Pages\CreateWarranty;
use App\Filament\Resources\WarrantyResource\Pages\EditWarranty;
use App\Filament\Resources\WarrantyResource\Pages\ListWarranties;
use App\Filament\Resources\WarrantyResource\Pages\ViewWarranty;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\PointTransaction;
use App\Models\ScrollCode;
use App\Models\Store;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Garansi: pendaftaran (staf), kode otomatis, review QA (poin 100 sekali),
 * klaim kepemilikan, cek publik (masking & pembatasan percobaan), unduh PDF
 * (hanya approved & tidak revoked), garansi saya & klaim, pengingat berakhir,
 * dan halaman/aksi Filament.
 */
class WarrantyFlowTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->seed(RolePermissionSeeder::class);
        Role::findOrCreate('kasir', 'web');

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    private function staff(string $role): User
    {
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id]);
        $user->assignRole($role);

        return $user;
    }

    private function customer(): Customer
    {
        return Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999), 'email' => uniqid() . '@mail.test']);
    }

    private function warranty(array $overrides = []): Warranty
    {
        return Warranty::create(array_merge([
            'customer_name'     => 'Budi Santoso',
            'phone_number'      => '081234567890',
            'car_plate'         => 'B 1234 XYZ',
            'car_type'          => 'Toyota Raize',
            'product_series'    => 'Ginnva PPF Pro',
            'product_category'  => 'ppf',
            'installation_date' => now()->subDays(10)->toDateString(),
            'expiry_date'       => now()->addYears(5)->toDateString(),
            'dealer_name'       => 'Toko A',
            'store_id'          => $this->store->id,
            'status'            => 'active',
            'review_status'     => 'approved',
        ], $overrides));
    }

    // ------------------------------------------------------------- pendaftaran

    public function test_only_staff_with_manage_permission_can_register_a_warranty(): void
    {
        $payload = [
            'customer_name' => 'Budi', 'phone_number' => '081234567890', 'car_plate' => 'B 1 A', 'car_type' => 'Raize',
            'product_series' => 'PPF Pro', 'installation_date' => now()->toDateString(),
            'expiry_date' => now()->addYears(3)->toDateString(), 'dealer_name' => 'Toko A',
        ];

        $this->postJson('/api/warranty/submit', $payload)->assertStatus(401);
        $this->actingAs($this->staff('kasir'), 'api')->postJson('/api/warranty/submit', $payload)->assertStatus(403);

        $response = $this->actingAs($this->staff('super_admin'), 'api')->postJson('/api/warranty/submit', $payload)->assertStatus(201);

        $warranty = Warranty::findOrFail($response->json('data.id'));
        $this->assertSame('pending_review', $warranty->review_status);
        $this->assertSame('pending_review', $warranty->status, 'Selama belum di-review, status tampil pending_review.');
        $this->assertNull($warranty->warranty_code, 'Kode baru dibuat saat kode gulungan dipilih.');
    }

    public function test_registration_validation(): void
    {
        $admin = $this->staff('super_admin');

        $this->actingAs($admin, 'api')->postJson('/api/warranty/submit', ['customer_name' => 'Budi'])->assertStatus(422);
        $this->actingAs($admin, 'api')->postJson('/api/warranty/submit', [
            'customer_name' => 'Budi', 'phone_number' => '0812', 'car_plate' => 'B 1', 'car_type' => 'X', 'product_series' => 'Y',
            'installation_date' => now()->toDateString(), 'expiry_date' => now()->subDay()->toDateString(), 'dealer_name' => 'T',
        ])->assertStatus(422);
    }

    public function test_warranty_code_is_generated_when_roll_is_chosen_and_roll_usage_counted(): void
    {
        ScrollCode::create(['code' => 'SC-PPF-1', 'status' => 'allocated', 'total_length_meters' => 15]);
        ScrollCode::create(['code' => 'SC-WF-F', 'status' => 'allocated', 'total_length_meters' => 15]);

        $ppf = $this->warranty(['warranty_code' => null, 'review_status' => 'pending_review', 'roll_number' => 'SC-PPF-1']);
        $this->assertMatchesRegularExpression('/^GNV-PPF-\d{5}$/', $ppf->warranty_code);
        $this->assertSame(1, ScrollCode::where('code', 'SC-PPF-1')->value('usage_count'));

        $wf = $this->warranty(['warranty_code' => null, 'product_category' => 'window_film', 'review_status' => 'pending_review', 'roll_number_front' => 'SC-WF-F']);
        $this->assertMatchesRegularExpression('/^GNV-WF-\d{5}$/', $wf->warranty_code);
    }

    public function test_status_reflects_expiry_and_revocation(): void
    {
        $this->assertSame('active', $this->warranty()->status);
        $this->assertSame('expired', $this->warranty(['expiry_date' => now()->subDay()->toDateString()])->status);
        $this->assertSame('revoked', $this->warranty(['status' => 'revoked'])->status);
        $this->assertSame('rejected', $this->warranty(['review_status' => 'rejected'])->status);
    }

    // ------------------------------------------------------------- review QA & poin

    public function test_approval_awards_points_once_and_notifies_the_owner(): void
    {
        $customer = $this->customer();
        $warranty = $this->warranty(['review_status' => 'pending_review', 'customer_id' => $customer->id, 'warranty_code' => 'GNV-PPF-00001']);

        $warranty->update(['review_status' => 'approved']);

        $this->assertSame(100, (int) $customer->fresh()->loyalty_points);
        $this->assertSame(1, PointTransaction::where('reference_type', 'warranty')->where('reference_id', $warranty->id)->count());
        $this->assertTrue(CustomerNotification::where('customer_id', $customer->id)->where('title', 'Garansi Disetujui')->exists());

        // Perubahan lain & set ulang approved tidak memberi poin lagi.
        $warranty->update(['dealer_name' => 'Toko A (pindah)']);
        $warranty->update(['review_status' => 'pending_review']);
        $warranty->update(['review_status' => 'approved']);
        $this->assertSame(100, (int) $customer->fresh()->loyalty_points);
    }

    public function test_rejection_notifies_without_points(): void
    {
        $customer = $this->customer();
        $warranty = $this->warranty(['review_status' => 'pending_review', 'customer_id' => $customer->id, 'warranty_code' => 'GNV-PPF-00002']);

        $warranty->update(['review_status' => 'rejected', 'rejection_reason' => 'Foto tidak jelas']);

        $this->assertSame(0, (int) $customer->fresh()->loyalty_points);
        $notification = CustomerNotification::where('customer_id', $customer->id)->where('title', 'Garansi Ditolak')->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('Foto tidak jelas', $notification->body);
    }

    // ------------------------------------------------------------- klaim kepemilikan

    public function test_customer_claims_unowned_warranty_and_gets_points_if_already_approved(): void
    {
        $customer = $this->customer();
        $warranty = $this->warranty(['warranty_code' => 'GNV-PPF-00003']);

        $this->postJson('/api/warranty/claim', ['warranty_code' => 'GNV-PPF-00003'])->assertStatus(401);

        $this->actingAs($customer, 'customer')->postJson('/api/warranty/claim', ['warranty_code' => 'GNV-PPF-00003'])
            ->assertSuccessful()->assertJsonPath('points_awarded', 100);

        $this->assertSame($customer->id, $warranty->fresh()->customer_id);
        $this->assertSame(100, (int) $customer->fresh()->loyalty_points);

        // Sudah dimiliki: akun lain ditolak; kode tak dikenal 404.
        $other = $this->customer();
        $this->actingAs($other, 'customer')->postJson('/api/warranty/claim', ['warranty_code' => 'GNV-PPF-00003'])->assertStatus(409);
        $this->actingAs($other, 'customer')->postJson('/api/warranty/claim', ['warranty_code' => 'GNV-PPF-99999'])->assertStatus(404);
    }

    // ------------------------------------------------------------- cek publik

    public function test_public_check_by_code_and_plate_is_unmasked_without_private_fields(): void
    {
        $warranty = $this->warranty(['warranty_code' => 'GNV-PPF-00004', 'customer_id' => $this->customer()->id]);

        $byCode = $this->getJson('/api/warranty/check?code=GNV-PPF-00004')->assertSuccessful();
        $this->assertSame('Budi Santoso', $byCode->json('data.0.customer_name'));
        $this->assertFalse($byCode->json('data.0.masked'));
        $this->assertTrue($byCode->json('data.0.has_owner'));
        $this->assertArrayNotHasKey('customer_id', $byCode->json('data.0'));
        $this->assertArrayNotHasKey('roll_number', $byCode->json('data.0'));

        $this->getJson('/api/warranty/check?code=' . urlencode('B 1234 XYZ'))->assertSuccessful()->assertJsonPath('data.0.id', $warranty->id);
    }

    public function test_public_check_by_phone_number_is_masked_and_hides_the_code(): void
    {
        $this->warranty(['warranty_code' => 'GNV-PPF-00005']);

        $response = $this->getJson('/api/warranty/check?code=081234567890')->assertSuccessful();

        $this->assertTrue($response->json('data.0.masked'));
        $this->assertSame('Budi S.', $response->json('data.0.customer_name'));
        $this->assertStringNotContainsString('00005', $response->json('data.0.warranty_code'));
    }

    public function test_public_check_returns_all_warranties_of_one_vehicle(): void
    {
        $this->warranty(['warranty_code' => 'GNV-PPF-00006']);
        $this->warranty(['warranty_code' => 'GNV-WF-00006', 'product_category' => 'window_film']);

        $this->assertCount(2, $this->getJson('/api/warranty/check?code=' . urlencode('B 1234 XYZ'))->json('data'));
    }

    public function test_public_check_validates_and_limits_failed_lookups(): void
    {
        $this->getJson('/api/warranty/check')->assertStatus(422);

        RateLimiter::clear('warranty-failed-lookup:127.0.0.1');
        foreach (range(1, 15) as $i) {
            $this->getJson('/api/warranty/check?code=TIDAK-ADA-' . $i)->assertStatus(404);
        }

        $this->getJson('/api/warranty/check?code=TIDAK-ADA-16')->assertStatus(429);
    }

    // ------------------------------------------------------------- unduh PDF

    public function test_pdf_download_only_for_approved_and_not_revoked(): void
    {
        $approved = $this->warranty(['warranty_code' => 'GNV-PPF-00007']);
        $pending = $this->warranty(['warranty_code' => 'GNV-PPF-00008', 'review_status' => 'pending_review']);
        $revoked = $this->warranty(['warranty_code' => 'GNV-PPF-00009', 'status' => 'revoked']);

        $response = $this->get('/api/warranty/download/GNV-PPF-00007')->assertSuccessful();
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $this->getJson('/api/warranty/download/GNV-PPF-00008')->assertStatus(403);
        $this->getJson('/api/warranty/download/GNV-PPF-00009')->assertStatus(403);
        $this->getJson('/api/warranty/download/GNV-PPF-NOPE')->assertStatus(404);
        $this->assertNotNull($approved);
        $this->assertNotNull($pending);
        $this->assertNotNull($revoked);
    }

    // ------------------------------------------------------------- Garansi Saya & klaim

    public function test_my_warranties_lists_and_shows_only_own(): void
    {
        $me = $this->customer();
        $other = $this->customer();
        $mine = $this->warranty(['warranty_code' => 'GNV-PPF-00010', 'customer_id' => $me->id]);
        $theirs = $this->warranty(['warranty_code' => 'GNV-PPF-00011', 'customer_id' => $other->id]);

        $ids = collect($this->actingAs($me, 'customer')->getJson('/api/customer/warranties')->assertSuccessful()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));

        $this->actingAs($me, 'customer')->getJson("/api/customer/warranties/{$mine->id}")
            ->assertSuccessful()->assertJsonPath('data.warranty_code', 'GNV-PPF-00010');
        $this->actingAs($me, 'customer')->getJson("/api/customer/warranties/{$theirs->id}")->assertStatus(404);
    }

    public function test_customer_files_a_claim_that_becomes_a_pending_booking(): void
    {
        $me = $this->customer();
        $warranty = $this->warranty(['warranty_code' => 'GNV-PPF-00012', 'customer_id' => $me->id]);

        $response = $this->actingAs($me, 'customer')->postJson("/api/customer/warranties/{$warranty->id}/claims", [
            'category' => 'product_warranty', 'description' => 'PPF mengelupas di pintu', 'preferred_date' => now()->addDays(3)->toDateString(),
        ])->assertStatus(201);

        $claim = WarrantyClaim::where('warranty_id', $warranty->id)->firstOrFail();
        $this->assertStringStartsWith('CLM-', $claim->claim_number);
        $this->assertSame($claim->claim_number, $response->json('data.claim_number'));

        $booking = Booking::where('warranty_claim_id', $claim->id)->firstOrFail();
        $this->assertSame('Klaim Garansi', $booking->service_type);
        $this->assertSame('pending', $booking->status);
        $this->assertSame($me->id, $booking->customer_id);
    }

    public function test_claim_rules(): void
    {
        $me = $this->customer();
        $future = now()->addDays(3)->toDateString();
        $claim = fn (Warranty $w, array $o = []) => $this->actingAs($me, 'customer')->postJson("/api/customer/warranties/{$w->id}/claims", array_merge([
            'category' => 'product_warranty', 'preferred_date' => $future,
        ], $o));

        $pending = $this->warranty(['warranty_code' => 'GNV-PPF-00013', 'customer_id' => $me->id, 'review_status' => 'pending_review']);
        $expired = $this->warranty(['warranty_code' => 'GNV-PPF-00014', 'customer_id' => $me->id, 'expiry_date' => now()->subDay()->toDateString()]);
        $noStore = $this->warranty(['warranty_code' => 'GNV-PPF-00015', 'customer_id' => $me->id, 'store_id' => null]);
        $ok = $this->warranty(['warranty_code' => 'GNV-PPF-00016', 'customer_id' => $me->id]);

        $claim($pending)->assertStatus(422);
        $claim($expired)->assertStatus(422);
        $claim($noStore)->assertStatus(422);
        $claim($ok, ['category' => 'bukan-kategori'])->assertStatus(422);
        $claim($ok, ['preferred_date' => now()->subDay()->toDateString()])->assertStatus(422);

        $this->store->update(['opening_hours' => [['days' => [strtolower(now()->addDays(3)->format('D'))], 'closed' => true]]]);
        $claim($ok)->assertStatus(422); // toko tutup di tanggal itu

        $other = $this->customer();
        $this->actingAs($other, 'customer')->postJson("/api/customer/warranties/{$ok->id}/claims", ['category' => 'other', 'preferred_date' => $future])->assertStatus(404);
        $this->assertSame(0, WarrantyClaim::count());
    }

    // ------------------------------------------------------------- pengingat berakhir

    public function test_expiry_command_alerts_staff_and_customers_at_checkpoints_only_for_approved(): void
    {
        $admin = $this->staff('super_admin');
        $me = $this->customer();
        $this->warranty(['warranty_code' => 'GNV-PPF-00020', 'customer_id' => $me->id, 'expiry_date' => now()->addDays(30)->toDateString()]);
        $this->warranty(['warranty_code' => 'GNV-PPF-00021', 'customer_id' => $me->id, 'expiry_date' => now()->addDays(7)->toDateString()]);
        $this->warranty(['warranty_code' => 'GNV-PPF-00022', 'customer_id' => $me->id, 'expiry_date' => now()->addDays(7)->toDateString(), 'review_status' => 'pending_review']);
        $this->warranty(['warranty_code' => 'GNV-PPF-00023', 'customer_id' => $me->id, 'expiry_date' => now()->addDays(15)->toDateString()]);

        $this->artisan('warranty:notify-expiring')->assertSuccessful();

        $bodies = CustomerNotification::where('customer_id', $me->id)->where('title', 'Garansi Akan Berakhir')->pluck('body')->all();
        $this->assertCount(2, $bodies, 'Hanya checkpoint 30 dan 7 hari milik garansi approved.');
        $this->assertTrue(collect($bodies)->contains(fn ($b) => str_contains($b, 'GNV-PPF-00020') && str_contains($b, '30 hari')));
        $this->assertTrue(collect($bodies)->contains(fn ($b) => str_contains($b, 'GNV-PPF-00021') && str_contains($b, '7 hari')));
        $this->assertSame(1, $admin->notifications()->count());
    }

    // ------------------------------------------------------------- Filament

    public function test_filament_pages_and_review_actions_work(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = $this->staff('super_admin');
        $this->actingAs($admin, 'web');

        $customer = $this->customer();
        $pending = $this->warranty(['warranty_code' => 'GNV-PPF-00030', 'review_status' => 'pending_review', 'customer_id' => $customer->id]);
        $toReject = $this->warranty(['warranty_code' => 'GNV-PPF-00031', 'review_status' => 'pending_review', 'customer_id' => $customer->id]);
        $approved = $this->warranty(['warranty_code' => 'GNV-PPF-00032']);

        Livewire::test(ListWarranties::class)->assertSuccessful()->assertCanSeeTableRecords([$pending, $approved]);
        Livewire::test(ViewWarranty::class, ['record' => $approved->getKey()])->assertSuccessful();
        Livewire::test(EditWarranty::class, ['record' => $approved->getKey()])->assertSuccessful();
        Livewire::test(CreateWarranty::class)->assertSuccessful();

        Livewire::test(ListWarranties::class)->callTableAction('approve', $pending);
        $this->assertSame('approved', $pending->fresh()->review_status);
        $this->assertSame(100, (int) $customer->fresh()->loyalty_points);

        Livewire::test(ListWarranties::class)->callTableAction('reject', $toReject, data: ['rejection_reason' => 'Data tidak lengkap']);
        $this->assertSame('rejected', $toReject->fresh()->review_status);

        Livewire::test(ListWarranties::class)->callTableAction('revoke', $approved, data: ['revoke_reason' => 'Salah input']);
        $this->assertSame('revoked', $approved->fresh()->status);
    }
}
