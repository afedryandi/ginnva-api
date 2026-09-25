<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUG DIPERBAIKI 2026-09-25 (audit Invoice) -- product_discount ada di
 * migrasi awal (create_invoices_table) & $fillable/$casts, tapi TIDAK
 * PERNAH diisi dari form (InvoiceResource::form()) maupun dihitung di
 * InvoiceService::recalculateTotals()/lineTotal() -- selalu 0, kolom
 * hantu yang bisa menyesatkan pengembang berikutnya mengira ada logika
 * diskon per-produk di level invoice (diskon per-item yang genuinely
 * dipakai ada di InvoiceItem::discount_percent, kolom BEDA). Dipastikan
 * zero-usage lewat grep menyeluruh sebelum dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('product_discount');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('product_discount', 15, 2)->default(0)->after('subtotal');
        });
    }
};
