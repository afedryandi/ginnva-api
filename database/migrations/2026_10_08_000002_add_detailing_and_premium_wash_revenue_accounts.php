<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Keputusan user 2026-10-08: pendapatan Detailing dan Premium Wash dibukukan ke akun SENDIRI, tidak lagi
 * ke "Pendapatan Lain-lain" (4400) atau menumpang ke PPF. Menambah dua akun pendapatan baru (4500 dan 4600)
 * yang dipakai BookingRevenueSplitter.
 *
 * HANYA menambah akun yang belum ada (cek berdasarkan kode), tidak pernah menimpa akun yang sudah ada.
 * Jurnal lama TIDAK diubah: booking yang sudah dijurnal tetap di akun lamanya; refund-nya dibalik proporsional
 * dengan jurnal aslinya (lihat BookingRevenueSplitter::refundSplits()). Booking baru, atau booking yang jurnalnya
 * dibuat ulang lewat "Proses Referral", memakai aturan baru.
 */
return new class extends Migration
{
    private const ACCOUNTS = [
        ['code' => '4500', 'name' => 'Pendapatan Jasa Detailing', 'description' => 'Booking dengan jasa Detailing (dibagi rata bila digabung dengan PPF / Kaca Film / Premium Wash).'],
        ['code' => '4600', 'name' => 'Pendapatan Jasa Premium Wash', 'description' => 'Booking dengan jasa Premium Wash (dibagi rata bila digabung dengan jenis layanan lain).'],
    ];

    public function up(): void
    {
        foreach (self::ACCOUNTS as $account) {
            if (DB::table('chart_of_accounts')->where('code', $account['code'])->exists()) {
                continue;
            }

            DB::table('chart_of_accounts')->insert([
                'code' => $account['code'],
                'name' => $account['name'],
                'type' => 'pendapatan',
                'normal_balance' => 'kredit',
                'parent_id' => null,
                'is_postable' => true,
                'is_active' => true,
                'is_cash' => false,
                'is_contra' => false,
                'cash_flow_category' => 'operasional',
                'description' => $account['description'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Hanya hapus kalau belum dipakai jurnal mana pun (akun bersaldo/berjurnal tidak boleh hilang).
        foreach (self::ACCOUNTS as $account) {
            $id = DB::table('chart_of_accounts')->where('code', $account['code'])->value('id');

            if ($id && ! DB::table('journal_entry_lines')->where('chart_of_account_id', $id)->exists()) {
                DB::table('chart_of_accounts')->where('id', $id)->delete();
            }
        }
    }
};
