<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->unsignedTinyInteger('extension_years')->default(0)->after('expiry_date');
            $table->date('original_expiry_date')->nullable()->after('extension_years');
        });
    }

    public function down(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->dropColumn(['extension_years', 'original_expiry_date']);
        });
    }
};
