<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing/Konten Fase 1 "Lead terkelola" (2026-10-23): pemilik lead, hasil (menang/kalah) beserta alasan, dan waktu respons pertama
 * untuk tiga tipe lead (quotation, inquiry produk, kemitraan). Semua nullable: lead lama tetap valid.
 *
 *  - owner_user_id: staf yang bertanggung jawab menindaklanjuti.
 *  - outcome ('won'|'lost'), outcome_reason (alasan kalah), outcome_at.
 *  - contacted_at: kapan lead PERTAMA kali ditindak (status berganti dari 'new'). Quotation sudah punya kolom ini; ditambahkan ke
 *    product_inquiries dan partnership_inquiries.
 */
return new class extends Migration
{
    private const TABLES = ['quotations', 'product_inquiries', 'partnership_inquiries'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'owner_user_id')) {
                    $t->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn($table, 'outcome')) {
                    $t->string('outcome', 10)->nullable()->index();
                }
                if (! Schema::hasColumn($table, 'outcome_reason')) {
                    $t->string('outcome_reason', 40)->nullable();
                }
                if (! Schema::hasColumn($table, 'outcome_at')) {
                    $t->timestamp('outcome_at')->nullable();
                }
                if ($table !== 'quotations' && ! Schema::hasColumn($table, 'contacted_at')) {
                    $t->timestamp('contacted_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (Schema::hasColumn($table, 'owner_user_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('owner_user_id'));
            }

            if (Schema::hasColumn($table, 'outcome')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex(['outcome']));
            }

            $columns = ['outcome', 'outcome_reason', 'outcome_at'];
            if ($table !== 'quotations') {
                $columns[] = 'contacted_at'; // quotations sudah punya contacted_at sebelum migrasi ini: tidak dihapus
            }

            $drop = array_values(array_filter($columns, fn (string $c) => Schema::hasColumn($table, $c)));

            if ($drop) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($drop));
            }
        }
    }
};
