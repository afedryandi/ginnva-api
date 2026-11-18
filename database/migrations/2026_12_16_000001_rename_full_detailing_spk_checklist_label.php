<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Butir "Uraian Pekerjaan" SPK: "Full Detailing Car Care" disingkat menjadi "Full Detailing" (permintaan pemilik, 2026-12-16).
 * Daftar bawaan dan pemetaan booking sudah memakai nama baru; butir SPK yang sudah tersimpan ikut diganti supaya cetakan dan
 * pencocokan label dengan booking tetap konsisten.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('spk_checklist_items')
            ->where('category', 'pekerjaan')
            ->where('label', 'Full Detailing Car Care')
            ->update(['label' => 'Full Detailing']);
    }

    public function down(): void
    {
        DB::table('spk_checklist_items')
            ->where('category', 'pekerjaan')
            ->where('label', 'Full Detailing')
            ->update(['label' => 'Full Detailing Car Care']);
    }
};
