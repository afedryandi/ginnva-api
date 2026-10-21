<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histori Aktivitas Fase 4 (2026-10-21): daftar Histori Aktivitas diurutkan dan difilter menurut tanggal, tetapi activity_log hanya
 * berindeks log_name, objek, dan pelaku (migrasi bawaan paket). Dua indeks ditambahkan: created_at (urutan dan filter tanggal) dan
 * (log_name, created_at) (filter modul + tanggal). Aman dijalankan ulang: indeks yang sudah ada dilewati. Tidak menyentuh isi tabel.
 */
return new class extends Migration
{
    private function table(): string
    {
        return config('activitylog.table_name', 'activity_log');
    }

    private function connection(): ?string
    {
        return config('activitylog.database_connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->connection());

        if (! $schema->hasTable($this->table())) {
            return;
        }

        if (! $schema->hasIndex($this->table(), 'activity_log_created_at_index')) {
            $schema->table($this->table(), fn (Blueprint $table) => $table->index('created_at', 'activity_log_created_at_index'));
        }

        if (! $schema->hasIndex($this->table(), 'activity_log_log_name_created_at_index')) {
            $schema->table($this->table(), fn (Blueprint $table) => $table->index(['log_name', 'created_at'], 'activity_log_log_name_created_at_index'));
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection());

        if (! $schema->hasTable($this->table())) {
            return;
        }

        foreach (['activity_log_created_at_index', 'activity_log_log_name_created_at_index'] as $index) {
            if ($schema->hasIndex($this->table(), $index)) {
                $schema->table($this->table(), fn (Blueprint $table) => $table->dropIndex($index));
            }
        }
    }
};
