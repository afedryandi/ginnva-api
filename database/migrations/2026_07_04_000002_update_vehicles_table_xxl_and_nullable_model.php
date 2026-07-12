<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Tambah XXL ke enum size_category
        DB::statement("ALTER TABLE vehicles MODIFY COLUMN size_category ENUM('S', 'M', 'L', 'XL', 'XXL') NOT NULL");

        // Jadikan model nullable — admin akan isi tipe spesifik via Filament
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('model')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE vehicles MODIFY COLUMN size_category ENUM('S', 'M', 'L', 'XL') NOT NULL");

        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('model')->nullable(false)->change();
        });
    }
};
