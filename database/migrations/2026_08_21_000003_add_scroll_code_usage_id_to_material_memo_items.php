<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_memo_items', function (Blueprint $table) {
            // Link ke baris riwayat pemakaian meter (scroll_code_usages)
            // yang dibuat bareng baris ini — dibutuhkan supaya fitur edit
            // jumlah bisa ikut mengoreksi baris riwayatnya juga, bukan cuma
            // remaining_length_meters di ScrollCode. Null untuk baris
            // item_type raw_material/consumable_item.
            $table->foreignId('scroll_code_usage_id')->nullable()
                ->after('meters_used')
                ->constrained('scroll_code_usages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('material_memo_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scroll_code_usage_id');
        });
    }
};
