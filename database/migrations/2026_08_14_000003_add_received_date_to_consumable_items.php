<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumable_items', function (Blueprint $table) {
            $table->date('received_date')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('consumable_items', function (Blueprint $table) {
            $table->dropColumn('received_date');
        });
    }
};
