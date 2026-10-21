<?php

namespace Tests\Feature;

use App\Filament\Resources\StoreResource\Pages\CreateStore;
use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Filament\Resources\StoreResource\Pages\ListStores;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Master Data Fase 3 (2026-10-21): identitas toko. Kode toko singkat dan unik (huruf besar otomatis, boleh kosong untuk toko lama)
 * dan penanggung jawab opsional. Tidak ada zona waktu (keputusan pemilik).
 */
class StoreIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('partner', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->actingAs(tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->storeA->id]), fn (User $u) => $u->assignRole('super_admin')), 'web');
    }

    private function form(array $extra = []): array
    {
        return array_merge(['name' => 'Toko Baru', 'city' => 'Surabaya', 'address' => 'Jl. Baru 1', 'phone' => '031-123456', 'install_capacity_per_day' => 3, 'opening_hours' => [], 'is_active' => true], $extra);
    }

    public function test_existing_stores_have_no_code_and_that_is_allowed(): void
    {
        $this->assertNull($this->storeA->fresh()->code);
        $second = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->assertNull($second->fresh()->code, 'Banyak toko tanpa kode boleh (null tidak dianggap kembar).');
    }

    public function test_the_code_is_uppercased_and_saved(): void
    {
        Livewire::test(CreateStore::class)->fillForm($this->form(['code' => 'jkt-01']))->call('create')->assertHasNoFormErrors();

        $this->assertSame('JKT-01', Store::where('name', 'Toko Baru')->firstOrFail()->code);
    }

    public function test_the_model_normalises_the_code_whatever_the_entry_path(): void
    {
        $store = Store::create(['city' => 'X', 'address' => 'Y', 'name' => 'Z', 'code' => '  bdg-02 ', 'is_active' => true]);
        $this->assertSame('BDG-02', $store->fresh()->code);

        $store->update(['code' => '   ']);
        $this->assertNull($store->fresh()->code, 'Isian spasi-saja menjadi kosong.');
    }

    public function test_a_duplicate_code_is_refused_in_any_letter_case(): void
    {
        $this->storeA->update(['code' => 'JKT-01']);

        Livewire::test(CreateStore::class)->fillForm($this->form(['code' => 'jkt-01']))->call('create')->assertHasFormErrors(['code' => 'unique']);
        $this->assertSame(0, Store::where('name', 'Toko Baru')->count());
    }

    public function test_an_invalid_code_is_refused(): void
    {
        foreach (['JKT 01', 'JKT_01', 'TOKO-TERLALU-PANJANG-1'] as $bad) {
            Livewire::test(CreateStore::class)->fillForm($this->form(['code' => $bad]))->call('create')->assertHasFormErrors(['code']);
        }
    }

    public function test_editing_a_store_keeps_its_own_code_without_a_false_duplicate_error(): void
    {
        $this->storeA->update(['code' => 'JKT-01']);

        Livewire::test(EditStore::class, ['record' => $this->storeA->getRouteKey()])
            ->fillForm(['code' => 'JKT-01', 'phone' => '021-999'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('021-999', $this->storeA->fresh()->phone);
    }

    public function test_the_manager_can_be_chosen_and_partners_are_not_offered(): void
    {
        $manager = User::create(['name' => 'Rina', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->storeA->id, 'is_active' => true]);
        $partner = tap(User::create(['name' => 'Mitra', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]), fn (User $u) => $u->assignRole('partner'));

        Livewire::test(CreateStore::class)->fillForm($this->form(['manager_user_id' => $manager->id]))->call('create')->assertHasNoFormErrors();

        $store = Store::where('name', 'Toko Baru')->firstOrFail();
        $this->assertSame($manager->id, $store->manager->id);

        Livewire::test(CreateStore::class)->assertFormFieldExists(
            'manager_user_id',
            fn (\Filament\Forms\Components\Select $field) => array_key_exists($manager->id, $field->getOptions()) && ! array_key_exists($partner->id, $field->getOptions())
        );
    }

    public function test_deleting_the_manager_leaves_the_store_intact(): void
    {
        $manager = User::create(['name' => 'Rina', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]);
        $this->storeA->update(['manager_user_id' => $manager->id]);

        \Illuminate\Support\Facades\DB::table('users')->where('id', $manager->id)->delete();

        $this->assertNull($this->storeA->fresh()->manager_user_id);
        $this->assertNotNull(Store::find($this->storeA->id));
    }

    public function test_the_list_marks_stores_without_a_code_and_can_filter_them(): void
    {
        $this->storeA->update(['code' => 'JKT-01']);
        $without = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);

        Livewire::test(ListStores::class)
            ->assertSuccessful()
            ->assertSee('JKT-01')
            ->assertSee('Belum ada')
            ->filterTable('no_code')
            ->assertCanSeeTableRecords([$without])
            ->assertCanNotSeeTableRecords([$this->storeA]);
    }
}
