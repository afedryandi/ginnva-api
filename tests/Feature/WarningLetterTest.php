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
        $this->actingAs($this->user('store_manager'), 'web');
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
        $manager = $this->user('store_manager');

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
        $manager = $this->user('store_manager');
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
            $this->actingAs($this->user($role), 'web');
            $this->assertTrue(WarningLetterResource::canCreate(), 'Menerbitkan SP baru terbuka untuk staf dengan akses menu.');
            $this->assertFalse((bool) WarningLetterResource::canEdit($letter), 'Koreksi SP yang sudah terbit tidak.');
            Livewire::test(EditWarningLetter::class, ['record' => $letter->getKey()])->assertForbidden();
        }

        $granted = $this->user('kasir', null, ['menu_permissions' => [WarningLetterResource::class => ['update']]]);
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

        $this->actingAs($this->user('store_manager'), 'web');
        $this->assertFalse((bool) WarningLetterResource::canDelete($letter));

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
        $me = $this->user('kasir');
        $colleague = $this->user('kasir');
        $older = $this->letter($me, ['issued_date' => today()->subMonths(3)->toDateString()]);
        $newer = $this->letter($me, ['level' => 'sp2', 'issued_date' => today()->subDays(3)->toDateString(), 'document' => 'warning-letters/sp2.pdf']);
        $this->letter($colleague);

        $data = $this->actingAs($me, 'api')->getJson('/api/staff/warning-letters')->assertSuccessful()->json('warning_letters');

        $this->assertSame([$newer->id, $older->id], collect($data)->pluck('id')->all());
        $this->assertSame(['id', 'warning_number', 'level', 'reason', 'issued_date', 'valid_until', 'acknowledged_at', 'document_url', 'issuer_name'], array_keys($data[0]));
        $this->assertStringEndsWith('/storage/warning-letters/sp2.pdf', $data[0]['document_url']);
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
}
