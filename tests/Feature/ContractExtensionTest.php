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

    /** Store manager yang menu Riwayat Kontrak-nya dicentang dan boleh mencatat perpanjangan (akun bawaan tidak punya akses menu ini). */
    private function manager(?Store $store = null, array $permissions = ['create']): User
    {
        return $this->user('store_manager', $store, [
            'menu_access' => [ContractExtensionResource::class],
            'menu_permissions' => [ContractExtensionResource::class => $permissions],
        ]);
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
        $employee = $this->user('kasir', null, ['employee_type_id' => $this->contractType()->id, 'contract_end_date' => today()->addMonth()]);
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
        $employee = $this->user('kasir', null, ['employee_type_id' => $this->contractType()->id, 'contract_end_date' => today()->addMonths(3)]);
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
        $employee = $this->user('kasir', null, ['employee_type_id' => $this->contractType()->id, 'contract_end_date' => $current]);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldNotReceive('sendToUsers'));

        Livewire::test(CreateContractExtension::class)
            ->fillForm(['user_id' => $employee->id, 'new_end_date' => $current->toDateString()])
            ->call('create');

        $this->assertSame(0, ContractExtension::count());
        $this->assertSame($current->toDateString(), $employee->fresh()->contract_end_date->toDateString());
    }

    public function test_a_store_manager_cannot_extend_an_employee_of_another_store_or_a_partner(): void
    {
        $manager = $this->manager();
        $mine = $this->user('kasir', null, ['employee_type_id' => $this->contractType()->id]);
        $foreigner = $this->user('kasir', $this->otherStore, ['employee_type_id' => $this->contractType()->id, 'contract_end_date' => today()->addMonth()]);
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
        $foreigner = $this->user('kasir', $this->otherStore, ['employee_type_id' => $this->contractType()->id]);
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

        $this->actingAs($this->manager(), 'web');
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

        $this->actingAs($this->manager(), 'web');
        $this->assertFalse((bool) ContractExtensionResource::canDelete($row), 'Tanpa izin hapus eksplisit.');
        Livewire::test(ListContractExtensions::class)->assertTableActionHidden('delete', $row);

        $this->actingAs($this->user('super_admin'), 'web');
        Livewire::test(ListContractExtensions::class)->callTableAction('delete', $row);

        $this->assertNull(ContractExtension::find($row->id));
        $this->assertSame($newEnd, $employee->fresh()->contract_end_date->toDateString(), 'Sesuai peringatan di modal: tanggal tidak dikembalikan otomatis.');
    }

    public function test_extensions_cannot_be_edited_and_permissions_follow_menu_access(): void
    {
        $row = $this->extend($this->user('kasir'), today()->addMonths(6)->toDateString());

        $default = $this->user('kasir');
        $this->assertNull($default->menu_access);
        $this->actingAs($default, 'web');
        $this->assertFalse(ContractExtensionResource::canViewAny(), 'Akun bawaan tidak membuka riwayat kontrak rekan kerja.');
        $this->assertFalse(ContractExtensionResource::canCreate());
        $this->assertFalse((bool) ContractExtensionResource::canDelete($row));
        Livewire::test(CreateContractExtension::class)->assertForbidden();

        $this->actingAs($this->user('kasir', null, ['menu_access' => ['SomeOtherResource']]), 'web');
        $this->assertFalse(ContractExtensionResource::canViewAny());
        $this->assertFalse(ContractExtensionResource::canCreate());

        $viewer = $this->user('kasir', null, ['menu_access' => [ContractExtensionResource::class]]);
        $this->actingAs($viewer, 'web');
        $this->assertTrue(ContractExtensionResource::canViewAny());
        $this->assertFalse(ContractExtensionResource::canCreate(), 'Hanya melihat tanpa izin catat.');
        $this->assertFalse(ContractExtensionResource::canEdit($row), 'Riwayat tidak bisa diubah.');

        $maintainer = $this->manager(null, ['create', 'delete']);
        $this->actingAs($maintainer, 'web');
        $this->assertTrue(ContractExtensionResource::canCreate());
        $this->assertTrue((bool) ContractExtensionResource::canDelete($row));
        $this->assertFalse(ContractExtensionResource::canEdit($row));
    }

    // ------------------------------------------------------------- peringatan kontrak akan berakhir

    public function test_expiring_contracts_are_split_into_urgent_and_normal_and_sent_to_the_right_admins(): void
    {
        $admin = $this->user('super_admin');
        $menuHolder = $this->user('kasir', null, ['menu_access' => [ContractExtensionResource::class]]);
        $plain = $this->user('kasir');
        $noMenu = $this->user('kasir', null, ['menu_access' => ['SomeOtherResource']]);
        $type = $this->contractType()->id;
        $urgent = $this->user('kasir', null, ['name' => 'Mendesak', 'employee_type_id' => $type, 'contract_end_date' => today()->addDays(5)]);
        $normal = $this->user('kasir', null, ['name' => 'Biasa', 'employee_type_id' => $type, 'contract_end_date' => today()->addDays(20)]);
        $this->user('kasir', null, ['name' => 'Jauh', 'employee_type_id' => $type, 'contract_end_date' => today()->addDays(45)]);
        $this->user('kasir', null, ['name' => 'Sudah Lewat', 'employee_type_id' => $type, 'contract_end_date' => today()->subDays(3)]);
        $this->user('kasir', null, ['name' => 'Nonaktif', 'employee_type_id' => $type, 'contract_end_date' => today()->addDays(10), 'is_active' => false]);
        $this->user('kasir', null, ['name' => 'Tanpa Kontrak']);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->twice());

        $this->artisan('contracts:notify-expiring')->assertSuccessful();

        $this->assertSame(['Kontrak Karyawan Akan Berakhir'], $admin->notifications()->get()->pluck('data.title')->all(), 'Satu ringkasan, bukan satu pesan per kelompok.');
        $this->assertSame(1, $menuHolder->notifications()->count());
        $this->assertSame(0, $noMenu->notifications()->count());
        $this->assertSame(0, $plain->notifications()->count(), 'Akun bawaan tidak menerima nama dan tanggal kontrak karyawan lain.');

        $notification = $admin->notifications()->first();
        $this->assertSame('danger', $notification->data['color'], 'Ada yang tinggal 7 hari: ringkasan ditandai mendesak.');
        [$urgentPart, $normalPart] = explode('Dalam 30 hari', $notification->data['body']);
        $this->assertStringContainsString('SEGERA', $urgentPart);
        $this->assertStringContainsString('Mendesak', $urgentPart);
        $this->assertStringNotContainsString('Biasa', $urgentPart);
        $this->assertStringContainsString('Biasa', $normalPart);
        $this->assertNotNull($normal);
        $this->assertNotNull($urgent);
    }

    public function test_an_employee_is_reminded_only_at_30_14_7_3_and_1_days_before_the_end_and_it_lands_in_their_inbox(): void
    {
        $type = $this->contractType()->id;
        $dueDays = [30, 14, 7, 3, 1];
        $due = collect($dueDays)->map(fn ($days) => $this->user('kasir', null, ['employee_type_id' => $type, 'contract_end_date' => today()->addDays($days)]));
        $between = collect([20, 10, 5, 2])->map(fn ($days) => $this->user('kasir', null, ['employee_type_id' => $type, 'contract_end_date' => today()->addDays($days)]));

        $pushes = [];
        $this->mock(PushNotificationService::class, function ($mock) use (&$pushes) {
            $mock->shouldReceive('sendToUsers')->andReturnUsing(function (array $ids, string $title, string $body) use (&$pushes) {
                $pushes[] = [$ids[0], $title, $body];
            });
        });

        $this->artisan('contracts:notify-expiring')->assertSuccessful();

        $selfPushes = collect($pushes)->where(1, 'Kontrak Kerja Anda Akan Berakhir');
        $this->assertEqualsCanonicalizing($due->pluck('id')->all(), $selfPushes->pluck(0)->all());
        $this->assertStringContainsString('14 hari lagi', $selfPushes->firstWhere(0, $due[1]->id)[2]);
        $this->assertSame(1, $due[1]->notifications()->count(), 'Juga masuk kotak masuk, bukan push saja.');
        foreach ($between as $skipped) {
            $this->assertSame(0, $skipped->notifications()->count(), "{$skipped->contract_end_date->format('d M')} bukan hari pengingat.");
        }
    }

    public function test_the_warning_is_silent_when_nothing_is_expiring_and_stops_after_an_extension(): void
    {
        $admin = $this->user('super_admin');
        $employee = $this->user('kasir', null, ['employee_type_id' => $this->contractType()->id, 'contract_end_date' => today()->addDays(10)]);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once());

        $this->artisan('contracts:notify-expiring')->assertSuccessful();
        $this->assertSame(1, $admin->notifications()->count());

        $this->extend($employee, today()->addMonths(8)->toDateString(), $admin);
        $this->artisan('contracts:notify-expiring')->assertSuccessful();

        $this->assertSame(1, $admin->notifications()->count(), 'Setelah diperpanjang, karyawan keluar dari jendela 30 hari.');
    }

    public function test_the_daily_summary_updates_one_unread_row_instead_of_stacking_and_starts_a_new_one_after_it_is_read(): void
    {
        $admin = $this->user('super_admin');
        $this->user('kasir', null, ['name' => 'Budi', 'employee_type_id' => $this->contractType()->id, 'contract_end_date' => today()->addDays(20)]);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers'));

        foreach (range(1, 3) as $run) {
            $this->artisan('contracts:notify-expiring')->assertSuccessful();
        }
        $this->assertSame(1, $admin->notifications()->count(), 'Tiga hari berturut-turut, belum dibaca: tetap satu baris.');

        $admin->unreadNotifications->markAsRead();
        $this->artisan('contracts:notify-expiring')->assertSuccessful();

        $this->assertSame(2, $admin->notifications()->count(), 'Sesudah dibaca, ringkasan berikutnya baris baru.');
    }

    public function test_a_contract_that_just_lapsed_is_reminded_for_seven_days_only_to_menu_holders_for_accounts_deactivated_by_the_lapse(): void
    {
        $type = $this->contractType()->id;
        $admin = $this->user('super_admin');
        $plain = $this->user('kasir');
        $lapsedYesterday = $this->user('kasir', null, ['name' => 'Lewat Kemarin', 'employee_type_id' => $type, 'contract_end_date' => today()->subDay()]);
        $lapsedLong = $this->user('kasir', null, ['name' => 'Lewat Lama', 'employee_type_id' => $type, 'contract_end_date' => today()->subDays(20)]);
        $resigned = $this->user('kasir', null, ['name' => 'Sudah Resign', 'employee_type_id' => $type, 'contract_end_date' => today()->subDays(2), 'is_active' => false, 'last_work_date' => today()->subDays(30)]);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers'));
        $this->artisan('contracts:deactivate-expired')->assertSuccessful();   // Lewat Kemarin dan Lewat Lama dinonaktifkan otomatis

        $this->artisan('contracts:notify-expiring')->assertSuccessful();

        $message = $admin->notifications()->get()->first(fn ($n) => $n->data['title'] === 'Kontrak Karyawan Sudah Berakhir');
        $this->assertNotNull($message);
        $this->assertSame('danger', $message->data['color']);
        $this->assertStringContainsString('Lewat Kemarin', $message->data['body']);
        $this->assertStringNotContainsString('Lewat Lama', $message->data['body'], 'Lebih dari 7 hari lalu tidak diingatkan lagi.');
        $this->assertStringNotContainsString('Sudah Resign', $message->data['body'], 'Berhenti karena alasan lain bukan kontrak yang lewat.');
        $this->assertSame(0, $plain->notifications()->count());
        $this->assertNotNull($lapsedYesterday);
        $this->assertNotNull($lapsedLong);
        $this->assertNotNull($resigned);
    }

    public function test_a_menu_holder_who_is_not_full_access_is_only_told_about_their_own_stores_contracts(): void
    {
        $admin = $this->user('super_admin');
        $manager = $this->manager();
        $this->user('kasir', null, ['name' => 'Karyawan Toko A', 'employee_type_id' => $this->contractType()->id, 'contract_end_date' => today()->addDays(5)]);
        $this->user('kasir', $this->otherStore, ['name' => 'Karyawan Toko B', 'employee_type_id' => $this->contractType()->id, 'contract_end_date' => today()->addDays(5)]);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->twice());

        $this->artisan('contracts:notify-expiring')->assertSuccessful();

        $adminBody = $admin->notifications()->first()->data['body'];
        $this->assertStringContainsString('Karyawan Toko A', $adminBody);
        $this->assertStringContainsString('Karyawan Toko B', $adminBody);

        $managerBody = $manager->notifications()->first()->data['body'];
        $this->assertStringContainsString('Karyawan Toko A', $managerBody);
        $this->assertStringNotContainsString('Karyawan Toko B', $managerBody);
        $this->assertStringContainsString('1 karyawan', $managerBody);
        $this->assertStringContainsString('2 karyawan', $adminBody);
    }

    // ------------------------------------------------------------- audit Riwayat Kontrak B: penonaktifan otomatis dan perpanjangan terlambat

    private function contractType(): \App\Models\EmployeeType
    {
        return \App\Models\EmployeeType::firstOrCreate(['name' => 'Kontrak'], ['has_end_date' => true, 'is_active' => true]);
    }

    public function test_automatic_deactivation_fills_the_last_work_date_so_the_final_month_stays_payable(): void
    {
        $type = $this->contractType();
        $ended = $this->user('kasir', null, ['employee_type_id' => $type->id, 'contract_end_date' => today()->subDays(3)]);
        $earlier = $this->user('kasir', null, ['employee_type_id' => $type->id, 'contract_end_date' => today()->subDays(3), 'last_work_date' => today()->subDays(10)]);

        $this->artisan('contracts:deactivate-expired')->assertSuccessful();

        $this->assertFalse($ended->fresh()->is_active);
        $this->assertSame(today()->subDays(3)->toDateString(), $ended->fresh()->last_work_date->toDateString());
        $this->assertSame(today()->subDays(10)->toDateString(), $earlier->fresh()->last_work_date->toDateString(), 'Hari Kerja Terakhir yang sudah diisi tidak ditimpa.');
    }

    public function test_the_backfill_command_lists_candidates_first_and_only_writes_with_apply(): void
    {
        $type = $this->contractType();
        $permanent = \App\Models\EmployeeType::create(['name' => 'Tetap', 'has_end_date' => false, 'is_active' => true]);
        $eligible = $this->user('kasir', null, ['employee_type_id' => $type->id, 'contract_end_date' => today()->subMonths(2), 'is_active' => false]);
        $hasLastDay = $this->user('kasir', null, ['employee_type_id' => $type->id, 'contract_end_date' => today()->subMonths(2), 'is_active' => false, 'last_work_date' => today()->subMonths(3)]);
        $tooOld = $this->user('kasir', null, ['employee_type_id' => $type->id, 'contract_end_date' => today()->subMonths(20), 'is_active' => false]);
        $notContract = $this->user('kasir', null, ['employee_type_id' => $permanent->id, 'contract_end_date' => today()->subMonths(2), 'is_active' => false]);
        $stillActive = $this->user('kasir', null, ['employee_type_id' => $type->id, 'contract_end_date' => today()->subMonths(2)]);

        $this->artisan('contracts:backfill-last-work-date')->assertSuccessful();
        $this->assertNull($eligible->fresh()->last_work_date, 'Tanpa --apply tidak menulis apa pun.');

        $this->artisan('contracts:backfill-last-work-date', ['--apply' => true])->assertSuccessful();

        $this->assertSame(today()->subMonths(2)->toDateString(), $eligible->fresh()->last_work_date->toDateString());
        $this->assertSame(today()->subMonths(3)->toDateString(), $hasLastDay->fresh()->last_work_date->toDateString());
        $this->assertNull($tooOld->fresh()->last_work_date);
        $this->assertNull($notContract->fresh()->last_work_date);
        $this->assertNull($stillActive->fresh()->last_work_date);
    }

    public function test_a_new_end_date_that_is_already_past_is_rejected_even_when_the_old_contract_lapsed_long_ago(): void
    {
        $employee = $this->user('kasir', null, ['contract_end_date' => today()->subDays(30)]);

        try {
            $this->extend($employee, today()->subDays(5)->toDateString());
            $this->fail('Tanggal yang sudah lewat seharusnya ditolak.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('sudah lewat', $e->getMessage());
        }

        $this->assertSame(0, ContractExtension::count());

        $this->extend($employee, today()->toDateString());
        $this->assertSame(today()->toDateString(), $employee->fresh()->contract_end_date->toDateString(), 'Hari ini masih diterima.');
    }

    public function test_a_late_extension_reactivates_an_account_that_was_deactivated_automatically(): void
    {
        $type = $this->contractType();
        $employee = $this->user('kasir', null, ['employee_type_id' => $type->id, 'contract_end_date' => today()->subDay()]);
        $this->artisan('contracts:deactivate-expired')->assertSuccessful();
        $this->assertFalse($employee->fresh()->is_active);

        $extension = $this->extend($employee, today()->addMonths(6)->toDateString());

        $this->assertTrue($extension->reactivatedUser);
        $this->assertTrue($employee->fresh()->is_active);
        $this->assertNull($employee->fresh()->last_work_date, 'Hari Kerja Terakhir dari penonaktifan otomatis dibersihkan.');
        $this->assertSame(today()->addMonths(6)->toDateString(), $employee->fresh()->contract_end_date->toDateString());
    }

    public function test_an_extension_does_not_reactivate_someone_who_left_for_another_reason(): void
    {
        $type = $this->contractType();
        $resigned = $this->user('kasir', null, ['employee_type_id' => $type->id, 'contract_end_date' => today()->subDay(), 'is_active' => false, 'last_work_date' => today()->subMonths(2)]);
        $leftBeforeEnd = $this->user('kasir', null, ['employee_type_id' => $type->id, 'contract_end_date' => today()->addMonth(), 'is_active' => false]);
        $active = $this->user('kasir', null, ['employee_type_id' => $type->id, 'contract_end_date' => today()->addMonth()]);

        foreach ([$resigned, $leftBeforeEnd, $active] as $employee) {
            $this->assertFalse($this->extend($employee, today()->addMonths(6)->toDateString())->reactivatedUser);
        }

        $this->assertFalse($resigned->fresh()->is_active);
        $this->assertSame(today()->subMonths(2)->toDateString(), $resigned->fresh()->last_work_date->toDateString());
        $this->assertFalse($leftBeforeEnd->fresh()->is_active);
        $this->assertTrue($active->fresh()->is_active);
    }

    public function test_the_create_page_tells_the_admin_when_an_extension_reactivated_the_account(): void
    {
        $type = $this->contractType();
        $employee = $this->user('kasir', null, ['employee_type_id' => $type->id, 'contract_end_date' => today()->subDay()]);
        $this->artisan('contracts:deactivate-expired')->assertSuccessful();
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once());
        $this->actingAs($this->user('super_admin'), 'web');

        Livewire::test(CreateContractExtension::class)
            ->fillForm(['user_id' => $employee->id, 'new_end_date' => today()->addMonths(6)->toDateString()])
            ->call('create')->assertHasNoFormErrors()
            ->assertNotified('Akun diaktifkan kembali');

        $this->assertTrue($employee->fresh()->is_active);
    }

    // ------------------------------------------------------------- audit Riwayat Kontrak C: alur kontrak per tipe karyawan

    public function test_the_employee_choices_only_offer_active_contract_staff_in_scope_plus_those_deactivated_by_a_lapsed_contract(): void
    {
        $contract = $this->contractType();
        $permanent = \App\Models\EmployeeType::create(['name' => 'Tetap', 'has_end_date' => false, 'is_active' => true]);
        $ok = $this->user('kasir', null, ['name' => 'Kontrak Aktif', 'employee_type_id' => $contract->id]);
        $lapsed = $this->user('kasir', null, ['name' => 'Kontrak Lewat', 'employee_type_id' => $contract->id, 'contract_end_date' => today()->subDay(), 'is_active' => false]);
        $resigned = $this->user('kasir', null, ['name' => 'Sudah Resign', 'employee_type_id' => $contract->id, 'contract_end_date' => today()->subDay(), 'is_active' => false, 'last_work_date' => today()->subMonth()]);
        $tetap = $this->user('kasir', null, ['name' => 'Karyawan Tetap', 'employee_type_id' => $permanent->id]);
        $noType = $this->user('kasir', null, ['name' => 'Tanpa Tipe']);
        $partner = $this->user('partner', null, ['name' => 'Mitra', 'employee_type_id' => $contract->id]);
        $foreign = $this->user('kasir', $this->otherStore, ['name' => 'Toko Lain', 'employee_type_id' => $contract->id]);

        $this->actingAs($this->user('super_admin'), 'web');
        $options = ContractExtensionResource::employeeOptions();
        $this->assertArrayHasKey($ok->id, $options);
        $this->assertArrayHasKey($lapsed->id, $options);
        $this->assertStringContainsString('nonaktif', $options[$lapsed->id]);
        $this->assertArrayHasKey($foreign->id, $options);
        foreach ([$resigned, $tetap, $noType, $partner] as $excluded) {
            $this->assertArrayNotHasKey($excluded->id, $options, $excluded->name);
        }

        $this->actingAs($this->manager(), 'web');
        $this->assertArrayNotHasKey($foreign->id, ContractExtensionResource::employeeOptions(), 'Store manager hanya karyawan tokonya.');
    }

    public function test_the_create_page_refuses_a_permanent_employee_even_if_the_request_is_forged(): void
    {
        $permanent = \App\Models\EmployeeType::create(['name' => 'Tetap', 'has_end_date' => false, 'is_active' => true]);
        $tetap = $this->user('kasir', null, ['employee_type_id' => $permanent->id]);
        $this->actingAs($this->user('super_admin'), 'web');

        Livewire::test(CreateContractExtension::class)
            ->fillForm(['user_id' => $tetap->id, 'new_end_date' => today()->addMonths(6)->toDateString()])
            ->call('create');

        $this->assertSame(0, ContractExtension::count());
        $this->assertNull($tetap->fresh()->contract_end_date);
    }

    public function test_promoting_to_permanent_clears_the_contract_date_changes_the_type_and_is_logged(): void
    {
        $contract = $this->contractType();
        $permanent = \App\Models\EmployeeType::create(['name' => 'Tetap', 'has_end_date' => false, 'is_active' => true]);
        $employee = $this->user('kasir', null, ['employee_type_id' => $contract->id, 'contract_end_date' => today()->addMonths(2)]);
        $admin = $this->user('super_admin');
        $this->mock(PushNotificationService::class, function ($mock) use ($employee) {
            $mock->shouldReceive('sendToUsers')->once()->withArgs(fn (array $ids, string $title) => $ids === [$employee->id] && $title === 'Status Kepegawaian Berubah');
        });
        $this->actingAs($admin, 'web');

        Livewire::test(ListContractExtensions::class)
            ->callAction('makePermanent', ['user_id' => $employee->id, 'employee_type_id' => $permanent->id, 'notes' => 'Lolos evaluasi'])
            ->assertNotified('Diangkat jadi karyawan tetap');

        $employee->refresh();
        $this->assertSame($permanent->id, $employee->employee_type_id);
        $this->assertNull($employee->contract_end_date);
        $this->assertTrue($employee->is_active);

        $log = Activity::where('log_name', 'contract_extension')->where('subject_id', $employee->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('diangkat menjadi karyawan tetap', $log->description);
        $this->assertSame('Kontrak', $log->properties['previous_type']);
        $this->assertSame($admin->id, $log->causer_id);

        $employee->update(['contract_end_date' => today()->addDays(5)]);   // sisa tanggal nyasar tidak lagi memicu peringatan
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldNotReceive('sendToUsers'));
        $this->artisan('contracts:notify-expiring')->assertSuccessful();
        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_promoting_reactivates_an_account_deactivated_by_a_lapsed_contract_but_rejects_contract_types_and_non_contract_staff(): void
    {
        $contract = $this->contractType();
        $permanent = \App\Models\EmployeeType::create(['name' => 'Tetap', 'has_end_date' => false, 'is_active' => true]);
        $lapsed = $this->user('kasir', null, ['employee_type_id' => $contract->id, 'contract_end_date' => today()->subDay()]);
        $this->artisan('contracts:deactivate-expired')->assertSuccessful();
        $this->assertFalse($lapsed->fresh()->is_active);

        $updated = ContractExtension::convertToPermanent($lapsed, $permanent, null);

        $this->assertTrue($updated->fresh()->is_active);
        $this->assertNull($updated->fresh()->last_work_date);

        $this->assertThrows(fn () => ContractExtension::convertToPermanent($this->user('kasir', null, ['employee_type_id' => $contract->id]), $contract, null), \InvalidArgumentException::class);
        $this->assertThrows(fn () => ContractExtension::convertToPermanent($this->user('kasir', null, ['employee_type_id' => $permanent->id]), $permanent, null), \InvalidArgumentException::class);
        $resigned = $this->user('kasir', null, ['employee_type_id' => $contract->id, 'is_active' => false, 'last_work_date' => today()->subMonth()]);
        $this->assertThrows(fn () => ContractExtension::convertToPermanent($resigned, $permanent, null), \InvalidArgumentException::class);
        $this->assertFalse($resigned->fresh()->is_active);
    }

    public function test_the_profile_and_dashboard_ignore_a_stray_contract_date_on_permanent_staff(): void
    {
        $permanent = \App\Models\EmployeeType::create(['name' => 'Tetap', 'has_end_date' => false, 'is_active' => true]);
        $contract = $this->contractType();
        $tetap = $this->user('kasir', null, ['employee_type_id' => $permanent->id, 'contract_end_date' => today()->addDays(10)]);
        $kontrak = $this->user('kasir', null, ['name' => 'Kontrak Asli', 'employee_type_id' => $contract->id, 'contract_end_date' => today()->addDays(10)]);

        $profile = $this->actingAs($tetap, 'api')->getJson('/api/staff/profile')->json('profile');
        $this->assertNull($profile['employment']['contract_end_date']);
        $this->assertNull($profile['employment']['contract_days_left']);

        $profile = $this->actingAs($kontrak, 'api')->getJson('/api/staff/profile')->json('profile');
        $this->assertSame(10, $profile['employment']['contract_days_left']);

        $summary = (new \App\Services\HrDashboardService())->summary();
        $this->assertSame(['Kontrak Asli'], array_column($summary['contracts_expiring'], 'name'));
    }

    // ------------------------------------------------------------- audit Riwayat Kontrak D: daftar dan kontrak pertama

    public function test_the_list_shows_store_status_extension_number_and_contract_tenure(): void
    {
        $type = $this->contractType();
        $employee = $this->user('kasir', null, ['employee_type_id' => $type->id, 'join_date' => today()->subYears(2), 'contract_end_date' => today()->addMonths(2)]);
        $first = $this->extend($employee, today()->addMonths(5)->toDateString());
        $second = $this->extend($employee, today()->addMonths(10)->toDateString());
        $this->actingAs($this->user('super_admin'), 'web');

        $rows = ContractExtensionResource::getEloquentQuery()->where('user_id', $employee->id)->orderBy('id')->get();

        $this->assertSame([1, 2], $rows->map(fn ($r) => (int) $r->extension_number)->all());

        Livewire::test(ListContractExtensions::class)
            ->assertCanSeeTableRecords([$first, $second])
            ->assertTableColumnStateSet('user.store.name', 'Toko A', $second)
            ->assertTableColumnStateSet('status', 'Diganti kontrak berikutnya', $rows[0])
            ->assertTableColumnStateSet('status', 'Berlaku, ' . today()->diffInDays(today()->addMonths(10), false) . ' hari lagi', $rows[1]);

        $this->assertStringStartsWith('2 th', (new \ReflectionMethod(ContractExtensionResource::class, 'tenureLabel'))->invoke(null, $rows[1]));
    }

    public function test_the_list_filters_by_employee_store_date_and_kind(): void
    {
        $type = $this->contractType();
        $mine = $this->user('kasir', null, ['employee_type_id' => $type->id]);
        $theirs = $this->user('kasir', $this->otherStore, ['employee_type_id' => $type->id]);
        $a = $this->extend($mine, today()->addMonths(3)->toDateString());
        $b = $this->extend($mine, today()->addMonths(9)->toDateString());
        $c = $this->extend($theirs, today()->addMonths(4)->toDateString());
        $this->actingAs($this->user('super_admin'), 'web');

        Livewire::test(ListContractExtensions::class)
            ->filterTable('user_id', $theirs->id)->assertCanSeeTableRecords([$c])->assertCanNotSeeTableRecords([$a, $b])
            ->removeTableFilters()
            ->filterTable('store', $this->store->id)->assertCanSeeTableRecords([$a, $b])->assertCanNotSeeTableRecords([$c])
            ->removeTableFilters()
            ->filterTable('new_end_date', ['from' => today()->addMonths(5)->toDateString(), 'until' => null])->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c])
            ->removeTableFilters()
            ->filterTable('first_contract', true)->assertCanSeeTableRecords([$a, $c])->assertCanNotSeeTableRecords([$b]);
    }

    public function test_a_first_contract_entered_on_the_user_form_is_added_to_the_history_once(): void
    {
        $type = $this->contractType();
        $employee = $this->user('kasir', null, ['employee_type_id' => $type->id, 'contract_end_date' => today()->addMonths(6)]);
        $permanent = $this->user('kasir', null, ['employee_type_id' => \App\Models\EmployeeType::create(['name' => 'Tetap', 'has_end_date' => false, 'is_active' => true])->id, 'contract_end_date' => today()->addMonth()]);
        $admin = $this->user('super_admin');

        $row = ContractExtension::recordFirstContractIfMissing($employee, $admin->id);

        $this->assertNotNull($row);
        $this->assertNull($row->previous_end_date);
        $this->assertSame(today()->addMonths(6)->toDateString(), $row->new_end_date->toDateString());
        $this->assertSame(today()->addMonths(6)->toDateString(), $employee->fresh()->contract_end_date->toDateString(), 'Karyawan tidak diubah.');
        $this->assertNull(ContractExtension::recordFirstContractIfMissing($employee), 'Sudah ada riwayat.');
        $this->assertNull(ContractExtension::recordFirstContractIfMissing($permanent), 'Karyawan tetap tidak punya riwayat kontrak.');
        $this->assertStringContainsString('Kontrak pertama', Activity::where('log_name', 'contract_extension')->where('subject_id', $row->id)->first()->description);
    }

    public function test_the_history_backfill_lists_candidates_first_and_only_writes_with_apply(): void
    {
        $type = $this->contractType();
        $needs = $this->user('kasir', null, ['employee_type_id' => $type->id, 'contract_end_date' => today()->addMonths(4)]);
        $hasHistory = $this->user('kasir', null, ['employee_type_id' => $type->id, 'contract_end_date' => today()->addMonth()]);
        $this->extend($hasHistory, today()->addMonths(3)->toDateString());
        $this->user('kasir', null, ['employee_type_id' => $type->id]);                                   // tanpa tanggal
        $this->user('kasir', null, ['employee_type_id' => $type->id, 'contract_end_date' => today()->addMonth(), 'is_active' => false]);

        $this->artisan('contracts:backfill-history')->assertSuccessful();
        $this->assertSame(1, ContractExtension::count(), 'Tanpa --apply tidak menulis apa pun.');

        $this->artisan('contracts:backfill-history', ['--apply' => true])->assertSuccessful();

        $this->assertSame(2, ContractExtension::count());
        $this->assertSame(1, ContractExtension::where('user_id', $needs->id)->count());
        $this->artisan('contracts:backfill-history', ['--apply' => true])->assertSuccessful();
        $this->assertSame(2, ContractExtension::count(), 'Idempoten.');
    }

    public function test_the_profile_lists_the_employees_own_contract_dates_newest_first_without_hr_notes(): void
    {
        $employee = $this->user('kasir', null, ['employee_type_id' => $this->contractType()->id, 'contract_end_date' => today()->addMonth()]);
        $other = $this->user('kasir', null, ['employee_type_id' => $this->contractType()->id]);
        $this->extend($employee, today()->addMonths(6)->toDateString(), null, 'Catatan internal HR');
        $this->extend($employee, today()->addMonths(12)->toDateString());
        $this->extend($other, today()->addMonths(3)->toDateString());

        $history = $this->actingAs($employee, 'api')->getJson('/api/staff/profile')->json('profile.employment.contract_history');

        $this->assertSame([
            ['previous_end_date' => today()->addMonths(6)->toDateString(), 'new_end_date' => today()->addMonths(12)->toDateString()],
            ['previous_end_date' => today()->addMonth()->toDateString(), 'new_end_date' => today()->addMonths(6)->toDateString()],
        ], $history, 'Hanya milik sendiri, terbaru di atas, tanpa catatan.');

        $permanent = $this->user('kasir', null, ['employee_type_id' => \App\Models\EmployeeType::create(['name' => 'Tetap', 'has_end_date' => false, 'is_active' => true])->id]);
        $this->assertSame([], $this->actingAs($permanent, 'api')->getJson('/api/staff/profile')->json('profile.employment.contract_history'));
    }
}
