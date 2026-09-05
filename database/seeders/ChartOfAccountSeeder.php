<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;

/**
 * Bagan Akun standar Ginnva — lihat dokumen desain "Bagan Akun Ginnva"
 * (klasifikasi 1000 Aset s.d. 8000 Pajak) untuk penjelasan lengkap
 * tiap akun & keputusan desainnya.
 *
 * Jalankan dengan: php artisan db:seed --class=ChartOfAccountSeeder
 * (juga ikut jalan otomatis lewat DatabaseSeeder untuk instalasi baru).
 *
 * updateOrCreate per 'code' — aman dijalankan ulang tanpa duplikasi.
 * parent_id di-resolve dari 'parent' (kode akun induk) di baris data,
 * BUKAN kolom DB — makanya diproses 2 tahap: (1) upsert semua akun
 * dulu TANPA parent_id, (2) baru isi parent_id-nya lewat lookup kode.
 * Urutan ini penting karena parent harus sudah ada baris-nya sebelum
 * anaknya bisa mereferensikan parent_id-nya.
 */
class ChartOfAccountSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = $this->accounts();

        foreach ($accounts as $data) {
            ChartOfAccount::updateOrCreate(
                ['code' => $data['code']],
                [
                    'name' => $data['name'],
                    'type' => $data['type'],
                    'normal_balance' => ChartOfAccount::normalBalanceFor($data['type']),
                    'is_postable' => $data['postable'] ?? true,
                    'is_active' => true,
                    'is_cash' => $data['cash'] ?? false,
                    'cash_flow_category' => $data['flow'] ?? null,
                    'description' => $data['description'] ?? null,
                ]
            );
        }

        foreach ($accounts as $data) {
            if (empty($data['parent'])) {
                continue;
            }

            $parentId = ChartOfAccount::where('code', $data['parent'])->value('id');
            ChartOfAccount::where('code', $data['code'])->update(['parent_id' => $parentId]);
        }
    }

    /**
     * @return array<int, array{code: string, name: string, type: string, parent?: ?string, postable?: bool, cash?: bool, flow?: ?string, description?: ?string}>
     */
    private function accounts(): array
    {
        return [
            // ─── 1000 ASET ──────────────────────────────────────────
            ['code' => '1100', 'name' => 'Aset Lancar', 'type' => 'aset', 'postable' => false],
            ['code' => '1101', 'name' => 'Kas di Tangan (per toko)', 'type' => 'aset', 'parent' => '1100', 'cash' => true],
            ['code' => '1102', 'name' => 'Kas di Bank', 'type' => 'aset', 'parent' => '1100', 'cash' => true],
            ['code' => '1110', 'name' => 'Piutang Usaha', 'type' => 'aset', 'parent' => '1100', 'flow' => 'operasional', 'description' => 'Booking selesai, pembayaran belum lunas.'],
            ['code' => '1120', 'name' => 'Piutang Karyawan', 'type' => 'aset', 'parent' => '1100', 'flow' => 'operasional', 'description' => 'Kasbon/pinjaman karyawan.'],
            ['code' => '1130', 'name' => 'Persediaan Bahan Baku', 'type' => 'aset', 'parent' => '1100', 'flow' => 'operasional', 'description' => 'Adhesive, backing paper, primer, dll — nilai dari RawMaterial.current_stock × unit_cost.'],
            ['code' => '1131', 'name' => 'Persediaan Barang Habis Pakai', 'type' => 'aset', 'parent' => '1100', 'flow' => 'operasional', 'description' => 'Nilai dari ConsumableItem.current_stock × unit_cost.'],
            ['code' => '1132', 'name' => 'Persediaan Produk PPF/Kaca Film', 'type' => 'aset', 'parent' => '1100', 'flow' => 'operasional', 'description' => 'Gulungan siap pasang berstatus in_stock (InventoryItem) — perlu kolom harga per unit, belum ada.'],
            ['code' => '1140', 'name' => 'Uang Muka / Beban Dibayar Dimuka', 'type' => 'aset', 'parent' => '1100', 'flow' => 'operasional', 'description' => 'Sewa toko, asuransi dibayar setahun di muka.'],
            ['code' => '1150', 'name' => 'PPN Masukan', 'type' => 'aset', 'parent' => '1100', 'flow' => 'operasional', 'description' => 'Hanya relevan kalau Ginnva berstatus PKP.'],

            ['code' => '1200', 'name' => 'Aset Tetap', 'type' => 'aset', 'postable' => false],
            ['code' => '1210', 'name' => 'Peralatan & Mesin', 'type' => 'aset', 'parent' => '1200', 'flow' => 'investasi', 'description' => 'Link ke Asset (category: Mesin).'],
            ['code' => '1211', 'name' => 'Akumulasi Penyusutan — Peralatan & Mesin', 'type' => 'aset', 'parent' => '1200', 'flow' => 'investasi', 'description' => 'Kontra-aset (pengurang) — dari Asset::currentBookValue().'],
            ['code' => '1220', 'name' => 'Kendaraan Operasional', 'type' => 'aset', 'parent' => '1200', 'flow' => 'investasi', 'description' => 'Link ke Asset (category: Kendaraan).'],
            ['code' => '1221', 'name' => 'Akumulasi Penyusutan — Kendaraan', 'type' => 'aset', 'parent' => '1200', 'flow' => 'investasi'],
            ['code' => '1230', 'name' => 'Inventaris Toko & Kantor', 'type' => 'aset', 'parent' => '1200', 'flow' => 'investasi', 'description' => 'Furnitur, AC, elektronik — link ke Asset.'],
            ['code' => '1231', 'name' => 'Akumulasi Penyusutan — Inventaris', 'type' => 'aset', 'parent' => '1200', 'flow' => 'investasi'],
            ['code' => '1240', 'name' => 'Renovasi & Perbaikan Toko', 'type' => 'aset', 'parent' => '1200', 'flow' => 'investasi', 'description' => 'Leasehold improvement.'],
            ['code' => '1241', 'name' => 'Akumulasi Amortisasi — Renovasi', 'type' => 'aset', 'parent' => '1200', 'flow' => 'investasi'],

            ['code' => '1300', 'name' => 'Aset Lain-lain', 'type' => 'aset', 'postable' => false],
            ['code' => '1310', 'name' => 'Deposit / Jaminan Sewa', 'type' => 'aset', 'parent' => '1300', 'flow' => 'investasi'],
            ['code' => '1320', 'name' => 'Software & Lisensi', 'type' => 'aset', 'parent' => '1300', 'flow' => 'investasi'],

            // ─── 2000 KEWAJIBAN ─────────────────────────────────────
            ['code' => '2100', 'name' => 'Kewajiban Lancar', 'type' => 'kewajiban', 'postable' => false],
            ['code' => '2110', 'name' => 'Hutang Usaha', 'type' => 'kewajiban', 'parent' => '2100', 'flow' => 'operasional', 'description' => 'Tagihan supplier bahan baku, belum dibayar.'],
            ['code' => '2120', 'name' => 'Hutang Gaji', 'type' => 'kewajiban', 'parent' => '2100', 'flow' => 'operasional', 'description' => 'Payroll berstatus draft, belum dibayar.'],
            ['code' => '2130', 'name' => 'Hutang Pajak', 'type' => 'kewajiban', 'parent' => '2100', 'flow' => 'operasional', 'description' => 'PPh 21, PPh 23, PPN Keluaran.'],
            ['code' => '2140', 'name' => 'Pendapatan Diterima Dimuka', 'type' => 'kewajiban', 'parent' => '2100', 'flow' => 'operasional', 'description' => 'DP booking, instalasi belum selesai.'],
            ['code' => '2150', 'name' => 'Hutang Poin Loyalty', 'type' => 'kewajiban', 'parent' => '2100', 'flow' => 'operasional', 'description' => 'Saldo poin customer/partner beredar × estimasi nilai per poin.'],
            ['code' => '2160', 'name' => 'Hutang Voucher Belum Terpakai', 'type' => 'kewajiban', 'parent' => '2100', 'flow' => 'operasional', 'description' => 'VoucherClaim berstatus active.'],
            ['code' => '2170', 'name' => 'Hutang Komisi Partner', 'type' => 'kewajiban', 'parent' => '2100', 'flow' => 'operasional', 'description' => 'Komisi referral belum dibayarkan.'],

            ['code' => '2200', 'name' => 'Kewajiban Jangka Panjang', 'type' => 'kewajiban', 'postable' => false],
            ['code' => '2210', 'name' => 'Hutang Bank / Pinjaman', 'type' => 'kewajiban', 'parent' => '2200', 'flow' => 'pendanaan'],
            ['code' => '2220', 'name' => 'Hutang Leasing', 'type' => 'kewajiban', 'parent' => '2200', 'flow' => 'pendanaan', 'description' => 'Sewa-beli kendaraan/alat.'],

            // ─── 3000 MODAL ─────────────────────────────────────────
            ['code' => '3100', 'name' => 'Modal Disetor', 'type' => 'modal', 'flow' => 'pendanaan'],
            ['code' => '3200', 'name' => 'Laba Ditahan', 'type' => 'modal', 'flow' => 'pendanaan', 'description' => 'Akumulasi laba tahun-tahun sebelumnya.'],
            ['code' => '3300', 'name' => 'Prive', 'type' => 'modal', 'flow' => 'pendanaan', 'description' => 'Pengambilan pribadi pemilik.'],
            ['code' => '3900', 'name' => 'Laba/Rugi Tahun Berjalan', 'type' => 'modal', 'postable' => false, 'description' => 'Dihitung otomatis: 4000 − 5000 − 6000 ± 7000, bukan diposting manual.'],

            // ─── 4000 PENDAPATAN ────────────────────────────────────
            ['code' => '4100', 'name' => 'Pendapatan Jasa Instalasi PPF', 'type' => 'pendapatan', 'flow' => 'operasional'],
            ['code' => '4200', 'name' => 'Pendapatan Jasa Instalasi Kaca Film', 'type' => 'pendapatan', 'flow' => 'operasional'],
            ['code' => '4300', 'name' => 'Pendapatan Penjualan Produk', 'type' => 'pendapatan', 'flow' => 'operasional', 'description' => 'Jual lepas dari katalog Inventaris, di luar Booking (POS/Kasir — belum dibangun).'],
            ['code' => '4400', 'name' => 'Pendapatan Lain-lain', 'type' => 'pendapatan', 'flow' => 'operasional', 'description' => 'Klaim garansi berbayar, jasa tambahan.'],
            ['code' => '4900', 'name' => 'Retur & Potongan Penjualan', 'type' => 'pendapatan', 'flow' => 'operasional', 'description' => 'Kontra-pendapatan — diskon voucher yang dipakai.'],

            // ─── 5000 BEBAN POKOK PENJUALAN (HPP) ───────────────────
            ['code' => '5100', 'name' => 'Pemakaian Bahan Baku', 'type' => 'beban_pokok', 'flow' => 'operasional', 'description' => 'PPF, kaca film, adhesive — RawMaterialMovement tipe out.'],
            ['code' => '5200', 'name' => 'Pemakaian Barang Habis Pakai Langsung', 'type' => 'beban_pokok', 'flow' => 'operasional', 'description' => 'ConsumableItemMovement tipe out.'],
            ['code' => '5300', 'name' => 'Upah Langsung Teknisi', 'type' => 'beban_pokok', 'flow' => 'operasional', 'description' => 'Kalau ada skema komisi per-instalasi — belum ada skema ini di Payroll.'],

            // ─── 6000 BEBAN OPERASIONAL ─────────────────────────────
            ['code' => '6100', 'name' => 'Beban Karyawan', 'type' => 'beban_operasional', 'postable' => false],
            ['code' => '6110', 'name' => 'Beban Gaji Pokok', 'type' => 'beban_operasional', 'parent' => '6100', 'flow' => 'operasional', 'description' => 'Payroll.net_pay.'],
            ['code' => '6120', 'name' => 'Beban Potongan Telat/Alpha', 'type' => 'beban_operasional', 'parent' => '6100', 'flow' => 'operasional', 'description' => 'Untuk rekonsiliasi Payroll.total_deduction, bukan ditambah ke beban gaji.'],
            ['code' => '6130', 'name' => 'Beban Tunjangan & BPJS', 'type' => 'beban_operasional', 'parent' => '6100', 'flow' => 'operasional'],
            ['code' => '6140', 'name' => 'Beban Bonus/Insentif', 'type' => 'beban_operasional', 'parent' => '6100', 'flow' => 'operasional'],

            ['code' => '6200', 'name' => 'Beban Toko', 'type' => 'beban_operasional', 'postable' => false],
            ['code' => '6210', 'name' => 'Beban Sewa Toko', 'type' => 'beban_operasional', 'parent' => '6200', 'flow' => 'operasional'],
            ['code' => '6220', 'name' => 'Beban Listrik & Air', 'type' => 'beban_operasional', 'parent' => '6200', 'flow' => 'operasional'],
            ['code' => '6230', 'name' => 'Beban Internet & Telepon', 'type' => 'beban_operasional', 'parent' => '6200', 'flow' => 'operasional'],
            ['code' => '6240', 'name' => 'Beban Kebersihan & Keamanan', 'type' => 'beban_operasional', 'parent' => '6200', 'flow' => 'operasional'],

            ['code' => '6300', 'name' => 'Beban Pemasaran', 'type' => 'beban_operasional', 'postable' => false],
            ['code' => '6310', 'name' => 'Beban Iklan & Promosi', 'type' => 'beban_operasional', 'parent' => '6300', 'flow' => 'operasional'],
            ['code' => '6320', 'name' => 'Beban Komisi Partner/Referral', 'type' => 'beban_operasional', 'parent' => '6300', 'flow' => 'operasional'],
            ['code' => '6330', 'name' => 'Beban Reward & Loyalty', 'type' => 'beban_operasional', 'parent' => '6300', 'flow' => 'operasional', 'description' => 'Cost of goods reward yang ditukar (RewardRedemption).'],

            ['code' => '6400', 'name' => 'Beban Administrasi & Umum', 'type' => 'beban_operasional', 'postable' => false],
            ['code' => '6410', 'name' => 'Beban ATK & Perlengkapan Kantor', 'type' => 'beban_operasional', 'parent' => '6400', 'flow' => 'operasional'],
            ['code' => '6420', 'name' => 'Beban Penyusutan Aset Tetap', 'type' => 'beban_operasional', 'parent' => '6400', 'flow' => 'operasional'],
            ['code' => '6430', 'name' => 'Beban Pemeliharaan & Perbaikan Aset', 'type' => 'beban_operasional', 'parent' => '6400', 'flow' => 'operasional'],
            ['code' => '6440', 'name' => 'Beban Transportasi & Perjalanan Dinas', 'type' => 'beban_operasional', 'parent' => '6400', 'flow' => 'operasional'],
            ['code' => '6450', 'name' => 'Beban Asuransi', 'type' => 'beban_operasional', 'parent' => '6400', 'flow' => 'operasional'],
            ['code' => '6460', 'name' => 'Beban Legal & Perizinan', 'type' => 'beban_operasional', 'parent' => '6400', 'flow' => 'operasional'],
            ['code' => '6470', 'name' => 'Beban Sistem/Software', 'type' => 'beban_operasional', 'parent' => '6400', 'flow' => 'operasional', 'description' => 'Hosting, SaaS, API pihak ketiga.'],

            ['code' => '6500', 'name' => 'Beban Lain-lain', 'type' => 'beban_operasional', 'postable' => false],
            ['code' => '6510', 'name' => 'Beban Piutang Tak Tertagih', 'type' => 'beban_operasional', 'parent' => '6500', 'flow' => 'operasional'],

            // ─── 7000-8000 LAIN-LAIN & PAJAK ────────────────────────
            // 7100/7800 SENGAJA bukan 'operasional' — bunga dari
            // simpanan/pinjaman lazim diklasifikasi Investasi/Pendanaan
            // di laporan arus kas, beda dari 7200/7900 yang generik.
            ['code' => '7100', 'name' => 'Pendapatan Bunga Bank', 'type' => 'pendapatan_lain', 'flow' => 'investasi'],
            ['code' => '7200', 'name' => 'Pendapatan Non-Operasional Lain', 'type' => 'pendapatan_lain', 'flow' => 'operasional'],
            ['code' => '7800', 'name' => 'Beban Bunga Pinjaman', 'type' => 'beban_lain', 'flow' => 'pendanaan'],
            ['code' => '7900', 'name' => 'Beban Non-Operasional Lain', 'type' => 'beban_lain', 'flow' => 'operasional'],
            ['code' => '8100', 'name' => 'Beban Pajak Penghasilan Badan', 'type' => 'pajak', 'flow' => 'operasional', 'description' => 'Konsultasikan ke konsultan pajak sebelum dipakai.'],
        ];
    }
}
