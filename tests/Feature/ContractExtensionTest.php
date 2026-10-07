<?php

namespace Tests\Feature;

use App\Filament\Resources\ContractExtensionResource;
use App\Filament\Resources\ContractExtensionResource\Pages\CreateContractExtension;
use App\Filament\Resources\ContractExtensionResource\Pages\ListContractExtensions;
use App\Models\ContractExtension;
use App\Models\Store;
use App\Models\User;
use App\Services\PushNotificationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Perpanjang Kontrak: catat perpanjangan (tanggal baru harus lebih baru,
 * riwayat menyimpan tanggal sebelumnya, tanggal akhir karyawan ikut
 * berubah, karyawan diberi tahu), aturan siapa boleh mengelola siapa, daftar
 * & hapus, dan peringatan harian kontrak yang akan berakhir.
 */
class ContractExtensionTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'store_manager', 'partner'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        $user = User::create(array_merge([
            'name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->store)->id,
        ], $extra));
        $user->assignRole($role);

        return $user;
    }

    private function extend(User $employee, string $newEnd, ?User $by = null, ?string $notes = null): ContractExtension
    {
        return ContractExtension::recordExtension($employee, $newEnd, ($by ?? $this->user('super_admin'))->id, $notes);
    }

    // ------------------------------------------------------------- model

    public function test_the_first_contract_has_no_previous_date_and_sets_the_end_date(): void
    {
        $employee = $this->user('kasir');
        $newEnd = today()->addMonths(6)->toDateString();

        $extension = $this->extend($employee, $newEnd, null, 'Kontrak pertama');

        $this->assertNull($extension->previous_end_date);
        $this->assertSame($newEnd, $extension->new_end_date->toDateString());
        $this->assertSame($newEnd, $employee->fresh()->contract_end_date->toDateString());
    }

    public function test_each_extension_snapshots_the_previous_end_date(): void
    {
        $employee = $this->user('kasir', null, ['contract_end_date' => today()->addMonth()]);
        $first = today()->addMonths(7)->toDateString();
        $second = today()->addMonths(13)->toDateString();

        $this->extend($employee, $first);
        $latest = $this->extend($employee, $second);

        $this->assertSame($first, $latest->previous_end_date->toDateString());
        $this->assertSame($second, $employee->fresh()->contract_end_date->toDateString());
        $this->assertSame(2, ContractExtension::where('user_id', $employee->id)->count());
        $this->assertSame(today()->addMonth()->toDateString(), ContractExtension::where('user_id', $employee->id)->oldest('id')->first()->previous_end_date->toDateString());
    }

    public function test_a_new_date_that_does_not_extend_the_contract_is_rejected_and_changes_nothing(): void
    {
        $current = today()->addMonths(3);
        $employee = $this->user('kasir', null, ['contract_end_date' => $current]);

        foreach ([$current->toDateString(), $current->copy()->subDay()->toDateString(), today()->subMonth()->toDateString()] as $notLater) {
            try {
                $this->extend($employee, $notLater);
                $this->fail("Seharusnya ditolak: {$notLater}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('harus lebih baru', $e->getMessage());
            }
        }

        $this->assertSame($current->toDateString(), $employee->fresh()->contract_end_date->toDateString());
        $this->assertSame(0, ContractExtension::count());
    }

    // ------------------------------------------------------------- Filament: buat

    public function test_admin_records_an_extension_updates_the_employee_and_notifies_them(): void
    {
        $admin = $this->user('super_admin');
        $employee = $this->user('kasir', null, ['contract_end_date' => today()->addMonth()]);
        $newEnd = today()->addMonths(7)->toDateString();
        $this->mock(PushNotificationService::class, function ($mock) use ($employee) {
            $mock->shouldReceive('sendToUsers')->once()->withArgs(fn (array $ids, string $title, string $body) => $ids === [$employee->id] && $title === 'Kontrak Kerja Diperpanjang');
        });
        $this->actingAs($admin, 'web');

        Livewire::test(CreateContractExtension::class)
            ->fillForm(['user_id' => $employee->id, 'new_end_date' => $newEnd, 'notes' => 'Kinerja baik'])
            ->call('create')->assertHasNoFormErrors();

        $row = ContractExtension::where('user_id', $employee->id)->firstOrFail();
        $this->assertSame($admin->id, $row->extended_by);
        $this->assertSame('Kinerja baik', $row->notes);
        $this->assertSame($newEnd, $employee->fresh()->contract_end_date->toDateString());
        $this->assertTrue(Activity::where('log_name', 'contract_extension')->where('subject_id', $row->id)->where('event', 'created')->exists());
    }

    public function test_form_requires_an_employee_and_a_date_later_than_the_current_end(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $employee = $this->user('kasir', null, ['contract_end_date' => today()->addMonths(3)]);
        $create = fn (array $data) => Livewire::test(CreateContractExtension::class)->fillForm($data)->call('create');

        $create(['user_id' => null, 'new_end_date' => today()->addMonths(6)->toDateString()])->assertHasFormErrors(['user_id' => 'required']);
        $create(['user_id' => $employee->id, 'new_end_date' => null])->assertHasFormErrors(['new_end_date' => 'required']);
        $create(['user_id' => $employee->id, 'new_end_date' => today()->addMonth()->toDateString()])->assertHasFormErrors(['new_end_date']);
        $create(['user_id' => $employee->id, 'new_end_date' => today()->subDay()->toDateString()])->assertHasFormErrors(['new_end_date']);

        $this->assertSame(0, ContractExtension::count());
    }

    public function test_an_equal_date_passes_the_form_but_is_stopped_by_the_model_without_saving(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $current = today()->addMonths(3);
        $employee = $this->user('kasir', null, ['contract_end_date' => $current]);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldNotReceive('sendToUsers'));

        Livewire::test(CreateContractExtension::class)
            ->fillForm(['user_id' => $employee->id, 'new_end_date' => $current->toDateString()])
            ->call('create');

        $this->assertSame(0, ContractExtension::count());
        $this->assertSame($current->toDateString(), $employee->fresh()->contract_end_date->toDateString());
    }

    public function test_a_store_manager_cannot_extend_an_employee_of_another_store_or_a_partner(): void
    {
        $manager = $this->user('store_manager');
        $mine = $this->user('kasir');
        $foreigner = $this->user('kasir', $this->otherStore, ['contract_end_date' => today()->addMonth()]);
        $partner = $this->user('partner');
        $newEnd = today()->addMonths(6)->toDateString();
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once());
        $this->actingAs($manager, 'web');

        foreach ([$foreigner, $partner] as $blocked) {
            Livewire::test(CreateContractExtension::class)->fillForm(['user_id' => $blocked->id, 'new_end_date' => $newEnd])->call('create');
        }
        $this->assertSame(0, ContractExtension::count(), 'Karyawan toko lain dan partner tidak boleh diperpanjang.');
        $this->assertSame(today()->addMonth()->toDateString(), $foreigner->fresh()->contract_end_date->toDateString());

        Livewire::test(CreateContractExtension::class)->fillForm(['user_id' => $mine->id, 'new_end_date' => $newEnd])->call('create')->assertHasNoFormErrors();
        $this->assertSame(1, ContractExtension::count());
    }

    public function test_full_access_can_extend_an_employee_of_any_store(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $foreigner = $this->user('kasir', $this->otherStore);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once());

        Livewire::test(CreateContractExtension::class)
            ->fillForm(['user_id' => $foreigner->id, 'new_end_date' => today()->addMonths(6)->toDateString()])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame(1, ContractExtension::where('user_id', $foreigner->id)->count());
    }

    // ------------------------------------------------------------- daftar & hapus

    public function test_list_is_scoped_to_the_viewers_store(): void
    {
        $mine = $this->extend($this->user('kasir'), today()->addMonths(6)->toDateString());
        $theirs = $this->extend($this->user('kasir', $this->otherStore), today()->addMonths(6)->toDateString());

        $this->actingAs($this->user('store_manager'), 'web');
        Livewire::test(ListContractExtensions::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs]);

        $this->actingAs($this->user('super_admin'), 'web');
        Livewire::test(ListContractExtensions::class)->assertCanSeeTableRecords([$mine, $theirs]);
    }

    public function test_deleting_history_does_not_revert_the_employees_end_date_and_is_full_access_only(): void
    {
        $employee = $this->user('kasir');
        $newEnd = today()->addMonths(6)->toDateString();
        $row = $this->extend($employee, $newEnd);

        $this->actingAs($this->user('store_manager'), 'web');
        $this->assertFalse((bool) ContractExtensionResource::canDelete($row));
        Livewire::test(ListContractExtensions::class)->assertTableActionHidden('delete', $row);

        $this->actingAs($this->user('super_admin'), 'web');
        Livewire::test(ListContractExtensions::class)->callTableAction('delete', $row);

        $this->assertNull(ContractExtension::find($row->id));
        $this->assertSame($newEnd, $employee->fresh()->contract_end_date->toDateString(), 'Sesuai peringatan di modal: tanggal tidak dikembalikan otomatis.');
    }

    public function test_extensions_cannot_be_edited_and_permissions_follow_menu_access(): void
    {
        $row = $this->extend($this->user('kasir'), today()->addMonths(6)->toDateString());

        $this->actingAs($this->user('kasir'), 'web');
        $this->assertTrue(ContractExtensionResource::canViewAny());
        $this->assertTrue(ContractExtensionResource::canCreate());
        $this->assertFalse(ContractExtensionResource::canEdit($row), 'Riwayat tidak bisa diubah.');

        $this->actingAs($this->user('kasir', null, ['menu_access' => ['SomeOtherResource']]), 'web');
        $this->assertFalse(ContractExtensionResource::canViewAny());
        $this->assertFalse(ContractExtensionResource::canCreate());
    }

    // ------------------------------------------------------------- peringatan kontrak akan berakhir

    public function test_expiring_contracts_are_split_into_urgent_and_normal_and_sent_to_the_right_admins(): void
    {
        $admin = $this->user('super_admin');
        $menuHolder = $this->user('kasir');
        $noMenu = $this->user('kasir', null, ['menu_access' => ['SomeOtherResource']]);
        $urgent = $this->user('kasir', null, ['name' => 'Mendesak', 'contract_end_date' => today()->addDays(5)]);
        $normal = $this->user('kasir', null, ['name' => 'Biasa', 'contract_end_date' => today()->addDays(20)]);
        $this->user('kasir', null, ['name' => 'Jauh', 'contract_end_date' => today()->addDays(45)]);
        $this->user('kasir', null, ['name' => 'Sudah Lewat', 'contract_end_date' => today()->subDays(3)]);
        $this->user('kasir', null, ['name' => 'Nonaktif', 'contract_end_date' => today()->addDays(10), 'is_active' => false]);
        $this->user('kasir', null, ['name' => 'Tanpa Kontrak']);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->twice());

        $this->artisan('contracts:notify-expiring')->assertSuccessful();

        $titles = $admin->notifications()->get()->pluck('data.title')->all();
        $this->assertEqualsCanonicalizing(['Kontrak Karyawan SEGERA Berakhir (≤7 hari)', 'Kontrak Karyawan Akan Berakhir (≤30 hari)'], $titles);
        $this->assertSame(2, $menuHolder->notifications()->count());
        $this->assertSame(0, $noMenu->notifications()->count());

        $urgentBody = $admin->notifications()->get()->first(fn ($n) => str_contains($n->data['title'], 'SEGERA'))->data['body'];
        $this->assertStringContainsString('Mendesak', $urgentBody);
        $this->assertStringNotContainsString('Biasa', $urgentBody);
        $this->assertNotNull($normal);
        $this->assertNotNull($urgent);
    }

    public function test_each_expiring_employee_gets_their_own_push_with_the_days_left(): void
    {
        $employee = $this->user('kasir', null, ['contract_end_date' => today()->addDays(12)]);
        $this->user('super_admin');
        $this->mock(PushNotificationService::class, function ($mock) use ($employee) {
            $mock->shouldReceive('sendToUsers')->once()->withArgs(fn (array $ids, string $title, string $body) => $ids === [$employee->id]
                && $title === 'Kontrak Kerja Anda Akan Berakhir' && str_contains($body, '12 hari lagi'));
        });

        $this->artisan('contracts:notify-expiring')->assertSuccessful();
    }

    public function test_the_warning_is_silent_when_nothing_is_expiring_and_stops_after_an_extension(): void
    {
        $admin = $this->user('super_admin');
        $employee = $this->user('kasir', null, ['contract_end_date' => today()->addDays(10)]);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once());

        $this->artisan('contracts:notify-expiring')->assertSuccessful();
        $this->assertSame(1, $admin->notifications()->count());

        $this->extend($employee, today()->addMonths(8)->toDateString(), $admin);
        $this->artisan('contracts:notify-expiring')->assertSuccessful();

        $this->assertSame(1, $admin->notifications()->count(), 'Setelah diperpanjang, karyawan keluar dari jendela 30 hari.');
    }
}
