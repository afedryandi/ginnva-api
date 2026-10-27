<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Urutan butir Daftar Periksa QC dinomori ulang 1, 2, 3, ... per layanan (sebelumnya 10, 20, 30, ... dan bawaan 100). Urutan relatif
     * tidak berubah (diurutkan menurut angka lama lalu id), jadi tampilan dan alur QC sama; urutan kini diatur dengan menyeret baris.
     * Aman dijalankan ulang.
     */
    public function up(): void
    {
        $services = DB::table('qc_checklist_items')->distinct()->pluck('service_key');

        foreach ($services as $serviceKey) {
            $position = 1;

            foreach (DB::table('qc_checklist_items')->where('service_key', $serviceKey)->orderBy('sort_order')->orderBy('id')->pluck('id') as $id) {
                DB::table('qc_checklist_items')->where('id', $id)->update(['sort_order' => $position++]);
            }
        }
    }

    public function down(): void
    {
        // Angka lama (10, 20, ...) tidak disimpan; urutan relatifnya tetap sama sehingga tidak ada yang perlu dikembalikan.
    }
};
