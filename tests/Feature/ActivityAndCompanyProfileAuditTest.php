<?php

namespace Tests\Feature;

use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Filament\Resources\ActivityResource\Pages\ViewActivity;
use App\Models\FinanceSetting;
use App\Models\Store;
use App\Models\User;
use App\Support\ActivityPresenter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Audit menu Riwayat Aktivitas dan Profil Perusahaan: penyamaran data sensitif bagi non-akses-penuh, filter Aksi dan rentang cepat,
 * pencarian yang mengenali modul dan nomor objek, tautan riwayat objek, NPWP baku, pratinjau, dan keterangan perubahan terakhir.
 */
class ActivityAndCompanyProfileAuditTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    private function user(string $role): User
    {
        return tap(User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'menu_access' => null, 'is_active' => true]), fn (User $u) => $u->assignRole($role));
    }

    private function log(string $logName, string $description, string $event = 'updated', array $properties = [], ?string $at = null, ?int $subjectId = null): Activity
    {
        $activity = Activity::create([
            'log_name' => $logName, 'description' => $description, 'event' => $event,
            'subject_type' => Store::class, 'subject_id' => $subjectId ?? $this->store->id, 'properties' => $properties,
        ]);

        if ($at) {
            DB::table('activity_log')->where('id', $activity->id)->update(['created_at' => $at, 'updated_at' => $at]);
        }

        return $activity->fresh();
    }

    public function test_salary_and_bank_values_are_masked_for_non_full_access_only(): void
    {
        $values = ['base_salary' => 9000000, 'bank_account_number' => '1234567890', 'name' => 'Budi'];

        $this->actingAs($this->user('kasir'), 'web');
        $masked = ActivityPresenter::rows($values);
        $this->assertSame(ActivityPresenter::MASKED, $masked[ActivityPresenter::label('base_salary')]);
        $this->assertSame(ActivityPresenter::MASKED, $masked[ActivityPresenter::label('bank_account_number')]);
        $this->assertSame('Budi', $masked[ActivityPresenter::label('name')], 'Kolom biasa tidak disamarkan.');

        $this->actingAs($this->user('super_admin'), 'web');
        $visible = ActivityPresenter::rows($values);
        $this->assertNotSame(ActivityPresenter::MASKED, $visible[ActivityPresenter::label('base_salary')]);
        $this->assertStringContainsString('1234567890', $visible[ActivityPresenter::label('bank_account_number')]);
    }

    public function test_action_filter_and_quick_ranges_narrow_the_list(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $deleted = $this->log('booking', 'Booking dihapus', 'deleted');
        $created = $this->log('booking', 'Booking dibuat', 'created');
        $old = $this->log('booking', 'Booking lama', 'created', [], now()->subDays(20)->toDateTimeString());

        Livewire::test(ListActivities::class)
            ->filterTable('event', 'deleted')
            ->assertCanSeeTableRecords([$deleted])
            ->assertCanNotSeeTableRecords([$created, $old]);

        Livewire::test(ListActivities::class)
            ->filterTable('created_at', ['preset' => 'today'])
            ->assertCanSeeTableRecords([$deleted, $created])
            ->assertCanNotSeeTableRecords([$old]);

        Livewire::test(ListActivities::class)
            ->filterTable('created_at', ['preset' => '30'])
            ->assertCanSeeTableRecords([$deleted, $created, $old]);
    }

    public function test_search_finds_by_object_number_and_by_module_name(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $twelve = $this->log('booking', 'Catatan biasa', 'updated', [], null, 12);
        $other = $this->log('warranty', 'Garansi lain', 'updated', [], null, 99);

        Livewire::test(ListActivities::class)
            ->searchTable('#12')
            ->assertCanSeeTableRecords([$twelve])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListActivities::class)
            ->searchTable('Booking')
            ->assertCanSeeTableRecords([$twelve])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_the_detail_links_to_all_activity_on_the_same_object(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $activity = $this->log('store', 'Toko diubah');

        Livewire::test(ViewActivity::class, ['record' => $activity->getRouteKey()])
            ->assertSee('Lihat semua aktivitas objek ini');
    }

    public function test_npwp_is_normalised_for_storage_and_printing(): void
    {
        $this->assertSame('12.345.678.9-012.345', FinanceSetting::formatNpwp('123456789012345'));
        $this->assertSame('12.345.678.9-012.345', FinanceSetting::formatNpwp('12.345.678.9-012.345'));
        $this->assertSame('1234567890123456', FinanceSetting::formatNpwp('1234 5678 9012 3456'));
        $this->assertNull(FinanceSetting::formatNpwp('  '));
        $this->assertSame('abc', FinanceSetting::formatNpwp('abc'), 'Isian yang bukan nomor dikembalikan apa adanya (validasi di form).');

        FinanceSetting::set(FinanceSetting::COMPANY_NPWP, '123456789012345');
        $this->assertSame('12.345.678.9-012.345', FinanceSetting::companyProfile()['npwp']);
    }

    public function test_the_company_profile_page_saves_a_standard_npwp_and_shows_the_last_change(): void
    {
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');

        Livewire::test(FinanceSettingsPage::class)
            ->assertSee('Belum pernah diubah')
            ->fillForm(['company_name' => 'PT Contoh Jaya', 'company_npwp' => '123456789012345', 'company_klu' => '45209'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('12.345.678.9-012.345', FinanceSetting::get(FinanceSetting::COMPANY_NPWP));

        Livewire::test(FinanceSettingsPage::class)
            ->assertSee('Terakhir diubah')
            ->assertSee($admin->name)
            ->assertFormSet(['company_npwp' => '12.345.678.9-012.345']);
    }

    public function test_the_invoice_preview_uses_the_unsaved_form_values(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        Livewire::test(FinanceSettingsPage::class)
            ->fillForm(['company_name' => 'PT Pratinjau', 'company_npwp' => '123456789012345', 'company_klu' => '45209', 'company_address' => 'Jl. Contoh 1'])
            ->mountAction('preview')
            ->assertSee('PT Pratinjau')
            ->assertSee('12.345.678.9-012.345')
            ->assertSee('KLU 45209')
            ->assertSee('Jl. Contoh 1');
    }

    public function test_the_klu_is_printed_in_the_invoice_footer(): void
    {
        FinanceSetting::set(FinanceSetting::COMPANY_KLU, '45209');

        foreach (['pdf.invoice', 'pdf.booking_invoice'] as $view) {
            $source = file_get_contents(resource_path('views/' . str_replace('.', '/', $view) . '.blade.php'));
            $this->assertStringContainsString("KLU {{ \$company['klu'] }}", $source, $view);
        }
    }
}
