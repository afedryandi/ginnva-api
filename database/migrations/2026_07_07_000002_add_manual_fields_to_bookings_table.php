<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('customer_name')->nullable()->after('customer_id');
            $table->string('phone_number')->nullable()->after('customer_name');
            $table->enum('source', ['app', 'whatsapp', 'walk_in'])->default('app')->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['customer_name', 'phone_number', 'source']);
        });
    }
};
