<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enkripsi data identitas karyawan di database (Karyawan Fase 0, 2026-10-28; UU PDP): NIK, NPWP, nomor BPJS, dan nomor rekening.
 * Kolomnya diperlebar jadi TEXT (hasil enkripsi jauh lebih panjang dari angkanya), lalu nilai yang sudah ada dienkripsi. Aman dijalankan
 * ulang: nilai yang sudah terenkripsi dilewati. PENTING: nilai ini hanya bisa dibaca dengan APP_KEY yang sama; simpan APP_KEY bersama cadangan.
 */
return new class extends Migration
{
    private const COLUMNS = ['nik', 'npwp', 'bpjs_kesehatan_number', 'bpjs_ketenagakerjaan_number', 'bank_account_number'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->text($column)->nullable()->change();
            }
        });

        DB::table('users')->select(['id', ...self::COLUMNS])->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                $updates = [];

                foreach (self::COLUMNS as $column) {
                    $value = $user->{$column};

                    if ($value === null || $value === '') {
                        continue;
                    }

                    try {
                        Crypt::decryptString($value);   // sudah terenkripsi: lewati
                    } catch (DecryptException) {
                        $updates[$column] = Crypt::encryptString($value);
                    }
                }

                if ($updates) {
                    DB::table('users')->where('id', $user->id)->update($updates);
                }
            }
        });
    }

    public function down(): void
    {
        // Sengaja tidak mendekripsi kembali ke teks biasa: mengembalikan data identitas ke bentuk terbuka bukan perilaku yang aman.
        // Kolom tetap TEXT dan tetap berisi nilai terenkripsi.
    }
};
