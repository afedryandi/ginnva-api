<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateActivityLogTable extends Migration
{
    // Tabel activity_log di database production SUDAH ADA (1.122 baris, lengkap dengan kolom event & batch_uuid) sebelum migrasi
    // ini ada di kode (ditemukan di latihan rilis 2026-10-13). Dibuat tahan: dilewati kalau sudah ada, riwayat audit tidak disentuh.
    public function up()
    {
        if (Schema::connection(config('activitylog.database_connection'))->hasTable(config('activitylog.table_name'))) {
            return;
        }

        Schema::connection(config('activitylog.database_connection'))->create(config('activitylog.table_name'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->timestamps();
            $table->index('log_name');
        });
    }

    public function down()
    {
        Schema::connection(config('activitylog.database_connection'))->dropIfExists(config('activitylog.table_name'));
    }
}
