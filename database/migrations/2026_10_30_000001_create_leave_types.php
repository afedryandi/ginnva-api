<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jenis Cuti yang bisa diatur staf (Karyawan Fase 2, 2026-10-30). Menggantikan daftar tetap izin/sakit/cuti: tiap jenis punya batas hari
 * per pengajuan, syarat lampiran (mis. surat dokter), dan apakah memotong jatah cuti tahunan. Kolom leave_requests.type tetap ada sebagai
 * kategori (izin/sakit/cuti) supaya laporan, absensi, dan penjadwalan lama tidak berubah.
 *
 * Tiga jenis dasar dibuat di sini dan semua pengajuan lama dikaitkan ke jenis yang sesuai kategorinya; jenis lain (haid, melahirkan,
 * menikah, dst.) ditambahkan oleh App\Models\LeaveType::ensureDefaults() saat menu dibuka.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('category', 10); // izin | sakit | cuti
            $table->boolean('deducts_annual_quota')->default(false);
            $table->unsignedSmallInteger('max_days')->nullable();
            $table->unsignedSmallInteger('document_required_from_days')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('leave_type_id')->nullable()->after('type')->constrained('leave_types')->nullOnDelete();
        });

        $now = now();
        $base = [
            ['code' => 'izin', 'name' => 'Izin', 'category' => 'izin', 'deducts_annual_quota' => false, 'document_required_from_days' => null, 'sort_order' => 20],
            ['code' => 'sakit', 'name' => 'Sakit', 'category' => 'sakit', 'deducts_annual_quota' => false, 'document_required_from_days' => 2, 'sort_order' => 30],
            ['code' => 'cuti_tahunan', 'name' => 'Cuti Tahunan', 'category' => 'cuti', 'deducts_annual_quota' => true, 'document_required_from_days' => null, 'sort_order' => 10],
        ];

        foreach ($base as $row) {
            DB::table('leave_types')->insert($row + ['max_days' => null, 'notes' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        foreach (['izin' => 'izin', 'sakit' => 'sakit', 'cuti' => 'cuti_tahunan'] as $category => $code) {
            DB::table('leave_requests')
                ->where('type', $category)
                ->update(['leave_type_id' => DB::table('leave_types')->where('code', $code)->value('id')]);
        }
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leave_type_id');
        });

        Schema::dropIfExists('leave_types');
    }
};
