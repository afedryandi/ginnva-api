<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel news BARU — sebelumnya berita di web hanya hardcoded link
     * keluar ke ginnvafilm.com (lihat components/news/NewsGrid.tsx di
     * project ginnva-web). Tabel ini menggantikan itu supaya berita bisa
     * dikelola dari Filament admin panel.
     *
     * source_url tetap disediakan (nullable) untuk kasus "berita ini cuma
     * link keluar ke artikel asli", supaya transisi dari behavior lama
     * tidak hilang kalau dibutuhkan.
     */
    public function up(): void
    {
        Schema::create('news', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('content')->nullable();
            $table->string('cover_image')->nullable();

            // Kalau diisi, berita dianggap "link keluar" (perilaku lama)
            $table->string('source_url')->nullable();

            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('is_published');
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news');
    }
};
