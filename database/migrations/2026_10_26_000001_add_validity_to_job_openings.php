<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lowongan Kerja (Marketing/Konten Fase 4): tanggal terbit dan batas berlaku. Google Jobs mewajibkan datePosted dan menyarankan
 * validThrough, dan lowongan kedaluwarsa harus hilang dari halaman. Kolom nullable: lowongan lama tanpa batas tetap tayang seperti
 * sebelumnya; tanggal terbit diisi dari tanggal dibuatnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_openings', function (Blueprint $table) {
            if (! Schema::hasColumn('job_openings', 'posted_at')) {
                $table->dateTime('posted_at')->nullable()->after('requirements');
            }
            if (! Schema::hasColumn('job_openings', 'valid_through')) {
                $table->date('valid_through')->nullable()->after('posted_at');
            }
        });

        DB::table('job_openings')->whereNull('posted_at')->update(['posted_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('job_openings', function (Blueprint $table) {
            foreach (['posted_at', 'valid_through'] as $column) {
                if (Schema::hasColumn('job_openings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
