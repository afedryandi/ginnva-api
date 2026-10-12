<?php

namespace App\Support;

use App\Models\User;

/**
 * Tur pengenalan panel admin (2026-10-12, audit UX "onboarding & bantuan"): jendela singkat berisi beberapa langkah,
 * tampil sekali untuk tiap staf (admin_tour_seen_at masih kosong) atau kapan pun diminta lewat ?tur=1 (tautan "Ulangi tur"
 * di halaman Panduan). Tidak memakai Livewire: penandaan selesai lewat satu permintaan GET biasa (lihat AdminTourController).
 */
class AdminTour
{
    public static function enabled(): bool
    {
        return (bool) config('features.admin_tour', false);
    }

    public static function shouldShow(?User $user, bool $forced = false): bool
    {
        // Fitur disembunyikan dulu (config/features.php).
        if (! self::enabled()) {
            return false;
        }

        if (! $user || ! $user->canAccessStaffArea()) {
            return false;
        }

        return $forced || $user->admin_tour_seen_at === null;
    }

    /** @return array<int, array{title: string, body: string}> */
    public static function steps(): array
    {
        return [
            [
                'title' => 'Selamat datang di Ginnva Admin',
                'body' => 'Tur singkat ini menunjukkan cara bergerak di panel. Butuh sekitar satu menit, dan bisa dilewati kapan saja.',
            ],
            [
                'title' => 'Menu di bar atas',
                'body' => 'Setiap menu (Booking, Karyawan, Keuangan, Penjualan, Lainnya) membuka sub-menu. Menu yang tampil mengikuti akses akun Anda, jadi tidak semua orang melihat menu yang sama.',
            ],
            [
                'title' => 'Cari dan saring daftar',
                'body' => 'Di setiap daftar ada kolom cari dan tombol filter di atas tabel. Arahkan kursor ke badge status (mis. Pending, Posted) untuk melihat artinya.',
            ],
            [
                'title' => 'Notifikasi',
                'body' => 'Lonceng di kanan atas menampilkan hal yang perlu ditindak, seperti booking yang menunggu atau garansi yang hampir berakhir.',
            ],
            [
                'title' => 'Butuh bantuan?',
                'body' => 'Menu Panduan (di dropdown Lainnya atau menu avatar) menjelaskan alur tiap modul, lengkap dengan kolom pencarian. Tur ini bisa diulang dari halaman Panduan.',
            ],
        ];
    }
}
