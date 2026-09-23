<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Payroll;
use RuntimeException;

/**
 * Auto-posting Penggajian → Jurnal Umum, dipicu saat Payroll ditandai
 * "Dibayar" (bukan saat digenerate — payroll 'draft' bisa direvisi/
 * dihapus, belum jadi fakta keuangan sampai benar-benar dibayar).
 *
 * Pola jurnal (baris komisi ditambahkan 2026-09-23, integrasi Komisi
 * Teknisi → Payroll):
 * - TANPA potongan & TANPA komisi (kasus lama): 2 baris —
 *     Debit 6110 Beban Gaji Pokok = net_pay
 *     Kredit 1101 Kas = net_pay
 * - DENGAN potongan dan/atau komisi: gaji POKOK (gross, sebelum
 *   potongan), komisi, dan potongannya ditampilkan TERPISAH per baris —
 *   bukan langsung dinetkan jadi net_pay di 1 baris — supaya laporan
 *   per-akun tetap bisa lihat "berapa total gaji kotor" vs "berapa
 *   komisi" vs "berapa yang dipotong karena telat/alpha" secara
 *   terpisah, konsisten dengan deskripsi akun 6120 di ChartOfAccountSeeder:
 *     Debit 6110 Beban Gaji Pokok = prorated_base_salary (gross)
 *     Debit 5300 Upah Langsung Teknisi = total_commission (kalau > 0)
 *     Kredit 6120 Beban Potongan Telat/Alpha = total_deduction (kalau > 0)
 *     Kredit 1101 Kas = net_pay
 *   (total debit = prorated_base_salary + total_commission = total
 *   kredit = total_deduction + net_pay, balance terjaga)
 */
class PayrollPostingService
{
    private const CASH_ACCOUNT_CODE = '1101';
    private const GAJI_POKOK_ACCOUNT_CODE = '6110';
    private const POTONGAN_ACCOUNT_CODE = '6120';
    private const KOMISI_TEKNISI_ACCOUNT_CODE = '5300';

    /**
     * @throws RuntimeException kalau payroll ini sudah pernah diposting
     *         sebelumnya, akun yang dibutuhkan tidak ditemukan di Bagan
     *         Akun, atau periode tanggal posting sudah ditutup
     *         (diteruskan dari JournalEntryService).
     */
    public function post(Payroll $payroll): JournalEntry
    {
        if ($payroll->journal_entry_id) {
            throw new RuntimeException('Payroll ini sudah pernah diposting ke Jurnal Umum sebelumnya.');
        }

        $cash = ChartOfAccount::where('code', self::CASH_ACCOUNT_CODE)->first();
        $gajiPokok = ChartOfAccount::where('code', self::GAJI_POKOK_ACCOUNT_CODE)->first();
        $potongan = ChartOfAccount::where('code', self::POTONGAN_ACCOUNT_CODE)->first();
        $komisiTeknisi = ChartOfAccount::where('code', self::KOMISI_TEKNISI_ACCOUNT_CODE)->first();

        $deduction = (float) $payroll->total_deduction;
        $commission = (float) $payroll->total_commission;

        if (! $cash || ! $gajiPokok || ($deduction > 0 && ! $potongan) || ($commission > 0 && ! $komisiTeknisi)) {
            throw new RuntimeException('Akun Bagan Akun yang dibutuhkan (Kas/Beban Gaji Pokok/Beban Potongan/Upah Langsung Teknisi) tidak ditemukan — periksa menu Bagan Akun.');
        }

        $netPay = (float) $payroll->net_pay;

        if ($deduction > 0 || $commission > 0) {
            $lines = [
                ['chart_of_account_id' => $gajiPokok->id, 'debit' => (float) $payroll->prorated_base_salary],
            ];

            if ($commission > 0) {
                $lines[] = ['chart_of_account_id' => $komisiTeknisi->id, 'debit' => $commission];
            }

            if ($deduction > 0) {
                $lines[] = ['chart_of_account_id' => $potongan->id, 'credit' => $deduction];
            }

            $lines[] = ['chart_of_account_id' => $cash->id, 'credit' => $netPay];
        } else {
            $lines = [
                ['chart_of_account_id' => $gajiPokok->id, 'debit' => $netPay],
                ['chart_of_account_id' => $cash->id, 'credit' => $netPay],
            ];
        }

        $periodLabel = $payroll->period_month->translatedFormat('F Y');
        $description = "Gaji {$payroll->user?->name} periode {$periodLabel}";

        $service = app(JournalEntryService::class);

        $entry = $service->create([
            'entry_date' => now()->toDateString(),
            'store_id' => $payroll->store_id,
            'description' => $description,
            'reference_type' => 'payroll',
            'reference_id' => $payroll->id,
            'created_by' => $payroll->paid_by,
        ], $lines);

        return $service->post($entry, $payroll->paid_by);
    }
}
