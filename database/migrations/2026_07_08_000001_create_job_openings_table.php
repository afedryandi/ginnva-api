<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel lowongan kerja (careers). Sebelumnya daftar lowongan di web
     * hardcoded di app/karier/CareerContent.tsx (project ginnva-web).
     * Tabel ini menggantikan itu supaya lowongan bisa dikelola dari
     * Filament admin panel — tambah, edit, publish/unpublish, hapus.
     *
     * requirements disimpan sebagai JSON (array of string) supaya admin
     * bisa mengisi daftar kualifikasi baris per baris lewat Repeater.
     */
    public function up(): void
    {
        Schema::create('job_openings', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->string('department');
            $table->string('location')->default('PIK 2, Tangerang');
            $table->string('type')->default('Full-time'); // Full-time / Part-time / Kontrak
            $table->text('description');
            $table->json('requirements')->nullable();

            $table->boolean('is_published')->default(true);

            // Urutan tampil di web (drag-reorder di Filament). Angka kecil
            // tampil lebih dulu.
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index('is_published');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_openings');
    }
};
