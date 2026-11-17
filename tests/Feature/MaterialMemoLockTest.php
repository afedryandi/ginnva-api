<?php

namespace Tests\Feature;

use App\Filament\Resources\MaterialMemoResource;
use App\Filament\Resources\MaterialMemoResource\Pages\ListMaterialMemos;
use App\Filament\Resources\MaterialMemoResource\Pages\ViewMaterialMemo;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\MaterialMemo;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Memo Pengambilan/Pengembalian terkunci begitu booking-nya Selesai; akses penuh bisa membuka kunci dengan alasan wajib. */
class MaterialMemoLockTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.memo_booking_link' => true]); // tautan booking di memo mati secara bawaan; tes ini menguji tautannya

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x', 'store_id' => $this->store->id]);
        $this->admin->assignRole('super_admin');
        $this->actingAs($this->admin, 'web');
    }

    private function memoFor(string $bookingStatus): MaterialMemo
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $booking = Booking::create([
            'booking_number' => 'BKG-ML-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $this->store->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => now()->toDateString(), 'status' => $bookingStatus,
        ]);

        return MaterialMemo::create(['memo_number' => 'MEMO-ML-' . uniqid(), 'store_id' => $this->store->id, 'booking_id' => $booking->id, 'created_by' => $this->admin->id]);
    }

    private function cashier(string $email): User
    {
        $staff = User::create([
            'name' => 'Kasir', 'email' => $email, 'password' => 'x', 'store_id' => $this->store->id,
            'menu_access' => ['MaterialMemoResource'], 'menu_permissions' => ['MaterialMemoResource' => ['create', 'update', 'view']], 'is_active' => true,
        ]);
        $staff->assignRole('kasir');

        return $staff;
    }

    public function test_a_memo_is_locked_only_when_its_booking_is_completed(): void
    {
        $this->assertTrue($this->memoFor('completed')->isLocked());
        $this->assertFalse($this->memoFor('confirmed')->isLocked());
        $this->assertFalse($this->memoFor('cancelled')->isLocked(), 'Booking batal tidak mengunci: sisa bahan masih harus bisa dikembalikan.');

        $standalone = MaterialMemo::create(['memo_number' => 'MEMO-ML-' . uniqid(), 'store_id' => $this->store->id, 'created_by' => $this->admin->id]);
        $this->assertFalse($standalone->isLocked());
    }

    public function test_a_locked_memo_cannot_be_edited_in_the_panel_even_by_full_access(): void
    {
        $locked = $this->memoFor('completed');
        $open = $this->memoFor('confirmed');

        $this->assertFalse(MaterialMemoResource::canEdit($locked));
        $this->assertTrue(MaterialMemoResource::canEdit($open));
    }

    public function test_full_access_can_unlock_with_a_reason_and_lock_again(): void
    {
        $memo = $this->memoFor('completed');

        Livewire::test(ViewMaterialMemo::class, ['record' => $memo->getKey()])
            ->assertActionVisible('unlock')
            ->callAction('unlock', ['reason' => 'Salah catat pengembalian'])
            ->assertHasNoActionErrors();

        $fresh = $memo->fresh();
        $this->assertFalse($fresh->isLocked());
        $this->assertSame('Salah catat pengembalian', $fresh->unlock_reason);
        $this->assertTrue(MaterialMemoResource::canEdit($fresh));
        $this->assertDatabaseHas('activity_log', ['subject_id' => $memo->id, 'log_name' => 'material_memo']);

        Livewire::test(ViewMaterialMemo::class, ['record' => $memo->getKey()])
            ->assertActionVisible('relock')
            ->callAction('relock');

        $this->assertTrue($memo->fresh()->isLocked());
    }

    public function test_the_unlock_action_needs_a_reason(): void
    {
        $memo = $this->memoFor('completed');

        Livewire::test(ViewMaterialMemo::class, ['record' => $memo->getKey()])
            ->callAction('unlock', ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);

        $this->assertTrue($memo->fresh()->isLocked());
    }

    public function test_the_unlock_action_is_hidden_from_non_full_access_staff(): void
    {
        $memo = $this->memoFor('completed');
        $this->actingAs($this->cashier('kasir@test.local'), 'web');

        Livewire::test(ViewMaterialMemo::class, ['record' => $memo->getKey()])->assertActionHidden('unlock');
        $this->assertFalse(MaterialMemoResource::canEdit($memo));
    }

    public function test_the_api_refuses_changes_to_a_locked_memo_but_allows_an_open_one(): void
    {
        $locked = $this->memoFor('completed');
        $open = $this->memoFor('confirmed');
        $staff = $this->cashier('kasir2@test.local');

        $this->actingAs($staff, 'api')->patchJson("/api/staff/memos/{$locked->id}", ['notes' => 'ubah'])
            ->assertStatus(422)->assertJson(['locked' => true]);

        $this->actingAs($staff, 'api')->patchJson("/api/staff/memos/{$open->id}", ['notes' => 'ubah'])
            ->assertSuccessful();

        $this->actingAs($staff, 'api')->getJson("/api/staff/memos/{$locked->id}")
            ->assertSuccessful()->assertJson(['is_locked' => true, 'can_modify' => false]);
    }

    public function test_the_list_shows_a_lock_icon_column(): void
    {
        $this->memoFor('completed');

        Livewire::test(ListMaterialMemos::class)->assertSuccessful()->assertTableColumnExists('lock');
    }
}
