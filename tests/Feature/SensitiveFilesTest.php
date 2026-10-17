<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\Store;
use App\Models\User;
use App\Models\WarningLetter;
use App\Support\SensitiveFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Karyawan Fase 0 (2026-10-28): selfie absen, lampiran izin/sakit, dan scan surat peringatan disimpan di disk PRIVAT dan hanya dibuka lewat
 * tautan sementara; berkas lama dipindah dengan satu perintah; NIK, NPWP, BPJS, dan rekening terenkripsi di database.
 */
class SensitiveFilesTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Storage::fake('public');
        Storage::fake('local');
        Role::findOrCreate('kasir', 'web');
        Carbon::setTestNow('2026-10-07 09:00:00');

        $this->store = Store::create([
            'city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true,
            'latitude' => -6.2000, 'longitude' => 106.8000, 'attendance_radius_meters' => 100,
            'opening_hours' => [['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'open' => '09:00', 'close' => '18:00']],
        ]);
        $this->staff = $this->user();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(array $extra = []): User
    {
        $user = User::create($extra + ['name' => 'Karyawan ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'join_date' => '2025-01-01', 'is_active' => true]);
        $user->assignRole('kasir');

        return $user;
    }

    // ------------------------------------------------------------- penyimpanan privat

    public function test_new_selfies_and_leave_documents_are_stored_privately_and_served_with_temporary_links(): void
    {
        $in = $this->actingAs($this->staff, 'api')->post('/api/staff/attendance/clock-in', [
            'latitude' => -6.2000, 'longitude' => 106.8000, 'photo' => UploadedFile::fake()->image('selfie.jpg', 400, 400),
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $path = Attendance::firstOrFail()->clock_in_photo;
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->assertMatchesRegularExpression('/expir/', $in->json('attendance.clock_in_photo_url'), 'Tautan sementara, bukan alamat tetap.');

        $leave = $this->actingAs($this->staff, 'api')->post('/api/staff/leave-requests', [
            'type' => 'sakit', 'start_date' => '2026-10-12', 'end_date' => '2026-10-12', 'reason' => 'Demam',
            'document' => UploadedFile::fake()->create('surat-dokter.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $document = LeaveRequest::firstOrFail()->document;
        Storage::disk('local')->assertExists($document);
        Storage::disk('public')->assertMissing($document);
        $this->assertMatchesRegularExpression('/expir/', $leave->json('leave_request.document_url'));
    }

    public function test_legacy_public_files_still_resolve_until_they_are_moved(): void
    {
        Storage::disk('public')->put('attendance-photos/lama.jpg', 'lama');

        $url = SensitiveFiles::url('attendance-photos/lama.jpg');

        $this->assertNotNull($url);
        $this->assertStringContainsString('attendance-photos/lama.jpg', $url);
        $this->assertDoesNotMatchRegularExpression('/expir/', $url, 'Berkas lama masih alamat tetap sampai dipindah.');
        $this->assertNull(SensitiveFiles::url('attendance-photos/tidak-ada.jpg'));
        $this->assertNull(SensitiveFiles::url(null));
    }

    // ------------------------------------------------------------- perintah pemindah

    public function test_the_move_command_relocates_old_files_keeps_the_paths_and_is_safe_to_repeat(): void
    {
        Storage::disk('public')->put('attendance-photos/masuk.jpg', 'masuk');
        Storage::disk('public')->put('attendance-photos/keluar.jpg', 'keluar');
        Storage::disk('public')->put('leave-requests/sakit.pdf', 'surat');
        Storage::disk('public')->put('warning-letters/sp1.pdf', 'sp');

        Attendance::create(['user_id' => $this->staff->id, 'store_id' => $this->store->id, 'date' => '2026-10-05', 'entry_type' => 'clock', 'clock_in_photo' => 'attendance-photos/masuk.jpg', 'clock_out_photo' => 'attendance-photos/keluar.jpg']);
        LeaveRequest::create(['request_number' => 'IZ-1', 'user_id' => $this->staff->id, 'store_id' => $this->store->id, 'type' => 'sakit', 'start_date' => '2026-10-06', 'end_date' => '2026-10-06', 'reason' => 'Demam', 'status' => 'pending', 'document' => 'leave-requests/sakit.pdf']);
        WarningLetter::create(['warning_number' => 'SP-1', 'user_id' => $this->staff->id, 'store_id' => $this->store->id, 'level' => 'sp1', 'reason' => 'Telat', 'issued_date' => '2026-10-01', 'document' => 'warning-letters/sp1.pdf']);

        $this->artisan('files:move-sensitive-to-private', ['--dry-run' => true])->assertSuccessful();
        Storage::disk('public')->assertExists('attendance-photos/masuk.jpg');
        Storage::disk('local')->assertMissing('attendance-photos/masuk.jpg');

        $this->artisan('files:move-sensitive-to-private')->assertSuccessful();

        foreach (['attendance-photos/masuk.jpg', 'attendance-photos/keluar.jpg', 'leave-requests/sakit.pdf', 'warning-letters/sp1.pdf'] as $path) {
            Storage::disk('local')->assertExists($path);
            Storage::disk('public')->assertMissing($path);
        }

        $this->assertSame('masuk', Storage::disk('local')->get('attendance-photos/masuk.jpg'), 'Isi berkas utuh.');
        $this->assertSame('attendance-photos/masuk.jpg', Attendance::firstOrFail()->clock_in_photo, 'Path di database tidak berubah.');
        $this->assertMatchesRegularExpression('/expir/', SensitiveFiles::url('attendance-photos/masuk.jpg'));

        $this->artisan('files:move-sensitive-to-private')->assertSuccessful();   // ulang: tidak ada yang rusak
        Storage::disk('local')->assertExists('attendance-photos/masuk.jpg');
    }

    // ------------------------------------------------------------- enkripsi identitas

    public function test_identity_fields_are_encrypted_in_the_database_but_read_normally(): void
    {
        $user = $this->user([
            'nik' => '3175010101010001', 'npwp' => '12.345.678.9-012.000', 'bpjs_kesehatan_number' => '0001234567890',
            'bpjs_ketenagakerjaan_number' => '19012345678', 'bank_account_number' => '1234567890', 'bank_name' => 'BCA',
        ]);

        $raw = DB::table('users')->where('id', $user->id)->first();

        foreach (['nik' => '3175010101010001', 'npwp' => '12.345.678.9-012.000', 'bpjs_kesehatan_number' => '0001234567890', 'bpjs_ketenagakerjaan_number' => '19012345678', 'bank_account_number' => '1234567890'] as $column => $plain) {
            $this->assertNotSame($plain, $raw->{$column}, "{$column} tidak boleh tersimpan sebagai teks biasa.");
            $this->assertStringNotContainsString($plain, $raw->{$column});
        }

        $fresh = $user->fresh();
        $this->assertSame('3175010101010001', $fresh->nik);
        $this->assertSame('12.345.678.9-012.000', $fresh->npwp);
        $this->assertSame('1234567890', $fresh->bank_account_number);
        $this->assertSame('BCA', DB::table('users')->where('id', $user->id)->value('bank_name'), 'Nama bank bukan data yang dienkripsi.');

        $fresh->update(['nik' => '3175010101010002']);
        $this->assertSame('3175010101010002', $user->fresh()->nik);

        $this->assertNull($this->user()->nik, 'Kosong tetap kosong.');
    }
}
