<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ScrollCode;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * API mobile staf untuk inventaris PPF/WF (scan QR): akses lewat kotak menu "Barang", pencarian (nama / kode / kode gulungan,
 * petunjuk untuk kode gulungan yatim), detail + riwayat berhalaman, catat keluar/masuk (toko tujuan otomatis dari akun staf,
 * full-access wajib memilih toko, transisi tidak sah ditolak, status gulungan ikut sinkron), catat pemakaian meter (sisa
 * panjang, gulungan milik toko lain, link ke booking terkonfirmasi satu toko) dan tandai gulungan habis.
 */
class StaffInventoryApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->seed(RolePermissionSeeder::class);
        Role::findOrCreate('kasir', 'web');
        InventoryItem::query()->delete();
        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    private function staff(string $role = 'kasir', ?Store $store = null, ?array $menuAccess = null): User
    {
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->storeA)->id, 'menu_access' => $menuAccess, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function scroll(string $code, ?Store $store = null, string $status = 'allocated', float $total = 15, ?float $remaining = 15): ScrollCode
    {
        return ScrollCode::create(['code' => $code, 'store_id' => $store?->id, 'status' => $status, 'usage_count' => 0, 'total_length_meters' => $total, 'remaining_length_meters' => $remaining, 'allocated_at' => $status === 'unallocated' ? null : now()]);
    }

    private function item(string $code, string $name = 'Film PPF', ?ScrollCode $scroll = null, string $status = 'in_stock'): InventoryItem
    {
        return InventoryItem::create(['code' => $code, 'name' => $name, 'category' => 'PPF', 'status' => $status, 'scroll_code_id' => $scroll?->id]);
    }

    private function api(User $user)
    {
        return $this->actingAs($user, 'api');
    }

    private function booking(Store $store, string $status = 'confirmed'): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        return Booking::create(['booking_number' => 'BKG-T-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $store->id, 'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-09', 'status' => $status]);
    }

    // ------------------------------------------------------------- akses

    public function test_every_endpoint_needs_a_token_and_the_barang_menu(): void
    {
        $item = $this->item('INV-1');
        $noMenu = $this->staff('kasir', null, ['BookingResource']);
        $calls = [
            ['getJson', '/api/staff/inventory', []],
            ['getJson', '/api/staff/inventory/INV-1', []],
            ['getJson', '/api/staff/inventory/INV-1/movements', []],
            ['postJson', '/api/staff/inventory/INV-1/movement', ['type' => 'out']],
            ['postJson', '/api/staff/inventory/INV-1/record-usage', ['meters' => 1]],
            ['postJson', '/api/staff/inventory/INV-1/mark-scroll-code-used', []],
        ];

        foreach ($calls as [$method, $url, $payload]) {
            $this->{$method}($url, $payload)->assertStatus(401);
        }
        foreach ($calls as [$method, $url, $payload]) {
            $this->api($noMenu)->{$method}($url, $payload)->assertStatus(403);
        }

        $this->assertSame('in_stock', $item->fresh()->status);
    }

    // ------------------------------------------------------------- pencarian & detail

    public function test_search_finds_by_name_code_or_the_scroll_code(): void
    {
        $alpha = $this->item('INV-A', 'Film Alpha', $this->scroll('GLN-ALPHA'));
        $beta = $this->item('INV-B', 'Film Beta');
        $user = $this->staff();

        $names = fn (string $q) => collect($this->api($user)->getJson('/api/staff/inventory?search=' . $q)->assertSuccessful()->json('data'))->pluck('name')->all();

        $this->assertSame(['Film Alpha'], $names('Alpha'));
        $this->assertSame(['Film Beta'], $names('INV-B'));
        $this->assertSame(['Film Alpha'], $names('GLN-ALPHA'));
        $this->assertEqualsCanonicalizing(['Film Alpha', 'Film Beta'], $names(''));
    }

    public function test_an_unlinked_scroll_code_gets_a_helpful_hint(): void
    {
        $this->scroll('GLN-ORPHAN');
        $user = $this->staff();

        $response = $this->api($user)->getJson('/api/staff/inventory?search=GLN-ORPHAN')->assertSuccessful();

        $this->assertSame([], $response->json('data'));
        $this->assertStringContainsString('belum dikaitkan ke barang fisik', $response->json('hint'));
        $this->assertNull($this->api($user)->getJson('/api/staff/inventory?search=TIDAK-ADA')->json('hint'));
    }

    public function test_detail_returns_the_item_history_and_stores_only_for_full_access(): void
    {
        $scroll = $this->scroll('GLN-1', $this->storeA);
        $item = $this->item('INV-1', 'Film Alpha', $scroll);
        $item->recordMovement('out', $this->staff()->id, 'Kirim', $this->storeA->id);

        $staff = $this->api($this->staff())->getJson('/api/staff/inventory/INV-1')->assertSuccessful();
        $this->assertSame('Film Alpha', $staff->json('data.name'));
        $this->assertSame('GLN-1', $staff->json('data.scroll_code.code'));
        $this->assertCount(1, $staff->json('data.movements'));
        $this->assertSame([], $staff->json('stores'));
        $this->assertFalse($staff->json('movements_has_more'));

        $admin = $this->api($this->staff('super_admin'))->getJson('/api/staff/inventory/INV-1')->assertSuccessful();
        $this->assertCount(2, $admin->json('stores'));

        $this->api($this->staff())->getJson('/api/staff/inventory/NOPE')->assertNotFound();
    }

    public function test_the_history_is_paged_twenty_at_a_time(): void
    {
        $item = $this->item('INV-1');
        foreach (range(1, 25) as $i) {
            InventoryMovement::create(['inventory_item_id' => $item->id, 'type' => $i % 2 ? 'in' : 'out', 'note' => "Gerak {$i}"]);
        }
        $user = $this->staff();

        $first = $this->api($user)->getJson('/api/staff/inventory/INV-1/movements')->assertSuccessful();
        $this->assertCount(20, $first->json('data'));
        $this->assertTrue($first->json('has_more'));

        $second = $this->api($user)->getJson('/api/staff/inventory/INV-1/movements?offset=20')->assertSuccessful();
        $this->assertCount(5, $second->json('data'));
        $this->assertFalse($second->json('has_more'));

        $this->assertTrue($this->api($user)->getJson('/api/staff/inventory/INV-1')->json('movements_has_more'));
        $this->api($user)->getJson('/api/staff/inventory/NOPE/movements')->assertNotFound();
    }

    // ------------------------------------------------------------- keluar / masuk

    public function test_staff_out_and_in_are_tagged_with_their_own_store_and_sync_the_roll(): void
    {
        $scroll = $this->scroll('GLN-1', null, 'unallocated');
        $item = $this->item('INV-1', 'Film Alpha', $scroll);
        $user = $this->staff('kasir', $this->storeA);

        $this->api($user)->postJson('/api/staff/inventory/INV-1/movement', ['type' => 'out', 'note' => 'Untuk toko', 'store_id' => $this->storeB->id])
            ->assertSuccessful()
            ->assertJsonPath('message', 'Barang keluar berhasil dicatat.')
            ->assertJsonPath('data.status', 'out');

        $movement = InventoryMovement::firstOrFail();
        $this->assertSame($this->storeA->id, $movement->destination_store_id, 'Staf biasa: toko dari akunnya, kiriman store_id diabaikan.');
        $this->assertSame($user->id, $movement->user_id);
        $scroll = $scroll->fresh();
        $this->assertSame(['allocated', $this->storeA->id], [$scroll->status, $scroll->store_id]);

        $this->api($user)->postJson('/api/staff/inventory/INV-1/movement', ['type' => 'in'])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'in_stock');
        $this->assertSame('unallocated', $scroll->fresh()->status);
    }

    public function test_an_impossible_transition_is_refused(): void
    {
        $this->item('INV-1');
        $user = $this->staff();

        $this->api($user)->postJson('/api/staff/inventory/INV-1/movement', ['type' => 'in'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Barang ini sudah tercatat ada di gudang (in_stock).');

        $this->api($user)->postJson('/api/staff/inventory/INV-1/movement', ['type' => 'out'])->assertSuccessful();
        $this->api($user)->postJson('/api/staff/inventory/INV-1/movement', ['type' => 'out'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Barang ini sudah tercatat keluar sebelumnya.');
        $this->assertSame(1, InventoryMovement::count());
    }

    public function test_full_access_must_choose_a_destination_store_for_an_out(): void
    {
        $this->item('INV-1');
        $admin = $this->staff('super_admin');

        $this->api($admin)->postJson('/api/staff/inventory/INV-1/movement', ['type' => 'out'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pilih toko tujuan dulu.');
        $this->assertSame(0, InventoryMovement::count());

        $this->api($admin)->postJson('/api/staff/inventory/INV-1/movement', ['type' => 'out', 'store_id' => $this->storeB->id])->assertSuccessful();
        $this->assertSame($this->storeB->id, InventoryMovement::firstOrFail()->destination_store_id);
    }

    public function test_movement_validation_and_unknown_items(): void
    {
        $this->item('INV-1');
        $user = $this->staff();

        $this->api($user)->postJson('/api/staff/inventory/INV-1/movement', ['type' => 'hilang'])->assertStatus(422)->assertJsonValidationErrors('type');
        $this->api($user)->postJson('/api/staff/inventory/INV-1/movement', ['type' => 'out', 'note' => str_repeat('a', 501)])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->api($this->staff('super_admin'))->postJson('/api/staff/inventory/INV-1/movement', ['type' => 'out', 'store_id' => 99999])->assertStatus(422)->assertJsonValidationErrors('store_id');
        $this->api($user)->postJson('/api/staff/inventory/NOPE/movement', ['type' => 'out'])->assertNotFound();
        $this->assertSame(0, InventoryMovement::count());
    }

    // ------------------------------------------------------------- pemakaian meter

    public function test_recording_usage_reduces_the_remaining_length(): void
    {
        $scroll = $this->scroll('GLN-1', $this->storeA, 'allocated', 15, 15);
        $this->item('INV-1', 'Film Alpha', $scroll);
        $user = $this->staff();

        $this->api($user)->postJson('/api/staff/inventory/INV-1/record-usage', ['meters' => 4.5, 'note' => 'Hood'])
            ->assertSuccessful()
            ->assertJsonPath('message', 'Pemakaian berhasil dicatat.');

        $scroll = $scroll->fresh();
        $this->assertEquals(10.5, (float) $scroll->remaining_length_meters);
        $this->assertSame(1, $scroll->usage_count);

        $this->api($user)->postJson('/api/staff/inventory/INV-1/record-usage', ['meters' => 50])->assertStatus(422);
        $this->assertEquals(10.5, (float) $scroll->fresh()->remaining_length_meters);
    }

    public function test_usage_validation_and_missing_roll(): void
    {
        $this->item('INV-PLAIN');
        $this->item('INV-1', 'Film Alpha', $this->scroll('GLN-1', $this->storeA));
        $user = $this->staff();

        $this->api($user)->postJson('/api/staff/inventory/INV-1/record-usage', ['meters' => 0])->assertStatus(422)->assertJsonValidationErrors('meters');
        $this->api($user)->postJson('/api/staff/inventory/INV-1/record-usage', ['meters' => 200000])->assertStatus(422)->assertJsonValidationErrors('meters');
        $this->api($user)->postJson('/api/staff/inventory/INV-PLAIN/record-usage', ['meters' => 1])->assertNotFound();
        $this->api($user)->postJson('/api/staff/inventory/NOPE/record-usage', ['meters' => 1])->assertNotFound();
    }

    public function test_a_roll_allocated_to_another_store_is_off_limits_for_staff_but_not_for_full_access(): void
    {
        $scroll = $this->scroll('GLN-B', $this->storeB, 'allocated', 15, 15);
        $this->item('INV-1', 'Film Alpha', $scroll);

        $this->api($this->staff('kasir', $this->storeA))->postJson('/api/staff/inventory/INV-1/record-usage', ['meters' => 1])->assertForbidden();
        $this->api($this->staff('kasir', $this->storeA))->postJson('/api/staff/inventory/INV-1/mark-scroll-code-used')->assertForbidden();
        $this->assertEquals(15, (float) $scroll->fresh()->remaining_length_meters);
        $this->assertSame('allocated', $scroll->fresh()->status);

        $this->api($this->staff('kasir', $this->storeB))->postJson('/api/staff/inventory/INV-1/record-usage', ['meters' => 1])->assertSuccessful();
        $this->api($this->staff('super_admin'))->postJson('/api/staff/inventory/INV-1/record-usage', ['meters' => 1])->assertSuccessful();
        $this->assertEquals(13, (float) $scroll->fresh()->remaining_length_meters);
    }

    public function test_usage_can_be_linked_only_to_a_confirmed_booking_of_the_same_store(): void
    {
        $scroll = $this->scroll('GLN-1', $this->storeA, 'allocated', 15, 15);
        $this->item('INV-1', 'Film Alpha', $scroll);
        $mine = $this->booking($this->storeA, 'confirmed');
        $pending = $this->booking($this->storeA, 'pending');
        $theirs = $this->booking($this->storeB, 'confirmed');
        $user = $this->staff('kasir', $this->storeA);

        $this->api($user)->postJson('/api/staff/inventory/INV-1/record-usage', ['meters' => 1, 'booking_id' => $pending->id])->assertStatus(422);
        // Booking toko lain tidak terlihat oleh staf (Global Scope toko): dijawab "tidak valid", tanpa membocorkan keberadaannya.
        $this->api($user)->postJson('/api/staff/inventory/INV-1/record-usage', ['meters' => 1, 'booking_id' => $theirs->id])->assertStatus(422);
        $this->api($user)->postJson('/api/staff/inventory/INV-1/record-usage', ['meters' => 1, 'booking_id' => 99999])->assertStatus(422)->assertJsonValidationErrors('booking_id');
        $this->assertEquals(15, (float) $scroll->fresh()->remaining_length_meters, 'Semua yang ditolak tidak memakai meter.');

        $this->api($user)->postJson('/api/staff/inventory/INV-1/record-usage', ['meters' => 2, 'booking_id' => $mine->id])->assertSuccessful();
        $this->assertSame($mine->id, $scroll->usages()->firstOrFail()->booking_id);

        $this->api($this->staff('super_admin'))->postJson('/api/staff/inventory/INV-1/record-usage', ['meters' => 1, 'booking_id' => $theirs->id])->assertSuccessful();
    }

    // ------------------------------------------------------------- tandai habis

    public function test_marking_a_roll_used_works_only_from_allocated(): void
    {
        $allocated = $this->scroll('GLN-1', $this->storeA, 'allocated');
        $fresh = $this->scroll('GLN-2', null, 'unallocated');
        $this->item('INV-1', 'A', $allocated);
        $this->item('INV-2', 'B', $fresh);
        $this->item('INV-3', 'Polos');
        $user = $this->staff();

        $this->api($user)->postJson('/api/staff/inventory/INV-1/mark-scroll-code-used')
            ->assertSuccessful()
            ->assertJsonPath('message', 'Kode gulungan ditandai habis.');
        $this->assertSame('used', $allocated->fresh()->status);
        $this->assertNotNull($allocated->fresh()->used_at);

        $this->api($user)->postJson('/api/staff/inventory/INV-1/mark-scroll-code-used')->assertStatus(422);
        $this->api($user)->postJson('/api/staff/inventory/INV-2/mark-scroll-code-used')->assertStatus(422);
        $this->assertSame('unallocated', $fresh->fresh()->status);
        $this->api($user)->postJson('/api/staff/inventory/INV-3/mark-scroll-code-used')->assertNotFound();
    }
}
