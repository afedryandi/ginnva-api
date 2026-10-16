<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing/Konten Fase 2 "Klaim dan konten" (2026-10-24), bagian banner dan berita. Semua kolom nullable dan tambahan: banner yang
 * sudah ada tetap tayang terus (tanpa jadwal), berita lama tetap memakai judul dan ringkasan seperti sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carousels', function (Blueprint $table) {
            if (! Schema::hasColumn('carousels', 'starts_at')) {
                $table->dateTime('starts_at')->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('carousels', 'ends_at')) {
                $table->dateTime('ends_at')->nullable()->after('starts_at');
            }
            if (! Schema::hasColumn('carousels', 'alt_text')) {
                $table->string('alt_text', 255)->nullable()->after('image');
            }
        });

        Schema::table('news', function (Blueprint $table) {
            if (! Schema::hasColumn('news', 'meta_title')) {
                $table->string('meta_title', 70)->nullable()->after('excerpt');
            }
            if (! Schema::hasColumn('news', 'meta_description')) {
                $table->string('meta_description', 170)->nullable()->after('meta_title');
            }
            if (! Schema::hasColumn('news', 'category')) {
                $table->string('category', 60)->nullable()->after('meta_description');
            }
            if (! Schema::hasColumn('news', 'cover_alt')) {
                $table->string('cover_alt', 255)->nullable()->after('cover_image');
            }
        });
    }

    public function down(): void
    {
        Schema::table('carousels', function (Blueprint $table) {
            foreach (['starts_at', 'ends_at', 'alt_text'] as $column) {
                if (Schema::hasColumn('carousels', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('news', function (Blueprint $table) {
            foreach (['meta_title', 'meta_description', 'category', 'cover_alt'] as $column) {
                if (Schema::hasColumn('news', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
