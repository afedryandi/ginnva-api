<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\FilmProduct;
use App\Models\ScrollCode;
use App\Models\ScrollCodeUsage;
use App\Models\Spk;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SPK (Surat Perintah Kerja) lewat endpoint mobile staff: pembuatan dari
 * booking confirmed, 1 booking = 1 SPK, scoping toko/installer, update
 * (checklist & titik kerusakan), dan gate lacak roll saat SPK ditandai selesai.
 */
class SpkApiFlowTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'installer', 'partner'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. Test 2', 'name' => 'Toko B', 'is_active' => true]);
    }

    private function staff(string $role, ?Store $store = null): User
    {
        $user = User::create([
            'name' => ucfirst($role) . ' ' . uniqid(),
            'email' => uniqid() . '@test.local',
            'password' => 'x',
            'store_id' => ($store ?? $this->store)->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function booking(array $overrides = [], ?Store $store = null): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);

        return Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id'    => $customer->id,
            'store_id'       => ($store ?? $this->store)->id,
            'service_type'   => 'Pelindung Cat (PPF)',
            'product_ppf'    => true,
            'preferred_date' => now()->addDays(3)->toDateString(),
            'status'         => 'confirmed',
        ], $overrides));
    }

    private function payload(Booking $booking, array $overrides = []): array
    {
        return array_merge([
            'booking_id'      => $booking->id,
            'customer_name'   => 'Budi',
            'vehicle_plate'   => 'B 1234 XYZ',
            'vehicle_type'    => 'suv',
            'checklist_items' => [
                ['category' => 'pekerjaan', 'label' => 'PPF', 'is_checked' => true],
                ['category' => 'perlengkapan', 'label' => 'STNK', 'is_checked' => true],
            ],
            'damage_marks'    => [
                ['x_percent' => 20.5, 'y_percent' => 40, 'code' => 'B', 'note' => 'Baret pintu kiri'],
            ],
        ], $overrides);
    }

    private function createSpk(Booking $booking, ?User $by = null): Spk
    {
        $this->actingAs($by ?? $this->staff('kasir'), 'api')
            ->postJson('/api/staff/spks', $this->payload($booking))
            ->assertStatus(201);

        return Spk::where('booking_id', $booking->id)->firstOrFail();
    }

    public function test_checklist_template_is_available(): void
    {
        $this->actingAs($this->staff('kasir'), 'api')
            ->getJson('/api/staff/spks/checklist-template')
            ->assertSuccessful()
            ->assertJsonStructure(['data' => ['pekerjaan', 'extra_service', 'perlengkapan']]);
    }

    public function test_staff_creates_spk_for_confirmed_booking_with_checklist_and_damage_marks(): void
    {
        $booking = $this->booking();

        $response = $this->actingAs($this->staff('kasir'), 'api')
            ->postJson('/api/staff/spks', $this->payload($booking))
            ->assertStatus(201);

        $spk = Spk::findOrFail($response->json('data.id'));
        $this->assertStringStartsWith("SPK/{$this->store->id}/", $spk->spk_number);
        $this->assertSame($this->store->id, $spk->store_id);
        $this->assertSame('B 1234 XYZ', $spk->vehicle_plate);
        $this->assertCount(2, $spk->checklistItems);
        $this->assertCount(1, $spk->damageMarks);
        $this->assertSame('B', $spk->damageMarks->first()->code);
    }

    public function test_spk_cannot_be_created_for_pending_booking_or_twice(): void
    {
        $kasir = $this->staff('kasir');
        $pending = $this->booking(['status' => 'pending']);

        $this->actingAs($kasir, 'api')->postJson('/api/staff/spks', $this->payload($pending))->assertStatus(422);

        $booking = $this->booking();
        $this->actingAs($kasir, 'api')->postJson('/api/staff/spks', $this->payload($booking))->assertStatus(201);
        $this->actingAs($kasir, 'api')->postJson('/api/staff/spks', $this->payload($booking))->assertStatus(422);

        $this->assertSame(1, Spk::where('booking_id', $booking->id)->count());
    }

    public function test_spk_validation_and_partner_access(): void
    {
        $booking = $this->booking();
        $kasir = $this->staff('kasir');

        $this->actingAs($kasir, 'api')->postJson('/api/staff/spks', $this->payload($booking, ['customer_name' => '']))->assertStatus(422);
        $this->actingAs($kasir, 'api')->postJson('/api/staff/spks', $this->payload($booking, ['vehicle_type' => 'truk']))->assertStatus(422);
        $this->actingAs($kasir, 'api')->postJson('/api/staff/spks', $this->payload($booking, [
            'damage_marks' => [['x_percent' => 120, 'y_percent' => 10, 'code' => 'B']],
        ]))->assertStatus(422);

        $this->actingAs($this->staff('partner'), 'api')->getJson('/api/staff/spks')->assertStatus(403);
        $this->actingAs($this->staff('partner'), 'api')->postJson('/api/staff/spks', $this->payload($booking))->assertStatus(403);
    }

    public function test_store_staff_cannot_create_or_open_spk_of_another_store(): void
    {
        $otherBooking = $this->booking([], $this->otherStore);
        $kasirA = $this->staff('kasir');

        // Booking toko lain tidak terlihat sama sekali oleh staf toko ini (scope toko),
        // jadi ditolak sebagai "tidak valid" tanpa membocorkan keberadaannya.
        $this->actingAs($kasirA, 'api')->postJson('/api/staff/spks', $this->payload($otherBooking))->assertStatus(422);
        $this->assertSame(0, Spk::where('booking_id', $otherBooking->id)->count());

        $otherSpk = $this->createSpk($otherBooking, $this->staff('kasir', $this->otherStore));
        $this->actingAs($kasirA, 'api')->getJson("/api/staff/spks/{$otherSpk->id}")->assertStatus(404);
        $this->actingAs($kasirA, 'api')->putJson("/api/staff/spks/{$otherSpk->id}", ['customer_name' => 'X'])->assertStatus(404);

        $ids = collect($this->actingAs($kasirA, 'api')->getJson('/api/staff/spks')->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($otherSpk->id));
    }

    public function test_installer_only_sees_and_edits_spk_of_assigned_booking(): void
    {
        $installer = $this->staff('installer');
        $mine = $this->booking();
        $mine->installers()->attach($installer->id);
        $notMine = $this->booking();

        $kasir = $this->staff('kasir');
        $mineSpk = $this->createSpk($mine, $kasir);
        $notMineSpk = $this->createSpk($notMine, $kasir);

        $ids = collect($this->actingAs($installer, 'api')->getJson('/api/staff/spks')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mineSpk->id));
        $this->assertFalse($ids->contains($notMineSpk->id));

        $this->actingAs($installer, 'api')->getJson("/api/staff/spks/{$notMineSpk->id}")->assertStatus(404);
        $this->actingAs($installer, 'api')->putJson("/api/staff/spks/{$notMineSpk->id}/damage-marks", ['damage_marks' => []])->assertStatus(404);
        $this->actingAs($installer, 'api')->putJson("/api/staff/spks/{$mineSpk->id}", ['customer_name' => 'Budi Baru'])->assertSuccessful();

        // Installer tidak boleh membuat SPK untuk booking yang bukan miliknya.
        $another = $this->booking();
        $this->actingAs($installer, 'api')->postJson('/api/staff/spks', $this->payload($another))->assertStatus(403);
    }

    public function test_update_replaces_checklist_and_only_touches_damage_marks_when_sent(): void
    {
        $spk = $this->createSpk($this->booking());
        $kasir = $this->staff('kasir');

        // Tanpa kunci damage_marks: titik kerusakan lama tetap ada.
        $this->actingAs($kasir, 'api')->putJson("/api/staff/spks/{$spk->id}", [
            'customer_name'   => 'Budi Revisi',
            'checklist_items' => [['category' => 'pekerjaan', 'label' => 'Kaca Film', 'is_checked' => true]],
        ])->assertSuccessful();

        $fresh = $spk->fresh(['checklistItems', 'damageMarks']);
        $this->assertSame('Budi Revisi', $fresh->customer_name);
        $this->assertCount(1, $fresh->checklistItems);
        $this->assertSame('Kaca Film', $fresh->checklistItems->first()->label);
        $this->assertCount(1, $fresh->damageMarks);

        // Dengan damage_marks kosong: titik dihapus.
        $this->actingAs($kasir, 'api')->putJson("/api/staff/spks/{$spk->id}", [
            'customer_name' => 'Budi Revisi',
            'damage_marks'  => [],
        ])->assertSuccessful();
        $this->assertCount(0, $spk->fresh('damageMarks')->damageMarks);
    }

    public function test_damage_marks_endpoint_validates_and_saves(): void
    {
        $spk = $this->createSpk($this->booking());
        $kasir = $this->staff('kasir');

        $this->actingAs($kasir, 'api')->putJson("/api/staff/spks/{$spk->id}/damage-marks", [
            'damage_marks' => [['x_percent' => 10, 'y_percent' => 10, 'code' => 'ZZ']],
        ])->assertStatus(422);

        $this->actingAs($kasir, 'api')->putJson("/api/staff/spks/{$spk->id}/damage-marks", [
            'damage_marks' => [
                ['x_percent' => 10, 'y_percent' => 20, 'code' => 'P'],
                ['x_percent' => 60, 'y_percent' => 70, 'code' => 'OS', 'note' => 'Over spray bumper'],
            ],
        ])->assertSuccessful();

        $this->assertCount(2, $spk->fresh('damageMarks')->damageMarks);
    }

    public function test_spk_with_batch_tracked_product_cannot_be_completed_without_roll_usage(): void
    {
        $product = FilmProduct::create([
            'sku' => 'PPF-TRACK', 'name' => 'PPF Lacak Roll', 'product_type' => 'ppf',
            'position' => 'all', 'base_price' => 1_000_000, 'is_active' => true, 'tracks_batch' => true,
        ]);
        $booking = $this->booking(['film_product_id' => $product->id]);
        $spk = $this->createSpk($booking);
        $kasir = $this->staff('kasir');

        $complete = ['customer_name' => 'Budi', 'checked_out_at' => now()->toDateTimeString()];

        $this->actingAs($kasir, 'api')->putJson("/api/staff/spks/{$spk->id}", $complete)->assertStatus(422);
        $this->assertNull($spk->fresh()->checked_out_at);

        $scrollCode = ScrollCode::create([
            'code' => 'SC-TEST-' . uniqid(), 'status' => 'allocated', 'total_length_meters' => 15, 'purchase_cost' => 1_500_000,
        ]);
        ScrollCodeUsage::create(['scroll_code_id' => $scrollCode->id, 'booking_id' => $booking->id, 'meters' => 3]);

        $this->actingAs($kasir, 'api')->putJson("/api/staff/spks/{$spk->id}", $complete)->assertSuccessful();
        $this->assertNotNull($spk->fresh()->checked_out_at);
    }

    public function test_spk_without_batch_tracked_product_can_be_completed(): void
    {
        $spk = $this->createSpk($this->booking());

        $this->actingAs($this->staff('kasir'), 'api')
            ->putJson("/api/staff/spks/{$spk->id}", ['customer_name' => 'Budi', 'checked_out_at' => now()->toDateTimeString()])
            ->assertSuccessful();

        $this->assertNotNull($spk->fresh()->checked_out_at);
    }

    public function test_spk_numbers_are_unique_and_sequential_per_store_per_day(): void
    {
        $kasir = $this->staff('kasir');
        $numbers = [];
        foreach (range(1, 3) as $i) {
            $numbers[] = $this->createSpk($this->booking(), $kasir)->spk_number;
        }

        $this->assertCount(3, array_unique($numbers));
        $this->assertStringEndsWith('0001', $numbers[0]);
        $this->assertStringEndsWith('0003', $numbers[2]);
    }

    public function test_staff_without_spk_menu_access_is_forbidden_but_installer_is_not(): void
    {
        $booking = $this->booking();
        $spk = $this->createSpk($booking);

        $noSpkMenu = User::create([
            'name' => 'Tanpa Menu SPK', 'email' => uniqid() . '@test.local', 'password' => 'x',
            'store_id' => $this->store->id, 'menu_access' => ['SomeOtherResource'],
        ]);
        $noSpkMenu->assignRole('kasir');

        $this->actingAs($noSpkMenu, 'api')->getJson('/api/staff/spks')->assertStatus(403);
        $this->actingAs($noSpkMenu, 'api')->getJson("/api/staff/spks/{$spk->id}")->assertStatus(403);
        $this->actingAs($noSpkMenu, 'api')->postJson('/api/staff/spks', $this->payload($this->booking()))->assertStatus(403);

        // Installer yang ditugaskan tetap bisa, walau menu_access dibatasi.
        $installer = User::create([
            'name' => 'Installer', 'email' => uniqid() . '@test.local', 'password' => 'x',
            'store_id' => $this->store->id, 'menu_access' => ['SomeOtherResource'],
        ]);
        $installer->assignRole('installer');
        $booking->installers()->attach($installer->id);

        $this->actingAs($installer, 'api')->getJson("/api/staff/spks/{$spk->id}")->assertSuccessful();
    }
}
