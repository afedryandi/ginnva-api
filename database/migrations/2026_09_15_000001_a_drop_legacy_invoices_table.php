<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Latihan rilis production (2026-10-13): database production memiliki tabel `invoices` BENTUK LAMA (invoice pembayaran
 * DP/pelunasan: invoice_number, booking_id wajib, type, amount, payment_method, validated_by, ...) dari fitur lama yang
 * migrasinya sudah tidak ada di kode. Tabel itu menabrak `2026_09_15_000002_create_invoices_table` (modul Invoice baru,
 * bentuk berbeda: store_id, customer_name, issue_date, ...) dengan error "Table 'invoices' already exists".
 *
 * Migrasi ini berjalan SEBELUM create_invoices_table (nama berkas diurutkan) dan hanya menghapus tabel lama kalau:
 *   - bentuknya memang lama (punya validated_by & type, tidak punya store_id), DAN
 *   - isinya kosong.
 * Tabel berbentuk baru (mis. di server dev) tidak disentuh. Tabel lama yang berisi data TIDAK dihapus: migrasi berhenti
 * dengan pesan jelas supaya ditinjau manual.
 *
 * Tidak reversibel (tabel lama sudah tidak punya kode yang memakainya).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }

        $legacyShape = Schema::hasColumn('invoices', 'validated_by')
            && Schema::hasColumn('invoices', 'type')
            && ! Schema::hasColumn('invoices', 'store_id');

        if (! $legacyShape) {
            return;
        }

        $rows = DB::table('invoices')->count();

        if ($rows > 0) {
            throw new RuntimeException("Tabel invoices bentuk lama berisi {$rows} baris. Tinjau manual sebelum melanjutkan migrasi; tabel ini tidak dihapus otomatis.");
        }

        Schema::disableForeignKeyConstraints();
        Schema::drop('invoices');
        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // Tidak reversibel: bentuk tabel lama sudah tidak punya kode pemakai.
    }
};
