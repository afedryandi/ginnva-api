<?php

namespace Tests\Feature;

use App\Filament\Resources\RelationManagers\ActivityHistoryRelationManager;
use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Histori Aktivitas Fase 1 (2026-10-21): tab Riwayat per rekaman di resource utama.
 */
class ActivityHistoryTabTest extends TestCase
{
    use RefreshDatabase;

    /** Resource utama yang diberi tab Riwayat. */
    private const RESOURCES = [
        'Booking', 'Warranty', 'Customer', 'Invoice', 'Quotation', 'FilmProduct', 'Store', 'User', 'Spk', 'Partner', 'Vehicle',
        'Supplier', 'Payable', 'Receivable', 'FinanceTransaction', 'JournalEntry', 'Technician', 'Reward', 'Asset',
        'PurchaseRequest', 'StockOpname', 'PaymentAccount',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function as(string $role): User
    {
        $user = User::create(['name' => ucfirst($role), 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]);
        $user->assignRole($role);
        $this->actingAs($user, 'web');

        return $user;
    }

    private function store(string $name): Store
    {
        return Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => $name, 'is_active' => true]);
    }

    private function log(Store $store, string $description, array $extra = []): Activity
    {
        return Activity::create(array_merge([
            'log_name' => 'store', 'description' => $description, 'event' => 'updated',
            'subject_type' => Store::class, 'subject_id' => $store->id, 'properties' => [],
        ], $extra));
    }

    public function test_every_main_resource_registers_the_tab_and_its_model_has_the_relation(): void
    {
        foreach (self::RESOURCES as $name) {
            $resource = "App\\Filament\\Resources\\{$name}Resource";

            $this->assertContains(ActivityHistoryRelationManager::class, $resource::getRelations(), "{$name}Resource belum memasang tab Riwayat.");
            $this->assertContains(LogsActivity::class, class_uses_recursive($resource::getModel()), "Model {$name} tidak memakai LogsActivity.");
            $this->assertTrue(method_exists($resource::getModel(), 'activities'), "Model {$name} tidak punya relasi activities().");
        }
    }

    public function test_only_those_who_may_open_the_activity_menu_see_the_tab(): void
    {
        $store = $this->store('Toko A');

        $this->as('super_admin');
        $this->assertTrue(ActivityHistoryRelationManager::canViewForRecord($store, EditStore::class));

        $this->as('kasir');
        $this->assertFalse(ActivityHistoryRelationManager::canViewForRecord($store, EditStore::class));
    }

    public function test_the_tab_lists_only_this_records_history_with_readable_sentences(): void
    {
        $this->as('super_admin');
        $mine = $this->store('Toko A');
        $other = $this->store('Toko B');
        Activity::query()->delete();
        $first = $this->log($mine, 'updated', ['properties' => ['attributes' => ['name' => 'Toko A2']]]);
        $second = $this->log($mine, 'Radius absen toko diubah');
        $foreign = $this->log($other, 'Milik toko lain');

        Livewire::test(ActivityHistoryRelationManager::class, ['ownerRecord' => $mine, 'pageClass' => EditStore::class])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$first, $second])
            ->assertCanNotSeeTableRecords([$foreign])
            ->assertSee('Radius absen toko diubah')
            ->assertSee('Toko #' . $mine->id . ' diubah (Nama)');
    }

    public function test_the_tab_shows_the_empty_state_and_the_detail_view(): void
    {
        $this->as('super_admin');
        $store = $this->store('Toko A');
        Activity::query()->delete();

        Livewire::test(ActivityHistoryRelationManager::class, ['ownerRecord' => $store, 'pageClass' => EditStore::class])
            ->assertSee('Belum ada riwayat');

        $log = $this->log($store, 'Harga diubah', ['properties' => ['old' => ['city' => 'Jakarta'], 'attributes' => ['city' => 'Bandung']]]);

        Livewire::test(ActivityHistoryRelationManager::class, ['ownerRecord' => $store, 'pageClass' => EditStore::class])
            ->assertCanSeeTableRecords([$log])
            ->callTableAction('view', $log)
            ->assertSuccessful();
    }
}
