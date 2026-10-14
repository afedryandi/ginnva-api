<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tes kontrak aplikasi mobile ↔ API (Fase 0 pengerjaan ulang booking, 2026-10-14): memakai BENTUK kiriman yang sama
 * dengan aplikasi — multipart (FormData) yang mengirim SEMUA nilai sebagai teks, termasuk berkas. Tes lain mengirim JSON
 * atau nilai native (mis. is_mocked '1'), jadi bug seperti is_mocked "false" pada absen lolos sebelum ini.
 * Tambahkan kasus baru di sini setiap kali aplikasi mendapat kiriman FormData.
 */
class MobileMultipartContractTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Storage::fake('public');
        Role::findOrCreate('kasir', 'web');

        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->staff = User::create([
            'name' => 'Staf', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store->id, 'join_date' => '2025-01-01',
        ]);
        $this->staff->assignRole('kasir');
    }

    public function test_leave_request_sent_as_multipart_text_fields_with_a_pdf_attachment(): void
    {
        $this->actingAs($this->staff, 'api')->post('/api/staff/leave-requests', [
            'type' => 'sakit',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'reason' => 'Demam, surat dokter terlampir',
            'document' => UploadedFile::fake()->create('surat-dokter.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $this->assertSame(1, LeaveRequest::count());
    }

    public function test_leave_request_sent_as_multipart_with_an_image_attachment_from_the_camera(): void
    {
        $this->actingAs($this->staff, 'api')->post('/api/staff/leave-requests', [
            'type' => 'izin',
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'reason' => 'Urusan keluarga',
            'document' => UploadedFile::fake()->image('foto.jpg', 800, 600),
        ], ['Accept' => 'application/json'])->assertStatus(201);
    }

    public function test_leave_request_sent_as_plain_json_without_attachment_still_works(): void
    {
        $this->actingAs($this->staff, 'api')->postJson('/api/staff/leave-requests', [
            'type' => 'cuti',
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'reason' => 'Liburan keluarga',
        ])->assertStatus(201);
    }
}
