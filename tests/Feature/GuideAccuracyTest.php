<?php

namespace Tests\Feature;

use App\Models\BookingMessage;
use App\Models\BookingSaleLine;
use App\Models\WarrantyMaintenanceSchedule;
use App\Support\GuideCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panduan Fase 0 (2026-10-22): pengaman agar isi panduan tidak melenceng dari sistem. Angka dan label yang bisa berubah diambil dari
 * kode, dan setiap istilah tebal (nama tombol/menu) harus benar-benar ada sebagai label.
 */
class GuideAccuracyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function guide(string $id): array
    {
        return collect(GuideCatalog::all())->firstWhere('id', $id) ?? $this->fail("Panduan {$id} tidak ada.");
    }

    private function text(array $guide): string
    {
        return implode("\n", array_merge([$guide['title'], $guide['summary']], $guide['steps'], $guide['tips']));
    }

    /** @return array<string, string> kelas menu => nama pendek, untuk semua resource dan halaman panel admin */
    private function panelClasses(): array
    {
        $panel = Filament::getPanel('admin');
        $map = [];

        foreach (array_merge($panel->getResources(), $panel->getPages()) as $class) {
            $map[class_basename($class)] = $class;
        }

        return $map;
    }

    // ------------------------------------------------------------- rujukan menu

    public function test_every_guided_menu_exists_and_is_visible_in_the_navigation(): void
    {
        $classes = $this->panelClasses();

        foreach (GuideCatalog::all() as $guide) {
            foreach ($guide['menus'] as $menu) {
                $this->assertArrayHasKey($menu, $classes, "Panduan {$guide['id']} merujuk menu {$menu} yang tidak ada di panel.");
                $this->assertTrue(
                    $classes[$menu]::shouldRegisterNavigation(),
                    "Panduan {$guide['id']} merujuk menu {$menu} yang disembunyikan (mis. lewat bendera fitur): staf tidak bisa menemukannya."
                );
            }
        }
    }

    public function test_the_attendance_guide_no_longer_sends_staff_to_the_hidden_work_schedule_menus(): void
    {
        $menus = $this->guide('absensi')['menus'];

        $this->assertNotContains('ShiftResource', $menus);
        $this->assertNotContains('WorkScheduleResource', $menus);
    }

    // ------------------------------------------------------------- istilah tebal = label nyata

    public function test_every_bold_term_is_a_real_label_in_the_code(): void
    {
        $corpus = '';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php' && $file->getFilename() !== 'GuideCatalog.php') {
                $corpus .= mb_strtolower((string) file_get_contents($file->getPathname())) . "\n";
            }
        }
        $views = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS));
        foreach ($views as $file) {
            $corpus .= mb_strtolower((string) file_get_contents($file->getPathname())) . "\n";
        }

        $terms = [];
        foreach (GuideCatalog::all() as $guide) {
            preg_match_all('/\*\*(.+?)\*\*/', $this->text($guide), $found);
            foreach ($found[1] as $term) {
                // "A / B / C" ditulis sebagai satu istilah tebal: tiap bagian diperiksa sendiri.
                foreach (explode(' / ', $term) as $part) {
                    $terms[trim($part)] = true;
                }
            }
        }

        $missing = [];
        foreach (array_keys($terms) as $term) {
            if (! in_array($term, GuideCatalog::MOBILE_LABELS, true) && ! str_contains($corpus, mb_strtolower($term))) {
                $missing[] = $term;
            }
        }

        $this->assertNotEmpty($terms);
        $this->assertSame([], $missing, 'Istilah tebal di panduan yang tidak ada sebagai label di kode: ' . implode(', ', $missing)
            . '. Perbaiki teks panduan, atau daftarkan di GuideCatalog::MOBILE_LABELS bila label itu hanya ada di aplikasi staf (setelah diperiksa).');
    }

    public function test_every_mobile_label_is_actually_used_in_a_guide(): void
    {
        $all = '';
        foreach (GuideCatalog::all() as $guide) {
            $all .= $this->text($guide) . "\n";
        }

        foreach (GuideCatalog::MOBILE_LABELS as $label) {
            $this->assertStringContainsString("**{$label}**", $all, "MOBILE_LABELS memuat \"{$label}\" yang tidak lagi dipakai panduan manapun (hapus).");
        }
    }

    // ------------------------------------------------------------- isi mengikuti kode

    public function test_the_booking_guide_follows_the_stage_service_slot_and_sla_definitions_in_code(): void
    {
        config(['booking.confirm_sla_hours' => 6, 'booking.light_slots' => ['08:00 - 10:00', '10:00 - 12:00']]);
        $text = $this->text($this->guide('booking'));

        foreach (array_merge(BookingMessage::PRODUCT_STAGES['kaca_film'], BookingMessage::PRODUCT_STAGES['ppf'], BookingMessage::SHARED_STAGES) as $label) {
            $this->assertStringContainsString($label, $text, "Tahap \"{$label}\" tidak disebut di panduan Booking.");
        }

        foreach (BookingSaleLine::SERVICE_TYPES as $label) {
            $this->assertStringContainsString($label, $text, "Layanan \"{$label}\" tidak disebut di panduan Booking.");
        }

        $this->assertStringContainsString('08:00 - 10:00, 10:00 - 12:00', $text, 'Slot jam diambil dari konfigurasi.');
        $this->assertStringContainsString('dalam 6 jam', $text, 'Batas respons diambil dari konfigurasi.');
    }

    public function test_detailing_and_premium_wash_are_explained_as_having_no_work_stages(): void
    {
        $text = $this->text($this->guide('booking'));

        $this->assertStringContainsString('Detailing dan Premium Wash tidak punya tahap pengerjaan', $text);
        $this->assertSame(
            ['kaca_film', 'ppf'],
            array_keys(BookingMessage::PRODUCT_STAGES),
            'Bila Detailing/Premium Wash mendapat tahap pengerjaan, perbarui panduan Booking dan Istilah.'
        );
    }

    public function test_the_warranty_guide_uses_the_maintenance_grace_period_from_code(): void
    {
        $this->assertStringContainsString('masa toleransi ' . WarrantyMaintenanceSchedule::GRACE_DAYS . ' hari', $this->text($this->guide('garansi-maintenance')));
    }

    public function test_the_sales_guide_exists_and_covers_the_steps_of_the_process_referral_action(): void
    {
        $text = $this->text($this->guide('penjualan'));

        foreach (['Proses Referral', 'Rincian Harga per Layanan', 'Nominal Transaksi', 'Pembayaran diterima', 'Piutang Usaha', 'Catat Uang Muka (DP)', 'Kembalikan Sisa DP', 'Proses Refund'] as $term) {
            $this->assertStringContainsString($term, $text, "Panduan Penjualan tidak menyebut \"{$term}\".");
        }
    }

    // ------------------------------------------------------------- panduan per layanan (Fase 1)

    public function test_each_of_the_four_services_has_its_own_guide(): void
    {
        $ids = collect(GuideCatalog::all())->pluck('id')->all();

        foreach (['layanan-ppf', 'layanan-kaca-film', 'layanan-detailing', 'layanan-premium-wash'] as $id) {
            $this->assertContains($id, $ids);
            $this->assertContains('BookingResource', $this->guide($id)['menus'], "Panduan {$id} harus terlihat oleh staf yang punya akses Booking.");
        }
    }

    public function test_heavy_service_guides_follow_the_stage_definitions_and_require_the_wet_signature(): void
    {
        $ppf = $this->text($this->guide('layanan-ppf'));
        $kacaFilm = $this->text($this->guide('layanan-kaca-film'));

        foreach (BookingMessage::PRODUCT_STAGES['ppf'] as $label) {
            $this->assertStringContainsString($label, $ppf, "Tahap PPF \"{$label}\" tidak disebut di panduan PPF.");
        }

        foreach (BookingMessage::PRODUCT_STAGES['kaca_film'] as $label) {
            $this->assertStringContainsString($label, $kacaFilm, "Tahap Kaca Film \"{$label}\" tidak disebut di panduan Kaca Film.");
        }

        foreach ([$ppf, $kacaFilm] as $text) {
            $this->assertStringContainsString('tanda tangan basah di kertas (wajib', $text);
            $this->assertStringContainsString('Garansi', $text);
            $this->assertStringContainsString('Catat Pemakaian', $text, 'Gulungan film yang dipakai harus dicatat.');
        }

        // Kaca Film memakai kode gulungan per posisi kaca; kuota maintenance hanya untuk PPF.
        $this->assertStringContainsString('Kode Gulungan — Kaca Depan', $kacaFilm);
        $this->assertStringContainsString('Kuota Maintenance', $ppf);
        $this->assertStringNotContainsString('Kuota Maintenance** dan', $kacaFilm);
    }

    public function test_light_service_guides_use_slots_from_config_have_no_work_stages_and_no_warranty(): void
    {
        config(['booking.light_slots' => ['08:00 - 10:00', '10:00 - 12:00']]);

        foreach (['layanan-detailing' => 'Detailing', 'layanan-premium-wash' => 'Premium Wash'] as $id => $name) {
            $text = $this->text($this->guide($id));

            $this->assertStringContainsString('08:00 - 10:00, 10:00 - 12:00', $text);
            $this->assertStringContainsString("{$name} tidak punya tahap pengerjaan", $text);
            $this->assertStringContainsString('Tanda tangan basah di kertas opsional', $text);
            $this->assertStringContainsString('hanya untuk PPF dan Kaca Film', $text, 'Garansi tidak berlaku untuk layanan ringan.');
            $this->assertStringNotContainsString('Buat **Garansi**', $text);
            $this->assertNotContains('WarrantyResource', $this->guide($id)['menus']);
        }
    }

    public function test_the_photo_retention_period_in_the_service_guides_comes_from_config(): void
    {
        config(['booking.photo_retention_months' => 12]);

        $this->assertStringContainsString('setelah 12 bulan', $this->text($this->guide('layanan-ppf')));
        $this->assertStringContainsString('setelah 12 bulan', $this->text($this->guide('layanan-kaca-film')));
    }

    // ------------------------------------------------------------- cakupan per kelompok (Fase 2)

    /** Kelompok menu (cluster) yang panduannya sudah lengkap: menu apa pun di dalamnya yang tampil di navigasi wajib punya panduan. */
    private const COMPLETED_CLUSTERS = [
        \App\Filament\Clusters\BookingCluster::class,
        \App\Filament\Clusters\KeuanganCluster::class,
        \App\Filament\Clusters\KaryawanCluster::class,
        \App\Filament\Clusters\InventarisCluster::class,
        \App\Filament\Clusters\ProdukCluster::class,
        \App\Filament\Clusters\PromosiCluster::class,
        \App\Filament\Clusters\PelangganCluster::class,
        \App\Filament\Clusters\AnalisaLaporanCluster::class,
        \App\Filament\Clusters\PenjualanCluster::class,
        \App\Filament\Clusters\MarketingKontenCluster::class,
        \App\Filament\Clusters\NotifikasiCluster::class,
        \App\Filament\Clusters\MasterDataCluster::class,
        \App\Filament\Clusters\SistemCluster::class,
    ];

    public function test_every_visible_menu_in_a_completed_cluster_has_a_guide(): void
    {
        $covered = collect(GuideCatalog::all())->pluck('menus')->flatten()->unique()->all();
        $missing = [];

        foreach ($this->panelClasses() as $name => $class) {
            $cluster = method_exists($class, 'getCluster') ? $class::getCluster() : null;

            if ($cluster && in_array($cluster, self::COMPLETED_CLUSTERS, true) && $class::shouldRegisterNavigation() && ! in_array($name, $covered, true)) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing, 'Menu di kelompok yang sudah lengkap tanpa panduan: ' . implode(', ', $missing)
            . '. Tambahkan panduan di GuideCatalog dan rujuk menunya di `menus`.');
    }

    public function test_the_booking_group_guides_exist(): void
    {
        $covered = collect(GuideCatalog::all())->pluck('menus')->flatten()->unique()->all();

        foreach (['SpkResource', 'QuotationResource', 'InvoiceResource', 'TechnicianResource', 'CapacityCalendar', 'StoreReviewResource', 'QcChecklistItemResource', 'AppSettingResource'] as $menu) {
            $this->assertContains($menu, $covered, "Menu {$menu} belum punya panduan.");
        }
    }

    public function test_the_employee_guides_exist_and_the_payroll_rules_are_documented(): void
    {
        $ids = collect(GuideCatalog::all())->pluck('id')->all();

        foreach (['karyawan-akun', 'cuti-kontrak', 'penggajian', 'k3-kinerja', 'hr-laporan', 'kebijakan-checklist'] as $id) {
            $this->assertContains($id, $ids);
        }

        $text = $this->text($this->guide('penggajian'));
        $this->assertStringContainsString('TIDAK menghitung pajak', $text);
        $this->assertStringContainsString('Setujui & Tandai Dibayar', $text);
    }

    public function test_the_inventory_guides_exist_and_the_purchase_flow_is_documented(): void
    {
        $ids = collect(GuideCatalog::all())->pluck('id')->all();

        foreach (['stok', 'aset-tetap', 'permohonan-pembelian', 'riwayat-stok-dashboard'] as $id) {
            $this->assertContains($id, $ids);
        }

        $text = $this->text($this->guide('permohonan-pembelian'));
        $this->assertStringContainsString('Catat Stok', $text);
        $this->assertStringContainsString('TIDAK menambah stok', $text);
    }

    public function test_the_product_customer_and_promo_guides_exist_and_keep_their_warnings(): void
    {
        $ids = collect(GuideCatalog::all())->pluck('id')->all();

        foreach (['katalog-produk', 'pelanggan', 'poin-promo', 'analisa-laporan'] as $id) {
            $this->assertContains($id, $ids);
        }

        $this->assertStringContainsString('PERMANEN', $this->text($this->guide('pelanggan')));
        $this->assertStringContainsString('MANUAL', $this->text($this->guide('poin-promo')));
    }

    public function test_the_report_guides_exist_and_explain_the_revenue_basis(): void
    {
        $ids = collect(GuideCatalog::all())->pluck('id')->all();

        foreach (['laporan-penjualan', 'laporan-operasional', 'laporan-pelanggan-poin', 'laporan-persediaan', 'laporan-karyawan'] as $id) {
            $this->assertContains($id, $ids);
        }

        $this->assertStringContainsString('sudah tercatat sebagai pendapatan', $this->text($this->guide('laporan-penjualan')));
    }

    public function test_the_marketing_guides_exist_and_keep_the_consent_rules(): void
    {
        $ids = collect(GuideCatalog::all())->pluck('id')->all();

        foreach (['lead-inquiry-kemitraan', 'konten-beranda', 'galeri-izin-foto', 'klaim-bukti'] as $id) {
            $this->assertContains($id, $ids);
        }

        $gallery = $this->text($this->guide('galeri-izin-foto'));
        $this->assertStringContainsString('bawaannya TIDAK ada izin', $gallery);
        $this->assertStringContainsString('TIDAK mengubah angka yang tampil di website', $this->text($this->guide('klaim-bukti')));
    }

    public function test_the_notification_master_data_and_activity_guides_keep_their_rules(): void
    {
        $ids = collect(GuideCatalog::all())->pluck('id')->all();

        foreach (['kirim-notifikasi', 'riwayat-notifikasi', 'master-data', 'histori-aktivitas'] as $id) {
            $this->assertContains($id, $ids);
        }

        $send = $this->text($this->guide('kirim-notifikasi'));
        $this->assertStringContainsString('hanya dikirim ke pelanggan yang sudah menyetujui', $send);
        $this->assertStringContainsString((string) config('marketing.promo_max_campaigns_per_week'), $send, 'Batas promosi per minggu harus sama dengan konfigurasi.');
        $this->assertStringContainsString(\App\Support\QuietHours::label(), $send, 'Jam tenang harus dibaca dari konfigurasi.');

        $activity = $this->text($this->guide('histori-aktivitas'));
        $this->assertStringContainsString('TIDAK otomatis melihatnya', $activity);
        $this->assertStringContainsString((string) number_format(\App\Exports\ActivityLogExport::$maxRows, 0, ',', '.'), $activity, 'Batas baris ekspor harus sama dengan kode.');
    }

    public function test_the_finance_guides_exist_and_the_year_end_order_is_documented(): void
    {
        $ids = collect(GuideCatalog::all())->pluck('id')->all();

        foreach (['transaksi-keuangan', 'kas-bank', 'jurnal-bagan-akun', 'hutang-piutang', 'tutup-periode', 'anggaran', 'laporan-keuangan'] as $id) {
            $this->assertContains($id, $ids);
        }

        $text = $this->text($this->guide('tutup-periode'));
        $this->assertStringContainsString('tutup tahun dulu, baru tutup Desember', $text, 'Urutan tutup tahun buku dan tutup Desember harus tertulis.');
        $this->assertStringContainsString('Laba Ditahan (3200)', $text);
    }

    public function test_the_approval_guide_explains_the_store_manager_threshold(): void
    {
        $guide = $this->guide('approval');
        $text = $this->text($guide);

        $this->assertContains('FinanceSettingsPage', $guide['menus']);
        $this->assertStringContainsString('Batas yang cukup disetujui Store Manager', $text);
        $this->assertStringContainsString('Secara bawaan semua nominal wajib disetujui direksi', $text);
    }

    public function test_the_lead_guide_uses_the_outcome_reasons_defined_in_code(): void
    {
        $text = $this->text($this->guide('quotation-lead'));

        $this->assertStringContainsString('Alasan Tidak Jadi', $text);
        $this->assertCount(7, \App\Support\LeadOutcome::REASONS, 'Alasan tidak jadi berubah: perbarui daftar di panduan Quotation (Lead).');
    }

    // ------------------------------------------------------------- fitur dari audit ACCURATE 5 (2026-10-26)

    public function test_the_features_added_after_the_accurate_audit_are_documented(): void
    {
        $expected = [
            'booking' => ['Duplikat (pelanggan datang lagi)'],
            'transaksi-keuangan' => ['Duplikat'],
            'garansi-maintenance' => ['Riwayat Klaim After-Sales', 'Ditangani oleh', 'Perkiraan Selesai', 'Bahan Terpakai', 'Catat Penyelesaian', 'Hasil Penanganan'],
            'stok' => ['Bahan Terpakai', 'Pemasok Utama'],
            'riwayat-stok-dashboard' => ['Buat Permintaan Beli'],
            'karyawan-akun' => ['Salin akses dari karyawan lain'],
            'invoice' => ['Profil perusahaan'],
            'tutup-periode' => ['Status buku', 'Batas penguncian', 'Periode tertutup terbaru', 'Aktivitas terakhir'],
            'dashboard' => ['Cadangan Database'],
            'kas-bank' => ['Catat sebagai Transaksi'],
            'katalog-produk' => ['Perubahan Harga Terjadwal', 'Terapkan Sekarang'],
            'aset-tetap' => ['Lepas Aset', 'Dampak ke pembukuan'],
            'laporan-keuangan' => ['Laba Rugi Bulanan'],
            'hutang-piutang' => ['Duplikat', 'Perputaran Piutang dan Hari Penagihan', 'Periode Perputaran Piutang', 'Hari Penagihan'],
            'permohonan-pembelian' => ['Jumlah Diterima', 'Buat Permintaan Beli', 'Pemasok Utama'],
        ];

        foreach ($expected as $id => $terms) {
            $text = $this->text($this->guide($id));

            foreach ($terms as $term) {
                $this->assertStringContainsString("**{$term}**", $text, "Panduan {$id} perlu menyebut **{$term}**.");
            }
        }
    }

    // ------------------------------------------------------------- laporan cakupan

    public function test_the_coverage_command_reports_without_changing_anything(): void
    {
        $this->artisan('guides:coverage', ['--list' => true])
            ->expectsOutputToContain('Cakupan panduan')
            ->expectsOutputToContain('Menu yang belum punya panduan')
            ->assertSuccessful();
    }
}
