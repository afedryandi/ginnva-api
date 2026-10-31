<?php

namespace Tests\Feature;

use App\Filament\Resources\WarningLetterResource;
use App\Filament\Resources\WarningLetterResource\Pages\CreateWarningLetter;
use App\Filament\Resources\WarningLetterResource\Pages\EditWarningLetter;
use App\Filament\Resources\WarningLetterResource\Pages\ListWarningLetters;
use App\Models\Store;
use App\Models\User;
use App\Models\WarningLetter;
use App\Services\PushNotificationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Surat Peringatan: terbitkan (nomor otomatis, tingkat, validasi karyawan &
 * toko di server, karyawan diberi tahu), koreksi hanya full-access, hapus,
 * daftar & filter per toko, riwayat di aplikasi staf (hanya milik sendiri,
 * tetap terlihat setelah mutasi toko) dan tanda sudah dibaca.
 */
class WarningLetterTest extends TestCase
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

    /** Staf yang menu Surat Peringatan-nya dicentang dengan izin aksi tertentu (akun bawaan tidak punya akses menu ini). */
    private function holder(string $role = 'store_manager', array $permissions = ['create'], ?Store $store = null): User
    {
        return $this->user($role, $store, [
            'menu_access' => [WarningLetterResource::class],
            'menu_permissions' => [WarningLetterResource::class => $permissions],
        ]);
    }

    private function letter(User $employee, array $overrides = []): WarningLetter
    {
        return WarningLetter::create(array_merge([
            'user_id' => $employee->id, 'store_id' => $employee->store_id, 'level' => 'sp1', 'reason' => 'Terlambat berulang',
            'issued_date' => today()->subDays(2)->toDateString(),
        ], $overrides));
    }

    private function form(User $employee, array $overrides = []): array
    {
        return array_merge([
            'store_id' => $employee->store_id, 'user_id' => $employee->id, 'level' => 'sp1', 'reason' => 'Terlambat 5 kali dalam sebulan',
            'issued_date' => today()->toDateString(),
        ], $overrides);
    }

    // ------------------------------------------------------------- model

    public function test_letter_numbers_are_generated_unique_and_formatted(): void
    {
        $employee = $this->user('kasir');
        $a = $this->letter($employee);
        $b = $this->letter($employee, ['level' => 'sp2']);

        $this->assertMatchesRegularExpression('/^SP-\d{6}-[A-Z0-9]{4}$/', $a->warning_number);
        $this->assertNotSame($a->warning_number, $b->warning_number);
    }

    // ------------------------------------------------------------- terbitkan

    public function test_admin_issues_a_letter_and_the_employee_is_notified(): void
    {
        $admin = $this->user('super_admin');
        $employee = $this->user('kasir');
        $this->mock(PushNotificationService::class, function ($mock) use ($employee) {
            $mock->shouldReceive('sendToUsers')->once()->withArgs(fn (array $ids, string $title, string $body) => $ids === [$employee->id]
                && $title === 'Surat Peringatan Diterbitkan' && str_contains($body, 'SP 2'));
        });
        $this->actingAs($admin, 'web');

        Livewire::test(CreateWarningLetter::class)
            ->fillForm($this->form($employee, ['level' => 'sp2', 'valid_until' => today()->addMonths(6)->toDateString()]))
            ->call('create')->assertHasNoFormErrors();

        $letter = WarningLetter::where('user_id', $employee->id)->firstOrFail();
        $this->assertSame('sp2', $letter->level);
        $this->assertSame($admin->id, $letter->issued_by);
        $this->assertNotEmpty($letter->warning_number);
        $this->assertNull($letter->acknowledged_at);
        $this->assertTrue(Activity::where('log_name', 'warning_letter')->where('subject_id', $letter->id)->where('event', 'created')->exists());
    }

    public function test_a_store_manager_issues_letters_for_their_own_store(): void
    {
        $this->actingAs($this->holder(), 'web');
        $employee = $this->user('kasir');
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once());

        Livewire::test(CreateWarningLetter::class)
            ->fillForm($this->form($employee, ['store_id' => $this->otherStore->id]))
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame($this->store->id, WarningLetter::where('user_id', $employee->id)->value('store_id'));
    }

    public function test_required_fields_and_validity_dates_are_validated(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $employee = $this->user('kasir');
        $create = fn (array $over) => Livewire::test(CreateWarningLetter::class)->fillForm($this->form($employee, $over))->call('create');

        $create(['user_id' => null])->assertHasFormErrors(['user_id' => 'required']);
        $create(['level' => null])->assertHasFormErrors(['level' => 'required']);
        $create(['reason' => ''])->assertHasFormErrors(['reason' => 'required']);
        $create(['issued_date' => null])->assertHasFormErrors(['issued_date' => 'required']);
        $create(['valid_until' => today()->subDays(5)->toDateString()])->assertHasFormErrors(['valid_until']);

        $this->assertSame(0, WarningLetter::count());
    }

    public function test_server_rejects_partners_and_employees_of_another_store(): void
    {
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldNotReceive('sendToUsers'));
        $partner = $this->user('partner');
        $foreigner = $this->user('kasir', $this->otherStore);
        $manager = $this->holder();

        $this->actingAs($this->user('super_admin'), 'web');
        Livewire::test(CreateWarningLetter::class)->fillForm($this->form($partner))->call('create');
        Livewire::test(CreateWarningLetter::class)->fillForm($this->form($foreigner, ['store_id' => $this->store->id]))->call('create');

        $this->actingAs($manager, 'web');
        Livewire::test(CreateWarningLetter::class)->fillForm($this->form($foreigner, ['store_id' => $this->otherStore->id]))->call('create');

        $this->assertSame(0, WarningLetter::count(), 'Partner, karyawan toko lain, dan toko tidak cocok semuanya ditolak.');
    }

    // ------------------------------------------------------------- daftar

    public function test_list_is_store_scoped_with_filters(): void
    {
        $manager = $this->holder('store_manager', []);
        $anna = $this->user('kasir', null, ['name' => 'Anna']);
        $mine = $this->letter($anna);
        $sp2 = $this->letter($this->user('kasir'), ['level' => 'sp2', 'issued_date' => today()->subMonths(2)->toDateString()]);
        $theirs = $this->letter($this->user('kasir', $this->otherStore));

        $this->actingAs($manager, 'web');
        Livewire::test(ListWarningLetters::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$mine, $sp2])->assertCanNotSeeTableRecords([$theirs]);

        Livewire::test(ListWarningLetters::class)->filterTable('level', 'sp2')
            ->assertCanSeeTableRecords([$sp2])->assertCanNotSeeTableRecords([$mine]);

        Livewire::test(ListWarningLetters::class)->filterTable('user_id', $anna->id)
            ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$sp2]);

        Livewire::test(ListWarningLetters::class)
            ->filterTable('issued_date', ['from' => today()->subMonth()->toDateString(), 'until' => today()->toDateString()])
            ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$sp2]);

        $this->actingAs($this->user('super_admin'), 'web');
        Livewire::test(ListWarningLetters::class)->assertCanSeeTableRecords([$mine, $sp2, $theirs]);
    }

    public function test_the_scan_link_is_shown_only_when_a_document_exists(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $employee = $this->user('kasir');
        $withScan = $this->letter($employee, ['document' => 'warning-letters/sp.pdf']);
        $without = $this->letter($employee, ['level' => 'sp2']);

        Livewire::test(ListWarningLetters::class)
            ->assertTableActionVisible('viewDocument', $withScan)
            ->assertTableActionHidden('viewDocument', $without);
    }

    // ------------------------------------------------------------- koreksi & hapus

    public function test_only_full_access_or_an_explicit_grant_can_correct_an_issued_letter(): void
    {
        $letter = $this->letter($this->user('kasir'));

        foreach (['kasir', 'store_manager'] as $role) {
            $this->actingAs($this->holder($role, ['create']), 'web');
            $this->assertTrue(WarningLetterResource::canCreate(), 'Menerbitkan SP baru butuh menu dicentang dan izin Buat.');
            $this->assertFalse((bool) WarningLetterResource::canEdit($letter), 'Koreksi SP yang sudah terbit tidak.');
            Livewire::test(EditWarningLetter::class, ['record' => $letter->getKey()])->assertForbidden();
        }

        $granted = $this->holder('kasir', ['update']);
        $this->actingAs($granted, 'web');
        $this->assertTrue((bool) WarningLetterResource::canEdit($letter));

        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue((bool) WarningLetterResource::canEdit($letter));
    }

    public function test_full_access_corrects_a_letter_and_it_is_audited(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $letter = $this->letter($this->user('kasir'));

        Livewire::test(EditWarningLetter::class, ['record' => $letter->getKey()])
            ->fillForm(['level' => 'sp2', 'reason' => 'Koreksi: pelanggaran berulang'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('sp2', $letter->fresh()->level);
        $this->assertTrue(Activity::where('log_name', 'warning_letter')->where('subject_id', $letter->id)->where('event', 'updated')->exists());
    }

    public function test_an_edit_cannot_reassign_the_letter_to_an_employee_outside_the_store(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $employee = $this->user('kasir');
        $foreigner = $this->user('kasir', $this->otherStore);
        $letter = $this->letter($employee);

        Livewire::test(EditWarningLetter::class, ['record' => $letter->getKey()])
            ->fillForm(['user_id' => $foreigner->id])
            ->call('save');

        $this->assertSame($employee->id, $letter->fresh()->user_id);
    }

    public function test_only_full_access_can_delete_by_default(): void
    {
        $letter = $this->letter($this->user('kasir'));

        $this->actingAs($this->holder('store_manager', ['create', 'update']), 'web');
        $this->assertFalse((bool) WarningLetterResource::canDelete($letter));

        $this->actingAs($this->holder('store_manager', ['delete']), 'web');
        $this->assertTrue((bool) WarningLetterResource::canDelete($letter), 'Izin hapus eksplisit boleh didelegasikan.');

        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue((bool) WarningLetterResource::canDelete($letter));
        Livewire::test(ListWarningLetters::class)->callTableAction('delete', $letter);

        $this->assertNull(WarningLetter::find($letter->id));
        $this->assertTrue(Activity::where('log_name', 'warning_letter')->where('event', 'deleted')->exists());
    }

    public function test_menu_access_gates_the_resource(): void
    {
        $this->actingAs($this->user('kasir', null, ['menu_access' => ['SomeOtherResource']]), 'web');

        $this->assertFalse(WarningLetterResource::canViewAny());
        $this->assertFalse(WarningLetterResource::canCreate());
    }

    public function test_default_accounts_can_neither_read_nor_issue_letters_for_colleagues(): void
    {
        $colleague = $this->user('kasir');
        $letter = $this->letter($colleague);
        $default = $this->user('store_manager');
        $this->assertNull($default->menu_access);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldNotReceive('sendToUsers'));

        $this->actingAs($default, 'web');
        $this->assertFalse(WarningLetterResource::canViewAny(), 'Akun bawaan tidak membaca SP rekan satu toko.');
        $this->assertFalse(WarningLetterResource::canCreate());
        $this->assertFalse((bool) WarningLetterResource::canEdit($letter));
        $this->assertFalse((bool) WarningLetterResource::canDelete($letter));
        Livewire::test(CreateWarningLetter::class)->assertForbidden();
        Livewire::test(ListWarningLetters::class)->assertForbidden();
        $this->assertSame(1, WarningLetter::count());

        $viewer = $this->holder('kasir', []);
        $this->actingAs($viewer, 'web');
        $this->assertTrue(WarningLetterResource::canViewAny());
        $this->assertFalse(WarningLetterResource::canCreate(), 'Melihat saja tanpa izin terbitkan.');
        Livewire::test(CreateWarningLetter::class)->assertForbidden();
    }

    public function test_the_employee_filter_only_offers_employees_with_letters_in_the_viewers_store(): void
    {
        $mine = $this->user('kasir', null, ['name' => 'Karyawan Toko A']);
        $theirs = $this->user('kasir', $this->otherStore, ['name' => 'Karyawan Toko B']);
        $noLetter = $this->user('kasir', null, ['name' => 'Tanpa Surat']);
        $this->letter($mine);
        $this->letter($theirs);

        $options = fn () => Livewire::test(ListWarningLetters::class)->instance()->getTable()->getFilter('user_id')->getOptions();

        $this->actingAs($this->holder('store_manager', []), 'web');
        $this->assertSame(['Karyawan Toko A'], array_values($options()), 'Nama karyawan toko lain tidak bocor ke filter.');

        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertEqualsCanonicalizing(['Karyawan Toko A', 'Karyawan Toko B'], array_values($options()));
        $this->assertNotNull($noLetter);
    }

    // ------------------------------------------------------------- aplikasi staf

    public function test_the_staff_endpoints_require_login(): void
    {
        $letter = $this->letter($this->user('kasir'));

        $this->getJson('/api/staff/warning-letters')->assertStatus(401);
        $this->postJson("/api/staff/warning-letters/{$letter->id}/acknowledge")->assertStatus(401);
        $this->assertNull($letter->fresh()->acknowledged_at);
    }

    public function test_the_staff_app_lists_only_my_own_letters_newest_first(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('warning-letters/sp2.pdf', 'scan');
        $me = $this->user('kasir');
        $colleague = $this->user('kasir');
        $older = $this->letter($me, ['issued_date' => today()->subMonths(3)->toDateString()]);
        $newer = $this->letter($me, ['level' => 'sp2', 'issued_date' => today()->subDays(3)->toDateString(), 'document' => 'warning-letters/sp2.pdf']);
        $this->letter($colleague);

        $data = $this->actingAs($me, 'api')->getJson('/api/staff/warning-letters')->assertSuccessful()->json('warning_letters');

        $this->assertSame([$newer->id, $older->id], collect($data)->pluck('id')->all());
        $this->assertSame(['id', 'warning_number', 'level', 'status', 'reason', 'issued_date', 'valid_until', 'acknowledged_at', 'cancelled_at', 'cancel_reason', 'document_url', 'issuer_name'], array_keys($data[0]));
        $this->assertStringContainsString('warning-letters/sp2.pdf', $data[0]['document_url']);
        $this->assertMatchesRegularExpression('/expir/', $data[0]['document_url'], 'Tautan sementara, bukan alamat tetap yang terbuka untuk umum.');
        $this->assertNull($data[1]['document_url']);
    }

    public function test_letters_issued_in_a_previous_store_stay_visible_after_a_transfer(): void
    {
        $employee = $this->user('kasir');
        $oldLetter = $this->letter($employee);
        $employee->update(['store_id' => $this->otherStore->id]);
        $newLetter = $this->letter($employee->fresh(), ['level' => 'sp2']);

        $ids = collect($this->actingAs($employee->fresh(), 'api')->getJson('/api/staff/warning-letters')->assertSuccessful()->json('warning_letters'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$oldLetter->id, $newLetter->id], $ids, 'SP dari toko lama adalah dokumen milik karyawan, tidak boleh hilang setelah mutasi.');
    }

    public function test_acknowledging_stamps_the_time_once_and_stays_idempotent(): void
    {
        $me = $this->user('kasir');
        $letter = $this->letter($me);

        $first = $this->actingAs($me, 'api')->postJson("/api/staff/warning-letters/{$letter->id}/acknowledge")->assertSuccessful();
        $stamp = $first->json('warning_letter.acknowledged_at');
        $this->assertNotNull($stamp);

        $this->travel(2)->hours();
        $second = $this->actingAs($me, 'api')->postJson("/api/staff/warning-letters/{$letter->id}/acknowledge")->assertSuccessful();

        $this->assertSame($stamp, $second->json('warning_letter.acknowledged_at'), 'Tap ulang tidak menimpa waktu baca pertama.');
        $this->assertNotNull($letter->fresh()->acknowledged_at);
    }

    public function test_nobody_can_acknowledge_someone_elses_letter(): void
    {
        $owner = $this->user('kasir');
        $other = $this->user('kasir');
        $letter = $this->letter($owner);

        $this->actingAs($other, 'api')->postJson("/api/staff/warning-letters/{$letter->id}/acknowledge")->assertStatus(404);

        $this->assertNull($letter->fresh()->acknowledged_at);
    }

    // ------------------------------------------------------------- audit SP B: kunci setelah dibaca dan pembatalan

    public function test_a_letter_read_by_the_employee_is_locked_and_cannot_be_deleted_but_can_still_get_a_scan(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $employee = $this->user('kasir');
        $letter = $this->letter($employee);
        $letter->update(['acknowledged_at' => now()]);
        $letter = $letter->fresh();

        $this->assertTrue($letter->isLocked());
        $this->assertThrows(fn () => $letter->update(['level' => 'sp3']), \RuntimeException::class);
        $this->assertThrows(fn () => $letter->update(['reason' => 'Diubah diam-diam']), \RuntimeException::class);
        $this->assertThrows(fn () => $letter->update(['issued_date' => today()->subYear()->toDateString()]), \RuntimeException::class);
        $this->assertThrows(fn () => $letter->delete(), \RuntimeException::class);
        $this->assertSame('sp1', $letter->fresh()->level);

        $letter->fresh()->update(['document' => 'warning-letters/scan.pdf']);   // model segar: percobaan ubah di atas meninggalkan atribut kotor di instance lama
        $this->assertSame('warning-letters/scan.pdf', $letter->fresh()->document, 'Scan boleh dilengkapi setelah dibaca.');

        Livewire::test(ListWarningLetters::class)->assertTableActionHidden('delete', $letter);
        Livewire::test(EditWarningLetter::class, ['record' => $letter->getKey()])
            ->assertFormFieldIsDisabled('level')->assertFormFieldIsDisabled('reason')->assertFormFieldIsDisabled('issued_date')
            ->call('save');
        $this->assertSame('Terlambat berulang', $letter->fresh()->reason);
    }

    public function test_cancelling_keeps_the_letter_drops_it_from_active_counts_notifies_and_needs_the_void_permission(): void
    {
        $employee = $this->user('kasir');
        $letter = $this->letter($employee, ['level' => 'sp2']);
        $this->assertSame('sp3', WarningLetter::suggestedLevelFor($employee->id));
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once()->withArgs(fn (array $ids, string $title, string $body) => $ids === [$employee->id]
            && $title === 'Surat Peringatan Dibatalkan' && str_contains($body, 'Salah orang')));

        $this->actingAs($this->holder('store_manager', ['create', 'update']), 'web');
        Livewire::test(ListWarningLetters::class)->assertTableActionHidden('cancelLetter', $letter);

        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');
        Livewire::test(ListWarningLetters::class)
            ->callTableAction('cancelLetter', $letter, ['cancel_reason' => ''])->assertHasTableActionErrors(['cancel_reason' => 'required']);
        Livewire::test(ListWarningLetters::class)
            ->callTableAction('cancelLetter', $letter, ['cancel_reason' => 'Salah orang'])->assertHasNoTableActionErrors();

        $letter = $letter->fresh();
        $this->assertTrue($letter->isCancelled());
        $this->assertSame($admin->id, $letter->cancelled_by);
        $this->assertSame('Salah orang', $letter->cancel_reason);
        $this->assertSame('Dibatalkan', $letter->statusLabel());
        $this->assertSame('sp1', WarningLetter::suggestedLevelFor($employee->id), 'SP yang dibatalkan tidak jadi dasar tingkat berikutnya.');
        $this->assertCount(0, WarningLetter::activeFor($employee->id));
        $this->assertTrue(Activity::where('log_name', 'warning_letter')->where('subject_id', $letter->id)->where('description', 'like', '%dibatalkan%')->exists());
        $this->assertSame(1, $employee->notifications()->get()->filter(fn ($n) => ($n->data['title'] ?? null) === 'Surat Peringatan Dibatalkan')->count());

        $this->assertThrows(fn () => $letter->cancel($admin, 'Lagi'), \InvalidArgumentException::class);
        $this->assertThrows(fn () => $this->letter($employee)->cancel($admin, '   '), \InvalidArgumentException::class);

        $api = $this->actingAs($employee, 'api')->getJson('/api/staff/warning-letters')->json('warning_letters.0');
        $this->assertNotNull($api['cancelled_at']);
        $this->assertSame('Salah orang', $api['cancel_reason']);
    }

    public function test_a_letter_already_read_can_still_be_cancelled_with_the_void_permission(): void
    {
        $employee = $this->user('kasir');
        $letter = $this->letter($employee);
        $letter->update(['acknowledged_at' => now()]);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once());

        $this->actingAs($this->holder('store_manager', ['void']), 'web');
        Livewire::test(ListWarningLetters::class)->callTableAction('cancelLetter', $letter, ['cancel_reason' => 'Keputusan manajemen']);

        $this->assertTrue($letter->fresh()->isCancelled());
        $this->assertNotNull($letter->fresh()->acknowledged_at);
    }

    public function test_future_issue_dates_are_rejected_and_the_status_column_labels_each_state(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $employee = $this->user('kasir');

        Livewire::test(CreateWarningLetter::class)
            ->fillForm($this->form($employee, ['issued_date' => today()->addDay()->toDateString()]))
            ->call('create')->assertHasFormErrors(['issued_date']);
        $this->assertSame(0, WarningLetter::count());

        $valid = $this->letter($employee);
        $expired = $this->letter($employee, ['issued_date' => today()->subMonths(8)->toDateString(), 'valid_until' => today()->subMonths(2)->toDateString()]);
        $cancelled = $this->letter($employee);
        $cancelled->cancel(null, 'Salah ketik');

        Livewire::test(ListWarningLetters::class)
            ->assertTableColumnStateSet('status', 'Berlaku', $valid)
            ->assertTableColumnStateSet('status', 'Kedaluwarsa', $expired)
            ->assertTableColumnStateSet('status', 'Dibatalkan', $cancelled->fresh());
    }

    public function test_the_hr_dashboard_does_not_count_cancelled_letters_as_active(): void
    {
        $employee = $this->user('kasir');
        $this->letter($employee);
        $this->letter($employee, ['level' => 'sp2'])->cancel(null, 'Salah');

        $this->assertSame(1, (new \App\Services\HrDashboardService())->summary()['active_warnings']);
    }

    // ------------------------------------------------------------- audit SP C: definisi "berlaku", filter, dasbor

    public function test_one_definition_of_active_is_used_by_the_suggestion_and_the_dashboard_including_letters_without_a_validity_date(): void
    {
        $employee = $this->user('kasir');
        $legacy = $this->letter($employee, ['level' => 'sp2', 'issued_date' => today()->subYear()->toDateString()]);
        WarningLetter::query()->whereKey($legacy->id)->update(['valid_until' => null]);   // SP lama tanpa batas, dibuat sebelum masa berlaku otomatis
        $this->letter($employee, ['issued_date' => today()->subYear()->toDateString(), 'valid_until' => today()->subMonths(6)->toDateString()]);   // sudah lewat
        $this->letter($employee)->cancel(null, 'Salah');                                                                                          // dibatalkan

        $this->assertCount(1, WarningLetter::activeFor($employee->id));
        $this->assertSame('sp3', WarningLetter::suggestedLevelFor($employee->id));
        $this->assertSame(1, (new \App\Services\HrDashboardService())->summary()['active_warnings'], 'Dasbor memakai definisi yang sama dengan saran tingkat.');
    }

    public function test_the_list_filters_by_status_and_unread_and_shows_how_many_letters_are_active_per_employee(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $employee = $this->user('kasir');
        $valid = $this->letter($employee);
        $expired = $this->letter($employee, ['issued_date' => today()->subMonths(8)->toDateString(), 'valid_until' => today()->subMonths(2)->toDateString()]);
        $cancelled = $this->letter($employee);
        $cancelled->cancel(null, 'Salah');
        $cancelled = $cancelled->fresh();

        Livewire::test(ListWarningLetters::class)
            ->filterTable('status', 'berlaku')->assertCanSeeTableRecords([$valid])->assertCanNotSeeTableRecords([$expired, $cancelled])
            ->removeTableFilters()
            ->filterTable('status', 'kedaluwarsa')->assertCanSeeTableRecords([$expired])->assertCanNotSeeTableRecords([$valid, $cancelled])
            ->removeTableFilters()
            ->filterTable('status', 'dibatalkan')->assertCanSeeTableRecords([$cancelled])->assertCanNotSeeTableRecords([$valid, $expired]);

        $record = WarningLetterResource::getEloquentQuery()->find($valid->id);
        $this->assertSame(1, (int) $record->active_count, 'Hanya satu yang berlaku dari tiga surat.');

        // Belum dibaca lebih dari 3 hari: hanya yang belum dibaca, belum dibatalkan, dan sudah cukup lama dicatat.
        $oldUnread = $valid;
        WarningLetter::query()->whereKey($oldUnread->id)->update(['created_at' => now()->subDays(4)]);
        $oldRead = $this->letter($employee);
        WarningLetter::query()->whereKey($oldRead->id)->update(['created_at' => now()->subDays(4), 'acknowledged_at' => now()]);
        $fresh = $this->letter($employee);

        Livewire::test(ListWarningLetters::class)
            ->filterTable('unread')
            ->assertCanSeeTableRecords([$oldUnread])->assertCanNotSeeTableRecords([$oldRead, $fresh, $cancelled]);
    }

    public function test_the_hr_dashboard_counts_letters_unread_for_too_long_and_links_to_the_filtered_list(): void
    {
        $employee = $this->user('kasir');
        $stale = $this->letter($employee);
        WarningLetter::query()->whereKey($stale->id)->update(['created_at' => now()->subDays(5)]);
        $read = $this->letter($employee);
        WarningLetter::query()->whereKey($read->id)->update(['created_at' => now()->subDays(5), 'acknowledged_at' => now()]);
        $cancelled = $this->letter($employee);
        WarningLetter::query()->whereKey($cancelled->id)->update(['created_at' => now()->subDays(5)]);
        $cancelled->fresh()->cancel(null, 'Salah');
        $this->letter($employee);   // baru dicatat

        $this->assertSame(1, (new \App\Services\HrDashboardService())->summary()['unread_warnings']);

        $this->actingAs($this->user('super_admin'), 'web');
        Livewire::test(\App\Filament\Pages\HrDashboard::class)
            ->assertSee('Surat Peringatan belum dibaca karyawan lebih dari 3 hari')
            ->assertSeeHtml('tableFilters%5Bunread%5D%5BisActive%5D=true');
    }

    // ------------------------------------------------------------- audit SP D: cetak surat resmi

    public function test_the_official_letter_pdf_downloads_from_the_list_and_the_edit_page(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $letter = $this->letter($this->user('kasir'));

        Livewire::test(ListWarningLetters::class)
            ->callTableAction('printLetter', $letter)
            ->assertFileDownloaded('Surat-Peringatan-' . $letter->warning_number . '.pdf');

        Livewire::test(EditWarningLetter::class, ['record' => $letter->getKey()])
            ->callAction('printLetter')
            ->assertFileDownloaded('Surat-Peringatan-' . $letter->warning_number . '.pdf');
    }

    public function test_the_letter_shows_the_employee_reason_level_issuer_and_earlier_letters_but_not_cancelled_ones(): void
    {
        $issuer = $this->user('super_admin', null, ['name' => 'Direksi Penerbit']);
        $employee = $this->user('kasir', null, ['name' => 'Budi Karyawan', 'employee_number' => 'EMP-777']);
        $earlier = $this->letter($employee, ['issued_date' => today()->subMonths(2)->toDateString()]);
        $wrong = $this->letter($employee, ['issued_date' => today()->subMonth()->toDateString()]);
        $wrong->cancel(null, 'Salah');
        $letter = $this->letter($employee, ['level' => 'sp2', 'reason' => 'Mangkir tiga hari tanpa kabar', 'issued_by' => $issuer->id, 'issued_date' => today()->toDateString()]);

        $html = view('pdf.warning-letter', WarningLetterResource::letterViewData($letter->fresh()))->render();

        foreach (['Surat Peringatan 2', 'Surat Peringatan Kedua', $letter->warning_number, 'Budi Karyawan', 'EMP-777', 'Mangkir tiga hari tanpa kabar', 'Direksi Penerbit', 'Toko A', $earlier->warning_number] as $expected) {
            $this->assertStringContainsString($expected, $html, $expected);
        }
        $this->assertStringNotContainsString($wrong->warning_number, $html, 'SP yang dibatalkan tidak dicantumkan sebagai riwayat.');
        $this->assertStringNotContainsString('DIBATALKAN', $html);
    }

    public function test_a_cancelled_letter_prints_with_the_cancellation_banner_and_a_read_letter_shows_when_it_was_read(): void
    {
        $employee = $this->user('kasir');
        $letter = $this->letter($employee);
        $letter->update(['acknowledged_at' => now()]);
        $letter->fresh()->cancel(null, 'Salah orang');

        $html = view('pdf.warning-letter', WarningLetterResource::letterViewData($letter->fresh()))->render();

        $this->assertStringContainsString('SURAT INI DIBATALKAN', $html);
        $this->assertStringContainsString('Salah orang', $html);
        $this->assertStringContainsString('Dibaca di aplikasi', $html);
    }

    // ------------------------------------------------------------- audit SP E: aplikasi staf dan pengingat

    public function test_the_staff_api_reports_status_and_the_unread_count_everywhere_the_app_shows_a_marker(): void
    {
        $me = $this->user('kasir');
        $this->letter($me);                                                                     // belum dibaca, berlaku
        $this->letter($me, ['issued_date' => today()->subMonths(8)->toDateString(), 'valid_until' => today()->subMonths(2)->toDateString()]);   // belum dibaca, kedaluwarsa
        $this->letter($me)->update(['acknowledged_at' => now()]);                               // sudah dibaca
        $this->letter($me)->cancel(null, 'Salah');                                              // dibatalkan, tidak dihitung
        $this->letter($this->user('kasir'));                                                    // milik rekan

        $index = $this->actingAs($me, 'api')->getJson('/api/staff/warning-letters')->json();

        $this->assertSame(2, $index['unread_count']);
        $this->assertEqualsCanonicalizing(['Berlaku', 'Berlaku', 'Kedaluwarsa', 'Dibatalkan'], array_column($index['warning_letters'], 'status'));
        $this->assertSame(2, $this->actingAs($me, 'api')->getJson('/api/staff/attendance/today')->json('unread_warning_letters'));
        $this->assertSame(2, $this->actingAs($me, 'api')->getJson('/api/staff/profile')->json('profile.unread_warning_letters'));

        $letterId = collect($index['warning_letters'])->firstWhere('status', 'Berlaku')['id'];
        $this->postJson("/api/staff/warning-letters/{$letterId}/acknowledge")->assertSuccessful();
        $this->assertSame(1, $this->getJson('/api/staff/warning-letters')->json('unread_count'));
    }

    public function test_issuing_and_cancelling_send_a_push_that_opens_the_warning_letter_screen(): void
    {
        $employee = $this->user('kasir');
        $pushes = [];
        $this->mock(PushNotificationService::class, function ($mock) use (&$pushes) {
            $mock->shouldReceive('sendToUsers')->andReturnUsing(function (array $ids, string $title, string $body, array $data = []) use (&$pushes) {
                $pushes[] = [$title, $data];
            });
        });
        $this->actingAs($this->user('super_admin'), 'web');

        Livewire::test(CreateWarningLetter::class)->fillForm($this->form($employee))->call('create')->assertHasNoFormErrors();
        $letter = WarningLetter::where('user_id', $employee->id)->firstOrFail();
        Livewire::test(ListWarningLetters::class)->callTableAction('cancelLetter', $letter, ['cancel_reason' => 'Salah']);

        $this->assertSame(['Surat Peringatan Diterbitkan', 'Surat Peringatan Dibatalkan'], array_column($pushes, 0));
        foreach ($pushes as [$title, $data]) {
            $this->assertSame('/staff/attendance/warning-letters', $data['route'], $title);
        }
    }

    public function test_unread_letters_are_reminded_on_days_3_7_and_14_once_per_employee_and_never_when_read_cancelled_or_inactive(): void
    {
        $age = function (WarningLetter $letter, int $days) {
            WarningLetter::query()->whereKey($letter->id)->update(['created_at' => now()->subDays($days)]);

            return $letter;
        };

        $day3 = $this->user('kasir');
        $age($this->letter($day3), 3);
        $twoLetters = $this->user('kasir');
        $age($this->letter($twoLetters), 7);
        $age($this->letter($twoLetters, ['level' => 'sp2']), 14);
        $day14 = $this->user('kasir');
        $age($this->letter($day14), 14);
        $notYet = $this->user('kasir');
        $age($this->letter($notYet), 2);
        $between = $this->user('kasir');
        $age($this->letter($between), 5);
        $tooOld = $this->user('kasir');
        $age($this->letter($tooOld), 15);
        $read = $this->user('kasir');
        $age($this->letter($read), 3)->update(['acknowledged_at' => now()]);
        $cancelled = $this->user('kasir');
        $age($this->letter($cancelled), 3);
        WarningLetter::where('user_id', $cancelled->id)->first()->cancel(null, 'Salah');
        $inactive = $this->user('kasir', null, ['is_active' => false]);
        $age($this->letter($inactive), 3);

        $pushes = [];
        $this->mock(PushNotificationService::class, function ($mock) use (&$pushes) {
            $mock->shouldReceive('sendToUsers')->andReturnUsing(function (array $ids, string $title, string $body, array $data = []) use (&$pushes) {
                $pushes[] = [$ids[0], $title, $body, $data];
            });
        });

        $this->artisan('warnings:remind-unread')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$day3->id, $twoLetters->id, $day14->id], array_column($pushes, 0));
        $two = collect($pushes)->firstWhere(0, $twoLetters->id);
        $this->assertSame('Surat Peringatan Belum Dibaca', $two[1]);
        $this->assertStringContainsString('2 Surat Peringatan', $two[2], 'Dua surat satu pesan.');
        $this->assertSame('/staff/attendance/warning-letters', $two[3]['route']);
        $this->assertSame(1, $twoLetters->notifications()->count());

        $this->artisan('warnings:remind-unread')->assertSuccessful();
        $this->assertSame(1, $twoLetters->notifications()->count(), 'Selama belum dibaca, baris kotak masuk diperbarui, bukan ditumpuk.');
    }

    public function test_the_unread_reminder_is_scheduled_every_morning(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'warnings:remind-unread'));

        $this->assertNotNull($event, 'Terdaftar di jadwal.');
        $this->assertSame('30 8 * * *', $event->expression);
    }

    // ------------------------------------------------------------- audit SP F: SP 2 dan SP 3 hanya akses penuh

    public function test_only_full_access_can_issue_sp2_and_sp3_while_a_grant_holder_can_issue_sp1(): void
    {
        $employee = $this->user('kasir');
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->times(3));

        $this->actingAs($this->holder(), 'web');
        foreach (['sp2', 'sp3'] as $level) {
            Livewire::test(CreateWarningLetter::class)->fillForm($this->form($employee, ['level' => $level]))->call('create');
        }
        $this->assertSame(0, WarningLetter::count(), 'Pemegang izin Buat tidak bisa menerbitkan SP 2 maupun SP 3, walau permintaannya dipalsukan.');

        Livewire::test(CreateWarningLetter::class)->fillForm($this->form($employee, ['level' => 'sp1']))->call('create')->assertHasNoFormErrors();
        $this->assertSame(1, WarningLetter::count());

        $this->actingAs($this->user('super_admin'), 'web');
        foreach (['sp2', 'sp3'] as $level) {
            Livewire::test(CreateWarningLetter::class)->fillForm($this->form($employee, ['level' => $level]))->call('create')->assertHasNoFormErrors();
        }
        $this->assertSame(['sp1', 'sp2', 'sp3'], WarningLetter::orderBy('id')->pluck('level')->all());
    }

    public function test_the_level_choices_and_suggestion_respect_who_is_issuing(): void
    {
        $employee = $this->user('kasir');
        $this->letter($employee);   // SP 1 masih berlaku, jadi disarankan SP 2

        $this->actingAs($this->holder(), 'web');
        Livewire::test(CreateWarningLetter::class)
            ->assertFormFieldExists('level', fn (\Filament\Forms\Components\Select $field) => array_keys($field->getOptions()) === ['sp1'])
            ->fillForm(['store_id' => $this->store->id, 'user_id' => $employee->id])
            ->set('data.user_id', $employee->id)
            ->assertSet('data.level', null);

        $this->actingAs($this->user('super_admin'), 'web');
        Livewire::test(CreateWarningLetter::class)
            ->assertFormFieldExists('level', fn (\Filament\Forms\Components\Select $field) => array_keys($field->getOptions()) === ['sp1', 'sp2', 'sp3']);
    }

    public function test_an_update_grant_cannot_raise_a_letter_to_sp2_or_sp3_but_can_correct_other_fields_of_one_already_sp2(): void
    {
        $employee = $this->user('kasir');
        $sp1 = $this->letter($employee);
        $sp2 = $this->letter($employee, ['level' => 'sp2', 'reason' => 'Awal']);
        $this->actingAs($this->holder('store_manager', ['update']), 'web');

        Livewire::test(EditWarningLetter::class, ['record' => $sp1->getKey()])->fillForm(['level' => 'sp3'])->call('save');
        $this->assertSame('sp1', $sp1->fresh()->level, 'Menaikkan tingkat sama dengan menerbitkan SP 2/3 dan ditolak.');

        Livewire::test(EditWarningLetter::class, ['record' => $sp2->getKey()])->fillForm(['reason' => 'Koreksi redaksi'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Koreksi redaksi', $sp2->fresh()->reason);
        $this->assertSame('sp2', $sp2->fresh()->level);
    }
}
