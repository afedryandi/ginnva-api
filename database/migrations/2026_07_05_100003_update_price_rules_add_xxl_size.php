<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL: ubah enum dengan cara langsung ALTER COLUMN
        DB::statement("ALTER TABLE price_rules MODIFY COLUMN vehicle_size ENUM('S','M','L','XL','XXL') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE price_rules MODIFY COLUMN vehicle_size ENUM('S','M','L','XL') NOT NULL");
    }
};
