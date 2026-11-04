<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Karyawan boleh masuk dengan No. HP dan boleh tidak punya email.
 *
 * - users.email menjadi boleh kosong (NULL). Indeks unik tetap ada; MySQL mengizinkan banyak NULL.
 * - users.phone_digits: No. HP dalam bentuk baku (628xxxx), diisi otomatis oleh model User, unik. Dipakai untuk mencocokkan 0812…,
 *   62812…, dan +62812… sebagai nomor yang sama. Kolom phone asli tidak diubah.
 *
 * Data lama diisi balik di sini. Bila dua karyawan ternyata memakai nomor yang sama, yang ber-id terkecil mendapat nomor bakunya dan
 * yang lain dikosongkan (tidak bisa masuk dengan No. HP sampai nomornya dibetulkan di menu User); daftarnya dicetak saat migrasi.
 * down() tidak mengembalikan email menjadi wajib karena sudah ada akun yang mungkin tanpa email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('phone_digits', 20)->nullable()->unique()->after('phone');
        });

        $taken = [];
        $duplicates = [];

        DB::table('users')->whereNotNull('phone')->orderBy('id')->get(['id', 'name', 'phone'])->each(function ($user) use (&$taken, &$duplicates) {
            $digits = preg_replace('/\D/', '', (string) $user->phone);

            if (str_starts_with($digits, '0')) {
                $digits = '62' . substr($digits, 1);
            } elseif (str_starts_with($digits, '8')) {
                $digits = '62' . $digits;
            }

            if (strlen($digits) < 9 || strlen($digits) > 20) {
                return;
            }

            if (isset($taken[$digits])) {
                $duplicates[] = "{$user->name} (#{$user->id}) sama dengan #{$taken[$digits]}: {$user->phone}";

                return;
            }

            $taken[$digits] = $user->id;
            DB::table('users')->where('id', $user->id)->update(['phone_digits' => $digits]);
        });

        foreach ($duplicates as $line) {
            echo "  PERINGATAN No. HP kembar, tidak bisa dipakai masuk: {$line}\n";
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone_digits']);
            $table->dropColumn('phone_digits');
        });
    }
};
