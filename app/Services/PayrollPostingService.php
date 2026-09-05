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
 * 2 pola jurnal tergantung ada tidaknya potongan:
 * - TANPA potongan (total_deduction = 0): 2 baris —
 *     Debit 6110 Beban Gaji Pokok = net_pay
 *     Kredit 1101 Kas = net_pay
 * - DENGAN potongan: 3 baris — gaji POKOK (gross, sebelum potongan)
 *   dan potongannya ditampilkan TERPISAH sebagai 2 baris kredit,
 *   bukan langsung dinetkan jadi net_pay di 1 baris — supaya laporan
 *   per-akun tetap bisa lihat "berapa total gaji kotor" vs "berapa
 *   yang dipotong karena telat/alpha" secara terpisah, konsisten
 *   dengan deskripsi akun 6120 di ChartOfAccountSeeder:
 *     Debit 6110 Beban Gaji Pokok = prorated_base_salary (gross)
 *     Kredit 6120 Beban Potongan Telat/Alpha = total_deduction
 *     Kredit 1101 Kas = net_pay
 *   (6110 − 6120 = net_pay, balance terjaga: total debit = total kredit
 *   = prorated_base_salary)
 */
class PayrollPostingService
{
    private const CASH_ACCOUNT_CODE = '1101';
    private const GAJI_POKOK_ACCOUNT_CODE = '6110';
    private const POTONGAN_ACCOUNT_CODE = '6120';

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

        if (! $cash || ! $gajiPokok || ($payroll->total_deduction > 0 && ! $potongan)) {
            throw new RuntimeException('Akun Bagan Akun yang dibutuhkan (Kas/Beban Gaji Pokok/Beban Potongan) tidak ditemukan — periksa menu Bagan Akun.');
        }

        $netPay = (float) $payroll->net_pay;
        $deduction = (float) $payroll->total_deduction;

        if ($deduction > 0) {
            $lines = [
                ['chart_of_account_id' => $gajiPokok->id, 'debit' => (float) $payroll->prorated_base_salary],
                ['chart_of_account_id' => $potongan->id, 'credit' => $deduction],
                ['chart_of_account_id' => $cash->id, 'credit' => $netPay],
            ];
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
