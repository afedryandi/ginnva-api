<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Standarkan penamaan model Tesla supaya konsisten dengan model lain
 * (mis. "MODEL 3") — sebelumnya tertulis "Y LONG RANGE" tanpa prefix
 * "MODEL", sekarang jadi "MODEL Y" generik (tidak spesifik varian
 * baterai).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('vehicles')
            ->where('brand', 'TESLA')
            ->where('model', 'Y LONG RANGE')
            ->update(['model' => 'MODEL Y']);
    }

    public function down(): void
    {
        DB::table('vehicles')
            ->where('brand', 'TESLA')
            ->where('model', 'MODEL Y')
            ->update(['model' => 'Y LONG RANGE']);
    }
};
