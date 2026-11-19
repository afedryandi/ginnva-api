<?php

namespace Tests\Feature;

use App\Filament\Resources\MaterialMemoResource\Pages\CreateMaterialMemo;
use App\Filament\Resources\SpkResource\Pages\ViewSpk;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\MaterialMemo;
use App\Models\Spk;
use App\Models\Store;
use App\Models\User;
use App\Services\SpkService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Alur Booking -> SPK -> Memo Barang: memo dibuat dari SPK, SPK tertaut, booking ikut tertaut, dan memo lama tidak disentuh. */
class MemoFromSpkTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Booking $booking;

    private Spk $spk;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $this->booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $this->store->id,
            'service_type' => 'Pelindung Cat (PPF)',
            'product_ppf' => true,
            'preferred_date' => now()->addDays(3)->toDateString(),
            'status' => 'confirmed',
        ]);
        $this->spk = app(SpkService::class)->createFromBooking($this->booking, $admin->id);
    }

    public function test_a_memo_created_with_an_spk_links_to_the_spk_and_its_booking(): void
    {
        Livewire::test(CreateMaterialMemo::class)
            ->fillForm(['store_id' => $this->store->id, 'spk_id' => $this->spk->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $memo = MaterialMemo::withoutGlobalScopes()->latest('id')->first();

        $this->assertSame($this->spk->id, $memo->spk_id);
        $this->assertSame($this->spk->spk_number, $memo->spk_number);
        $this->assertSame($this->booking->id, $memo->booking_id, 'Booking SPK ikut tertaut supaya kunci booking Selesai tetap berlaku.');
        $this->assertTrue($this->spk->materialMemos()->whereKey($memo->id)->exists());
    }

    public function test_a_free_text_spk_number_still_works_without_picking_an_spk(): void
    {
        Livewire::test(CreateMaterialMemo::class)
            ->fillForm(['store_id' => $this->store->id, 'spk_number' => 'MANUAL-001'])
            ->call('create')
            ->assertHasNoFormErrors();

        $memo = MaterialMemo::withoutGlobalScopes()->latest('id')->first();

        $this->assertNull($memo->spk_id);
        $this->assertSame('MANUAL-001', $memo->spk_number);
    }

    public function test_the_booking_link_is_skipped_when_the_booking_already_has_a_memo(): void
    {
        MaterialMemo::create(['memo_number' => 'MEMO-LAMA-1', 'store_id' => $this->store->id, 'booking_id' => $this->booking->id]);

        $data = MaterialMemo::completeFromSpk(['spk_id' => $this->spk->id]);

        $this->assertSame($this->spk->spk_number, $data['spk_number']);
        $this->assertArrayNotHasKey('booking_id', $data, 'Satu booking satu memo: tidak ditimpa.');
    }

    public function test_an_existing_booking_link_is_not_replaced_when_editing(): void
    {
        $other = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $this->booking->customer_id,
            'store_id' => $this->store->id,
            'service_type' => 'Premium Wash',
            'product_premium_wash' => true,
            'preferred_date' => now()->addDays(4)->toDateString(),
            'status' => 'confirmed',
        ]);
        $memo = MaterialMemo::create(['memo_number' => 'MEMO-LAMA-2', 'store_id' => $this->store->id, 'booking_id' => $other->id]);

        $data = MaterialMemo::completeFromSpk(['spk_id' => $this->spk->id], $memo);

        $this->assertArrayNotHasKey('booking_id', $data);
    }

    public function test_the_spk_view_page_lists_its_memos(): void
    {
        $memo = MaterialMemo::create([
            'memo_number' => 'MEMO-DARI-SPK-1',
            'store_id' => $this->store->id,
            'spk_id' => $this->spk->id,
            'spk_number' => $this->spk->spk_number,
        ]);

        Livewire::test(ViewSpk::class, ['record' => $this->spk->getKey()])
            ->assertSuccessful()
            ->assertSee($memo->memo_number);
    }

    private function cashier(?Store $store = null): User
    {
        Role::findOrCreate('kasir', 'web');
        $staff = User::create([
            'name' => 'Kasir', 'email' => 'kasir' . uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->store)->id,
            'menu_access' => ['MaterialMemoResource'], 'menu_permissions' => ['MaterialMemoResource' => ['create', 'update', 'view']], 'is_active' => true,
        ]);
        $staff->assignRole('kasir');

        return $staff;
    }

    public function test_the_mobile_api_lists_only_spks_of_the_staff_store(): void
    {
        $otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $foreign = Spk::withoutGlobalScopes()->create(['store_id' => $otherStore->id, 'spk_number' => 'SPK-LAIN-1', 'customer_name' => 'Orang Lain']);

        $response = $this->actingAs($this->cashier(), 'api')->getJson('/api/staff/memos/spks')->assertOk();

        $numbers = collect($response->json('data'))->pluck('spk_number');
        $this->assertTrue($numbers->contains($this->spk->spk_number));
        $this->assertFalse($numbers->contains($foreign->spk_number), 'SPK toko lain tidak boleh muncul.');
    }

    public function test_the_mobile_api_creates_a_memo_linked_to_an_spk(): void
    {
        $response = $this->actingAs($this->cashier(), 'api')
            ->postJson('/api/staff/memos', ['spk_id' => $this->spk->id])
            ->assertCreated();

        $memo = MaterialMemo::withoutGlobalScopes()->find($response->json('data.id'));

        $this->assertSame($this->spk->id, $memo->spk_id);
        $this->assertSame($this->spk->spk_number, $memo->spk_number);
        $this->assertSame($this->booking->id, $memo->booking_id);
    }

    public function test_the_mobile_api_rejects_an_spk_of_another_store(): void
    {
        $otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $foreign = Spk::withoutGlobalScopes()->create(['store_id' => $otherStore->id, 'spk_number' => 'SPK-LAIN-2', 'customer_name' => 'Orang Lain']);

        $this->actingAs($this->cashier(), 'api')
            ->postJson('/api/staff/memos', ['spk_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('spk_id');
    }

    public function test_the_mobile_app_payload_without_spk_id_still_works(): void
    {
        $response = $this->actingAs($this->cashier(), 'api')
            ->postJson('/api/staff/memos', ['spk_number' => 'KETIK-1'])
            ->assertCreated();

        $memo = MaterialMemo::withoutGlobalScopes()->find($response->json('data.id'));

        $this->assertNull($memo->spk_id);
        $this->assertSame('KETIK-1', $memo->spk_number);
    }

    public function test_the_spk_detail_for_mobile_includes_its_memos(): void
    {
        $memo = MaterialMemo::create(['memo_number' => 'MEMO-API-1', 'store_id' => $this->store->id, 'spk_id' => $this->spk->id]);

        // Akses ke SPK di app mengikuti menu SPK, jadi akun akses penuh yang dipakai di sini.
        $this->actingAs(User::where('email', 'admin@test.local')->first(), 'api')
            ->getJson("/api/staff/spks/{$this->spk->id}")
            ->assertOk()
            ->assertJsonFragment(['memo_number' => $memo->memo_number]);
    }
}
