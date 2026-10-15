<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing/Konten Fase 0 "Ukur" (2026-10-22): atribusi lead. Setiap lead (quotation, inquiry produk, kemitraan) menyimpan dari mana
 * ia datang: kanal (formulir web, aplikasi, WhatsApp, telepon, dll.), UTM, click id iklan (gclid/fbclid), halaman asal, dan perujuk.
 * Semua nullable: lead lama tetap valid dan tampil "tidak tercatat".
 *
 * Khusus product_inquiries: `interest` (jenis minat) dan `created_by_user_id` (lead yang dicatat staf secara manual, mis. dari
 * WhatsApp, supaya tidak memicu notifikasi "inquiry baru" ke dirinya sendiri).
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
                $columns = [
                    'channel' => fn () => $t->string('channel', 30)->nullable()->index(),
                    'utm_source' => fn () => $t->string('utm_source', 100)->nullable(),
                    'utm_medium' => fn () => $t->string('utm_medium', 100)->nullable(),
                    'utm_campaign' => fn () => $t->string('utm_campaign', 150)->nullable()->index(),
                    'utm_content' => fn () => $t->string('utm_content', 150)->nullable(),
                    'click_id' => fn () => $t->string('click_id', 191)->nullable(),
                    'landing_path' => fn () => $t->string('landing_path', 255)->nullable(),
                    'referrer' => fn () => $t->string('referrer', 255)->nullable(),
                ];

                foreach ($columns as $name => $add) {
                    if (! Schema::hasColumn($table, $name)) {
                        $add();
                    }
                }
            });
        }

        if (Schema::hasTable('product_inquiries')) {
            Schema::table('product_inquiries', function (Blueprint $t) {
                if (! Schema::hasColumn('product_inquiries', 'interest')) {
                    $t->string('interest', 30)->nullable();
                }
                if (! Schema::hasColumn('product_inquiries', 'created_by_user_id')) {
                    $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_inquiries')) {
            Schema::table('product_inquiries', function (Blueprint $t) {
                if (Schema::hasColumn('product_inquiries', 'created_by_user_id')) {
                    $t->dropConstrainedForeignId('created_by_user_id');
                }
                if (Schema::hasColumn('product_inquiries', 'interest')) {
                    $t->dropColumn('interest');
                }
            });
        }

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (['channel', 'utm_campaign'] as $indexed) {
                if (Schema::hasColumn($table, $indexed)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex([$indexed]));
                }
            }

            $drop = array_values(array_filter(
                ['channel', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'click_id', 'landing_path', 'referrer'],
                fn (string $c) => Schema::hasColumn($table, $c)
            ));

            if ($drop) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($drop));
            }
        }
    }
};
