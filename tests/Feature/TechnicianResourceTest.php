<?php

namespace Tests\Feature;

use App\Filament\Pages\TechnicianCommissionReport;
use App\Filament\Pages\TechnicianServiceDurationReport;
use App\Filament\Pages\TechnicianUtilizationReport;
use App\Filament\Resources\TechnicianResource;
use App\Filament\Resources\TechnicianResource\Pages\CreateTechnician;
use App\Filament\Resources\TechnicianResource\Pages\EditTechnician;
use App\Filament\Resources\TechnicianResource\Pages\ListTechnicians;
use App\Filament\Resources\TechnicianResource\Pages\ViewTechnician;
use App\Filament\Resources\TechnicianResource\RelationManagers\ServiceRatesRelationManager;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\Store;
use App\Models\Technician;
use App\Models\TechnicianServiceRate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Teknisi: pendaftaran & review (aktifkan/tolak + push), level & komisi,
 * tarif per layanan, satu akun installer = satu teknisi, scoping toko,
 * pengaruhnya ke penugasan installer (API mobile), dan halaman laporan.
 * (Perhitungan komisi/durasi/utilisasi sudah diuji di test service terpisah.)
 */
class TechnicianResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['store_manager', 'installer', 'kasir'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    private function user(string $role, ?int $storeId = null, ?array $menuAccess = null): User
    {
        $user = User::create([
            'name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x',
            'store_id' => $storeId ?? $this->store->id, 'menu_access' => $menuAccess,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function technician(array $overrides = []): Technician
    {
        return Technician::create(array_merge([
            'store_id' => $this->store->id, 'name' => 'Teknisi ' . uniqid(), 'level' => 'intermediate', 'status' => 'active',
        ], $overrides));
    }

    private function asAdmin(): User
    {
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');

        return $admin;
    }

    // ------------------------------------------------------------- halaman & form

    public function test_pages_render_for_admin(): void
    {
        $this->asAdmin();
        $tech = $this->technician();

        Livewire::test(ListTechnicians::class)->assertSuccessful()->assertCanSeeTableRecords([$tech]);
        Livewire::test(ViewTechnician::class, ['record' => $tech->getKey()])->assertSuccessful();
        Livewire::test(EditTechnician::class, ['record' => $tech->getKey()])->assertSuccessful();
        Livewire::test(CreateTechnician::class)->assertSuccessful();
    }

    public function test_list_filters_by_level_and_status(): void
    {
        $this->asAdmin();
        $mentor = $this->technician(['level' => 'mentor']);
        $advanced = $this->technician(['level' => 'advanced', 'status' => 'inactive']);

        Livewire::test(ListTechnicians::class)
            ->filterTable('level', 'mentor')
            ->assertCanSeeTableRecords([$mentor])->assertCanNotSeeTableRecords([$advanced]);

        Livewire::test(ListTechnicians::class)
            ->filterTable('status', 'inactive')
            ->assertCanSeeTableRecords([$advanced])->assertCanNotSeeTableRecords([$mentor]);
    }

    public function test_admin_creates_technician_with_level_commission_and_status(): void
    {
        $this->asAdmin();

        Livewire::test(CreateTechnician::class)
            ->fillForm([
                'store_id' => $this->store->id, 'name' => 'Andi', 'level' => 'advanced',
                'commission_amount' => 150000, 'status' => 'active', 'phone' => '0812345',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tech = Technician::where('name', 'Andi')->firstOrFail();
        $this->assertSame('advanced', $tech->level);
        $this->assertSame('active', $tech->status);
        $this->assertEquals(150000, (float) $tech->commission_amount);
    }

    public function test_one_installer_account_can_only_link_to_one_technician(): void
    {
        $this->asAdmin();
        $installer = $this->user('installer');
        $this->technician(['user_id' => $installer->id]);

        Livewire::test(CreateTechnician::class)
            ->fillForm(['store_id' => $this->store->id, 'name' => 'Duplikat', 'level' => 'intermediate', 'status' => 'active', 'user_id' => $installer->id])
            ->call('create')
            ->assertHasFormErrors(['user_id' => 'unique']);

        $this->assertSame(0, Technician::where('name', 'Duplikat')->count());
    }

    // ------------------------------------------------------------- staf toko

    public function test_store_manager_registration_is_forced_to_own_store_and_pending_review(): void
    {
        $this->actingAs($this->user('store_manager'), 'web');

        Livewire::test(CreateTechnician::class)
            ->fillForm(['name' => 'Calon Teknisi', 'level' => 'mentor'])
            ->call('create')
            ->assertHasNoFormErrors();

        $tech = Technician::where('name', 'Calon Teknisi')->firstOrFail();
        $this->assertSame($this->store->id, $tech->store_id);
        $this->assertSame('pending_review', $tech->status, 'Staf toko tidak boleh langsung mengaktifkan teknisi.');
    }

    public function test_store_manager_sees_only_own_store_technicians(): void
    {
        $mine = $this->technician();
        $others = $this->technician(['store_id' => $this->otherStore->id]);

        $this->actingAs($this->user('store_manager'), 'web');

        Livewire::test(ListTechnicians::class)
            ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$others]);
    }

    // ------------------------------------------------------------- review

    public function test_admin_activates_pending_technician_and_notifies_the_installer(): void
    {
        $this->asAdmin();
        $installer = $this->user('installer');
        DeviceToken::create(['user_id' => $installer->id, 'token' => 'ExponentPushToken[tech-1]']);
        $tech = $this->technician(['status' => 'pending_review', 'user_id' => $installer->id]);

        Livewire::test(ListTechnicians::class)->callTableAction('approve', $tech);

        $this->assertSame('active', $tech->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'exp.host')
            && str_contains(json_encode($request->data()), 'Akun Teknisi Diaktifkan'));
    }

    public function test_admin_rejects_pending_technician_with_a_required_reason(): void
    {
        $this->asAdmin();
        $installer = $this->user('installer');
        DeviceToken::create(['user_id' => $installer->id, 'token' => 'ExponentPushToken[tech-2]']);
        $tech = $this->technician(['status' => 'pending_review', 'user_id' => $installer->id, 'notes' => 'Catatan lama']);

        Livewire::test(ListTechnicians::class)
            ->callTableAction('reject', $tech, data: ['review_note' => ''])
            ->assertHasTableActionErrors(['review_note' => 'required']);
        $this->assertSame('pending_review', $tech->fresh()->status);

        Livewire::test(ListTechnicians::class)->callTableAction('reject', $tech, data: ['review_note' => 'Sertifikat belum lengkap']);

        $fresh = $tech->fresh();
        $this->assertSame('inactive', $fresh->status);
        $this->assertStringContainsString('Catatan lama', $fresh->notes, 'Catatan lama tidak boleh hilang.');
        $this->assertStringContainsString('Ditolak: Sertifikat belum lengkap', $fresh->notes);
        Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), 'Pendaftaran Teknisi Ditolak'));
    }

    public function test_review_actions_are_hidden_for_non_admin_and_for_non_pending(): void
    {
        $pending = $this->technician(['status' => 'pending_review']);
        $active = $this->technician();

        $this->actingAs($this->user('store_manager'), 'web');
        Livewire::test(ListTechnicians::class)
            ->assertTableActionHidden('approve', $pending->fresh())
            ->assertTableActionHidden('reject', $pending->fresh());

        $this->asAdmin();
        Livewire::test(ListTechnicians::class)
            ->assertTableActionVisible('approve', $pending->fresh())
            ->assertTableActionHidden('approve', $active->fresh());
    }

    public function test_navigation_badge_counts_pending_technicians(): void
    {
        $this->asAdmin();
        $this->technician(['status' => 'pending_review']);
        $this->technician(['status' => 'pending_review']);
        $this->technician();

        $this->assertSame('2', TechnicianResource::getNavigationBadge());
    }

    public function test_role_orphan_detection(): void
    {
        $installer = $this->user('installer');
        $kasir = $this->user('kasir');

        $this->assertFalse($this->technician()->isRoleOrphaned(), 'Tanpa akun: tidak yatim.');
        $this->assertFalse($this->technician(['user_id' => $installer->id])->isRoleOrphaned());
        $this->assertTrue($this->technician(['user_id' => $kasir->id])->isRoleOrphaned(), 'Akun yang bukan installer lagi dianggap yatim.');
    }

    // ------------------------------------------------------------- tarif per layanan

    public function test_admin_manages_service_rates_and_duplicates_are_rejected(): void
    {
        $this->asAdmin();
        $tech = $this->technician();

        $component = Livewire::test(ServiceRatesRelationManager::class, ['ownerRecord' => $tech, 'pageClass' => EditTechnician::class])
            ->assertSuccessful()
            ->callTableAction(\Filament\Tables\Actions\CreateAction::class, data: ['service_type' => 'ppf', 'commission_amount' => 200000])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(200000, (float) TechnicianServiceRate::where('technician_id', $tech->id)->where('service_type', 'ppf')->value('commission_amount'));

        $component->callTableAction(\Filament\Tables\Actions\CreateAction::class, data: ['service_type' => 'ppf', 'commission_amount' => 999])
            ->assertHasTableActionErrors(['service_type' => 'unique']);
        $this->assertSame(1, TechnicianServiceRate::where('technician_id', $tech->id)->count());

        // Dengan tarif per layanan, komisi flat diabaikan (aturan model).
        $tech->update(['commission_amount' => 50000]);
        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(), 'customer_id' => Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)])->id,
            'store_id' => $this->store->id, 'service_type' => 'PPF', 'product_ppf' => true,
            'preferred_date' => now()->toDateString(), 'status' => 'completed',
        ]);
        $this->assertEquals(200000.0, $tech->fresh('serviceRates')->commissionForBooking($booking));
    }

    // ------------------------------------------------------------- penugasan installer

    public function test_assignable_installers_exclude_inactive_and_pending_technicians_and_show_level(): void
    {
        $manager = $this->user('store_manager');
        $good = $this->user('installer');
        $pending = $this->user('installer');
        $inactive = $this->user('installer');
        $noTechnicianRow = $this->user('installer');
        $otherStoreInstaller = $this->user('installer', $this->otherStore->id);

        $this->technician(['user_id' => $good->id, 'level' => 'mentor']);
        $this->technician(['user_id' => $pending->id, 'status' => 'pending_review']);
        $this->technician(['user_id' => $inactive->id, 'status' => 'inactive']);

        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(), 'customer_id' => Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)])->id,
            'store_id' => $this->store->id, 'service_type' => 'PPF', 'product_ppf' => true,
            'preferred_date' => now()->addDays(3)->toDateString(), 'status' => 'confirmed',
        ]);

        $installers = collect($this->actingAs($manager, 'api')->getJson("/api/staff/bookings/{$booking->id}/assignable-staff")
            ->assertSuccessful()->json('data.installers'));

        $this->assertSame('mentor', $installers->firstWhere('id', $good->id)['level']);
        $withoutRow = $installers->firstWhere('id', $noTechnicianRow->id);
        $this->assertNotNull($withoutRow, 'Installer tanpa baris teknisi tetap bisa dipilih.');
        $this->assertNull($withoutRow['level']);
        $this->assertNull($installers->firstWhere('id', $pending->id));
        $this->assertNull($installers->firstWhere('id', $inactive->id));
        $this->assertNull($installers->firstWhere('id', $otherStoreInstaller->id));
    }

    // ------------------------------------------------------------- laporan

    public function test_technician_report_pages_render(): void
    {
        $this->asAdmin();
        $installer = $this->user('installer');
        $this->technician(['user_id' => $installer->id]);

        Livewire::test(TechnicianCommissionReport::class)->assertSuccessful();
        Livewire::test(TechnicianServiceDurationReport::class)->assertSuccessful();
        Livewire::test(TechnicianUtilizationReport::class)->assertSuccessful();
    }
}
