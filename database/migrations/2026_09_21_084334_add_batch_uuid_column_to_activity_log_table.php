<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddBatchUuidColumnToActivityLogTable extends Migration
{
    // Tabel activity_log di database production SUDAH ADA (1.122 baris, lengkap dengan kolom event & batch_uuid) sebelum migrasi
    // ini ada di kode (ditemukan di latihan rilis 2026-10-13). Dibuat tahan: dilewati kalau sudah ada, riwayat audit tidak disentuh.
    public function up()
    {
        if (Schema::connection(config('activitylog.database_connection'))->hasColumn(config('activitylog.table_name'), 'batch_uuid')) {
            return;
        }

        Schema::connection(config('activitylog.database_connection'))->table(config('activitylog.table_name'), function (Blueprint $table) {
            $table->uuid('batch_uuid')->nullable()->after('properties');
        });
    }

    public function down()
    {
        Schema::connection(config('activitylog.database_connection'))->table(config('activitylog.table_name'), function (Blueprint $table) {
            $table->dropColumn('batch_uuid');
        });
    }
}
