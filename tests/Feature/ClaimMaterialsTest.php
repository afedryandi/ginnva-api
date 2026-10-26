<?php

namespace Tests\Feature;

use App\Filament\Resources\MaterialMemoResource\Pages\CreateMaterialMemo;
use App\Filament\Resources\WarrantyResource\Pages\EditWarranty;
use App\Filament\Resources\WarrantyResource\RelationManagers\ClaimsRelationManager;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\MaterialMemo;
use App\Models\Store;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Bahan Terpakai" pada klaim garansi: klaim hanya membaca dan menautkan Memo Pengambilan/Pengembalian booking kunjungannya
 * (stok tetap diurus memo, tidak ada jalur stok kedua), dan halaman Buat Memo bisa terisi dari ?booking={id}.
 */
class ClaimMaterialsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['super_admin', 'kasir'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    private function admin(): User
    {
        $user = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function warranty(): Warranty
    {
        return Warranty::create([
            'warranty_code' => 'GNV-PPF-T' . strtoupper(substr(uniqid(), -6)), 'customer_name' => 'Budi Santoso', 'phone_number' => '081234567890', 'car_plate' => 'B 1234 XYZ',
            'car_type' => 'Toyota Raize', 'product_series' => 'Ginnva PPF Pro', 'product_category' => 'ppf', 'installation_date' => '2026-04-20',
            'expiry_date' => '2031-04-20', 'dealer_name' => 'Toko A', 'store_id' => $this->store->id, 'customer_id' => null,
            'status' => 'active', 'review_status' => 'approved',
        ]);
    }

    private function claim(Warranty $warranty, array $overrides = []): WarrantyClaim
    {
        return WarrantyClaim::create(array_merge(['warranty_id' => $warranty->id, 'category' => 'product_warranty', 'description' => 'Film mengelupas', 'status' => 'pass'], $overrides));
    }

    private function visitBooking(WarrantyClaim $claim, ?Store $store = null): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '08120000' . random_int(1000, 9999)]);

        return Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(), 'customer_id' => $customer->id, 'store_id' => ($store ?? $this->store)->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => now()->toDateString(), 'status' => 'confirmed',
            'warranty_claim_id' => $claim->id, 'vehicle_brand' => 'Toyota', 'vehicle_model' => 'Raize', 'vehicle_plate' => 'B 1234 XYZ',
        ]);
    }

    private function memoFor(Booking $booking, int $items = 1): MaterialMemo
    {
        $memo = MaterialMemo::create([
            'memo_number' => 'MEMO-TEST-' . uniqid(), 'store_id' => $booking->store_id, 'booking_id' => $booking->id, 'created_by' => $this->admin()->id,
        ]);

        for ($i = 1; $i <= $items; $i++) {
            $memo->items()->create(['item_type' => 'raw_material', 'item_id' => 9000 + $i, 'item_name' => "Lem Panel {$i}", 'unit' => 'liter', 'qty_taken' => 2]);
        }

        return $memo;
    }

    private function manager(Warranty $warranty)
    {
        return Livewire::test(ClaimsRelationManager::class, ['ownerRecord' => $warranty, 'pageClass' => EditWarranty::class]);
    }

    public function test_the_claim_reads_the_memo_of_its_visit_booking(): void
    {
        $claim = $this->claim($this->warranty());
        $this->assertNull($claim->materialMemo(), 'Tanpa booking kunjungan tidak ada memo.');

        $booking = $this->visitBooking($claim);
        $this->assertNull($claim->fresh()->materialMemo(), 'Booking ada tetapi belum ada memo.');

        $memo = $this->memoFor($booking, 2);
        $this->assertTrue($claim->fresh()->materialMemo()->is($memo));
    }

    public function test_the_table_summarises_the_materials_used(): void
    {
        $this->actingAs($this->admin(), 'web');
        $warranty = $this->warranty();
        $claim = $this->claim($warranty);
        $this->memoFor($this->visitBooking($claim), 2);

        $this->manager($warranty)->assertTableColumnStateSet('materials', '2 item', $claim);
    }

    public function test_the_materials_modal_lists_the_memo_items(): void
    {
        $this->actingAs($this->admin(), 'web');
        $warranty = $this->warranty();
        $claim = $this->claim($warranty);
        $memo = $this->memoFor($this->visitBooking($claim), 2);

        $this->manager($warranty)
            ->mountTableAction('materials', $claim)
            ->assertSee($memo->memo_number)
            ->assertSee('Lem Panel 1')
            ->assertSee('Lem Panel 2');
    }

    public function test_the_modal_explains_when_there_is_no_visit_booking(): void
    {
        $this->actingAs($this->admin(), 'web');
        $warranty = $this->warranty();
        $claim = $this->claim($warranty);

        $this->manager($warranty)
            ->mountTableAction('materials', $claim)
            ->assertSee('belum punya booking kunjungan');
    }

    public function test_the_modal_offers_to_create_a_memo_when_the_booking_has_none(): void
    {
        $this->actingAs($this->admin(), 'web');
        $warranty = $this->warranty();
        $claim = $this->claim($warranty);
        $this->visitBooking($claim);

        $this->manager($warranty)
            ->mountTableAction('materials', $claim)
            ->assertSee('Belum ada memo')
            ->assertSee('Buat Memo Pengambilan');
    }

    public function test_the_materials_action_is_only_for_approved_claims(): void
    {
        $this->actingAs($this->admin(), 'web');
        $warranty = $this->warranty();
        $pending = $this->claim($warranty, ['status' => 'pending']);

        $this->manager($warranty)->assertTableActionHidden('materials', $pending);
    }

    public function test_the_new_memo_page_prefills_from_the_booking(): void
    {
        $this->actingAs($this->admin(), 'web');
        $booking = $this->visitBooking($this->claim($this->warranty()));

        Livewire::withQueryParams(['booking' => $booking->id])->test(CreateMaterialMemo::class)
            ->assertFormSet([
                'booking_id' => $booking->id,
                'store_id' => $this->store->id,
                'vehicle_info' => 'Toyota Raize - B 1234 XYZ',
            ]);
    }

    public function test_prefill_is_skipped_when_the_booking_already_has_a_memo(): void
    {
        $this->actingAs($this->admin(), 'web');
        $booking = $this->visitBooking($this->claim($this->warranty()));
        $this->memoFor($booking);

        Livewire::withQueryParams(['booking' => $booking->id])->test(CreateMaterialMemo::class)
            ->assertFormSet(['booking_id' => null]);
    }

    public function test_prefill_is_skipped_for_a_booking_of_another_store(): void
    {
        $otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $booking = $this->visitBooking($this->claim($this->warranty()), $otherStore);

        $staff = User::create(['name' => 'Staf Toko A', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true, 'store_id' => $this->store->id]);
        $staff->assignRole('kasir');
        $this->actingAs($staff, 'web');

        Livewire::withQueryParams(['booking' => $booking->id])->test(CreateMaterialMemo::class)
            ->assertFormSet(['booking_id' => null]);
    }
}
