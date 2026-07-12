<?php

namespace Database\Seeders;

use App\Models\JobOpening;
use Illuminate\Database\Seeder;

class JobOpeningSeeder extends Seeder
{
    /**
     * Jalankan dengan: php artisan db:seed --class=JobOpeningSeeder
     *
     * Mengisi 3 contoh lowongan awal (sebelumnya hardcoded di web
     * CareerContent.tsx). Aman dijalankan ulang — pakai updateOrCreate
     * berdasarkan title supaya tidak menduplikat.
     *
     * Setelah di-seed, semua pengelolaan lowongan dilakukan dari Filament
     * admin panel (menu "Lowongan Kerja").
     */
    public function run(): void
    {
        $jobs = [
            [
                'title'       => 'Teknisi Instalasi PPF & Kaca Film',
                'department'  => 'Operasional',
                'location'    => 'PIK 2, Tangerang',
                'type'        => 'Full-time',
                'description' => 'Bertanggung jawab melakukan pemasangan Paint Protection Film dan Kaca Film pada kendaraan pelanggan sesuai standar kualitas Ginnva. Pelatihan dan sertifikasi resmi akan diberikan.',
                'requirements' => [
                    'Pengalaman instalasi PPF / kaca film / wrapping minimal 1 tahun (fresh graduate dengan minat tinggi dipersilakan melamar)',
                    'Teliti, rapi, dan berorientasi pada detail',
                    'Mampu bekerja dalam tim dan target waktu',
                    'Bersedia ditempatkan di Flagship Store PIK 2, Tangerang',
                ],
                'sort_order'  => 1,
            ],
            [
                'title'       => 'Sales Consultant',
                'department'  => 'Penjualan',
                'location'    => 'PIK 2, Tangerang',
                'type'        => 'Full-time',
                'description' => 'Menjadi garda terdepan Ginnva dalam melayani calon pelanggan — mulai dari konsultasi produk, penawaran harga, hingga after-sales. Produk knowledge lengkap akan diberikan saat onboarding.',
                'requirements' => [
                    'Pengalaman sales/CS di industri otomotif menjadi nilai tambah',
                    'Komunikatif, persuasif, dan berpenampilan menarik',
                    'Terbiasa menggunakan WhatsApp Business dan media sosial',
                    'Memiliki kendaraan pribadi menjadi nilai tambah',
                ],
                'sort_order'  => 2,
            ],
            [
                'title'       => 'Admin & Operasional Toko',
                'department'  => 'Operasional',
                'location'    => 'PIK 2, Tangerang',
                'type'        => 'Full-time',
                'description' => 'Mengelola administrasi toko: pencatatan penjualan, penjadwalan booking instalasi, registrasi garansi pelanggan, dan koordinasi stok material dengan tim teknisi.',
                'requirements' => [
                    'Minimal lulusan SMA/SMK sederajat',
                    'Menguasai komputer dasar (spreadsheet, sistem kasir/admin)',
                    'Teliti dalam pencatatan dan pengarsipan',
                    'Mampu berkomunikasi dengan baik dengan pelanggan',
                ],
                'sort_order'  => 3,
            ],
        ];

        foreach ($jobs as $job) {
            JobOpening::updateOrCreate(
                ['title' => $job['title']],
                $job
            );
        }
    }
}
