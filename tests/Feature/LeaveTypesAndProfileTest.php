<?php

namespace Tests\Feature;

use App\Filament\Resources\LeaveTypeResource;
use App\Filament\Resources\LeaveTypeResource\Pages\ListLeaveTypes;
use App\Models\EmployeeDocument;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Karyawan Fase 2 (2026-10-30): jenis izin/cuti yang bisa diatur staf (batas hari, syarat lampiran, memotong jatah tahunan atau tidak)
 * dan layar Profil Saya di aplikasi. Waktu dibekukan: Rabu 7 Okt 2026.
 */
class LeaveTypesAndProfileTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Storage::fake('local');
        Role::findOrCreate('kasir', 'web');
        Role::findOrCreate('super_admin', 'web');
        Carbon::setTestNow('2026-10-07 09:10:00');

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->staff = User::create([
            'name' => 'Rina', 'email' => 'rina@test.local', 'password' => 'x', 'store_id' => $this->store->id,
            'join_date' => '2024-01-01', 'employee_number' => 'EMP-001',
        ]);
        $this->staff->assignRole('kasir');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function typeId(string $code): int
    {
        LeaveType::ensureDefaults();

        return LeaveType::where('code', $code)->firstOrFail()->id;
    }

    private function request(array $data)
    {
        return $this->actingAs($this->staff, 'api')->postJson('/api/staff/leave-requests', array_merge([
            'start_date' => '2026-10-12', 'end_date' => '2026-10-13', 'reason' => 'Keperluan',
        ], $data));
    }

    // ------------------------------------------------------------- jenis

    public function test_the_three_base_types_exist_after_migration_and_defaults_are_idempotent(): void
    {
        $this->assertEqualsCanonicalizing(['izin', 'sakit', 'cuti_tahunan'], LeaveType::pluck('code')->all());

        LeaveType::ensureDefaults();
        $count = LeaveType::count();
        LeaveType::where('code', 'cuti_menikah')->update(['max_days' => 5]);
        LeaveType::ensureDefaults();

        $this->assertSame($count, LeaveType::count());
        $this->assertSame(5, LeaveType::where('code', 'cuti_menikah')->value('max_days'), 'Perubahan staf tidak ditimpa.');
        $this->assertTrue(LeaveType::where('code', 'cuti_tahunan')->value('deducts_annual_quota'));
        $this->assertFalse(LeaveType::where('code', 'cuti_menikah')->value('deducts_annual_quota'));
    }

    public function test_api_lists_active_types_with_their_rules(): void
    {
        LeaveType::ensureDefaults();
        LeaveType::where('code', 'cuti_ibadah')->update(['is_active' => false]);

        $response = $this->actingAs($this->staff, 'api')->getJson('/api/staff/leave-types')->assertSuccessful();
        $codes = collect($response->json('leave_types'))->pluck('name');

        $this->assertTrue($codes->contains('Cuti Menikah'));
        $this->assertFalse($codes->contains('Cuti Ibadah Keagamaan'));
        $sakit = collect($response->json('leave_types'))->firstWhere('name', 'Sakit');
        $this->assertSame(2, $sakit['document_required_from_days']);
        $this->assertStringContainsString('melampirkan dokumen', $sakit['rule_summary']);
    }

    public function test_special_leave_does_not_use_up_the_annual_quota(): void
    {
        $this->request(['leave_type_id' => $this->typeId('cuti_menikah'), 'start_date' => '2026-10-12', 'end_date' => '2026-10-14'])->assertStatus(201);
        LeaveRequest::query()->update(['status' => 'approved']);

        $this->assertSame(0, LeaveRequest::usedCutiDaysFor($this->staff, 2026));
        $this->assertSame(12, LeaveRequest::remainingCutiFor($this->staff, 2026));

        $this->request(['leave_type_id' => $this->typeId('cuti_tahunan'), 'start_date' => '2026-10-20', 'end_date' => '2026-10-21'])->assertStatus(201);
        LeaveRequest::where('status', 'pending')->update(['status' => 'approved']);

        $this->assertSame(2, LeaveRequest::usedCutiDaysFor($this->staff, 2026));
    }

    public function test_max_days_per_type_is_enforced_and_can_exceed_the_general_limit(): void
    {
        $this->request(['leave_type_id' => $this->typeId('cuti_menikah'), 'start_date' => '2026-10-12', 'end_date' => '2026-10-16'])
            ->assertStatus(422)->assertJsonPath('message', 'Cuti Menikah maksimal 3 hari.');

        // Cuti melahirkan 90 hari melebihi batas umum 30 hari, tetapi diizinkan jenisnya (lampiran wajib).
        $this->request(['leave_type_id' => $this->typeId('cuti_melahirkan'), 'start_date' => '2026-11-01', 'end_date' => '2027-01-15'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'wajib melampirkan dokumen'));

        $this->request([
            'leave_type_id' => $this->typeId('cuti_melahirkan'), 'start_date' => '2026-11-01', 'end_date' => '2027-01-15',
            'document' => UploadedFile::fake()->create('surat.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);
    }

    public function test_sick_leave_requires_a_document_from_the_configured_day_count(): void
    {
        $sakit = $this->typeId('sakit');

        $this->request(['leave_type_id' => $sakit, 'start_date' => '2026-10-12', 'end_date' => '2026-10-12'])->assertStatus(201);

        $this->request(['leave_type_id' => $sakit, 'start_date' => '2026-10-14', 'end_date' => '2026-10-15'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Sakit untuk 2 hari wajib melampirkan'));

        $this->request([
            'leave_type_id' => $sakit, 'start_date' => '2026-10-14', 'end_date' => '2026-10-15',
            'document' => UploadedFile::fake()->create('surat.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);

        // Staf mengubah aturan: surat dokter wajib dari hari pertama.
        LeaveType::where('code', 'sakit')->update(['document_required_from_days' => 1]);
        $this->request(['leave_type_id' => $sakit, 'start_date' => '2026-10-20', 'end_date' => '2026-10-20'])->assertStatus(422);
    }

    public function test_legacy_clients_sending_only_the_category_still_work_and_get_a_type(): void
    {
        $response = $this->request(['type' => 'sakit', 'start_date' => '2026-10-12', 'end_date' => '2026-10-12'])->assertStatus(201);

        $this->assertSame('sakit', $response->json('leave_request.type'));
        $this->assertSame('Sakit', $response->json('leave_request.leave_type_name'));
    }

    public function test_inactive_or_unknown_type_is_rejected(): void
    {
        LeaveType::where('code', 'izin')->update(['is_active' => false]);

        $this->request(['leave_type_id' => LeaveType::where('code', 'izin')->value('id')])->assertStatus(422);
        $this->request(['leave_type_id' => 99999])->assertStatus(422);
        $this->request([])->assertStatus(422);
    }

    public function test_a_type_in_use_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $this->request(['leave_type_id' => $this->typeId('izin')])->assertStatus(201);

        $admin = User::create(['name' => 'Admin', 'email' => 'a@test.local', 'password' => 'x', 'store_id' => $this->store->id]);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertFalse(LeaveTypeResource::canDelete(LeaveType::where('code', 'izin')->first()));
        $this->assertTrue(LeaveTypeResource::canDelete(LeaveType::where('code', 'sakit')->first()));

        Livewire::test(ListLeaveTypes::class)->assertSuccessful()
            ->assertCanSeeTableRecords(LeaveType::orderBy('sort_order')->take(10)->get());
    }

    // ------------------------------------------------------------- Profil Saya

    public function test_profile_shows_own_data_with_masked_identity_numbers(): void
    {
        $this->staff->forceFill([
            'nik' => '3174012345670001', 'npwp' => '123456789012345', 'bank_name' => 'BCA', 'bank_account_number' => '1234567890',
            'contract_end_date' => '2026-12-31', 'emergency_contact_name' => 'Budi', 'emergency_contact_phone' => '0812',
        ])->save();

        $profile = $this->actingAs($this->staff, 'api')->getJson('/api/staff/profile')->assertSuccessful()->json('profile');

        $this->assertSame('Rina', $profile['name']);
        $this->assertSame('Toko A', $profile['store_name']);
        $this->assertSame('2 tahun 9 bulan', $profile['employment']['tenure_label']);
        $this->assertSame(85, $profile['employment']['contract_days_left']);
        $this->assertSame('3174********0001', $profile['identity']['nik']);
        $this->assertSame('1234*******2345', $profile['identity']['npwp']);
        $this->assertSame('1234**7890', $profile['identity']['bank_account_number']);
        $this->assertSame('Budi', $profile['emergency_contact']['name']);
        $this->assertStringNotContainsString('3174012345670001', json_encode($profile));
    }

    public function test_profile_leave_balance_and_documents_are_the_users_own(): void
    {
        $this->request(['leave_type_id' => $this->typeId('cuti_tahunan'), 'start_date' => '2026-10-12', 'end_date' => '2026-10-14'])->assertStatus(201);
        $this->request(['leave_type_id' => $this->typeId('cuti_menikah'), 'start_date' => '2026-11-02', 'end_date' => '2026-11-03'])->assertStatus(201);
        LeaveRequest::query()->update(['status' => 'approved']);

        $other = User::create(['name' => 'Lain', 'email' => 'x@test.local', 'password' => 'x', 'store_id' => $this->store->id]);
        EmployeeDocument::create(['user_id' => $this->staff->id, 'type' => 'ktp', 'file_path' => 'employee-documents/a.pdf', 'original_filename' => 'ktp.pdf']);
        EmployeeDocument::create(['user_id' => $other->id, 'type' => 'ijazah', 'file_path' => 'employee-documents/b.pdf', 'original_filename' => 'ijazah.pdf']);

        $profile = $this->actingAs($this->staff, 'api')->getJson('/api/staff/profile')->json('profile');

        $this->assertSame(12, $profile['leave']['annual_quota']);
        $this->assertSame(3, $profile['leave']['annual_used']);
        $this->assertSame(9, $profile['leave']['annual_remaining']);
        $this->assertSame([['name' => 'Cuti Menikah', 'days' => 2]], $profile['leave']['other_used']);
        $this->assertCount(1, $profile['documents']);
        $this->assertSame('KTP', $profile['documents'][0]['type_label']);
        $this->assertArrayNotHasKey('file_path', $profile['documents'][0]);
    }

    public function test_profile_requires_login(): void
    {
        $this->getJson('/api/staff/profile')->assertStatus(401);
        $this->getJson('/api/staff/leave-types')->assertStatus(401);
    }
}
