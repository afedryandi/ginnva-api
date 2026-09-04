<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Satu-satunya jalur resmi menulis Jurnal Umum — validasi aturan
 * double-entry (minimal 2 baris, tiap baris HANYA debit ATAU kredit,
 * total debit = total kredit, akun harus postable & aktif) SEMUANYA di
 * sini, supaya tidak mungkin ada jurnal tidak balance lolos ke DB lewat
 * jalur mana pun (Filament sekarang, integrasi otomatis Fase 3 nanti).
 */
class JournalEntryService
{
    /**
     * @param array{entry_date: string, store_id: ?int, description: string, reference_type?: ?string, reference_id?: ?int, created_by?: ?int} $header
     * @param array<int, array{chart_of_account_id: int, debit?: float|null, credit?: float|null, description?: ?string}> $lines
     *
     * @throws RuntimeException kalau baris kurang dari 2, ada baris yang
     *         isi debit & kredit sekaligus (atau kosong dua-duanya),
     *         total debit ≠ total kredit, atau ada akun yang tidak
     *         postable/tidak aktif.
     */
    public function create(array $header, array $lines): JournalEntry
    {
        $lines = $this->validateLines($lines);

        return DB::transaction(function () use ($header, $lines) {
            $entry = JournalEntry::create($header + [
                'entry_number' => self::generateEntryNumber(),
                'status' => 'draft',
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create($line);
            }

            return $entry;
        });
    }

    /**
     * Jurnal draft boleh diedit BEBAS (ganti header & susun ulang
     * baris) — baris lama dihapus total lalu diganti baris baru,
     * lebih sederhana & tidak rawan bug dibanding diff baris satu-satu,
     * dan aman karena draft belum pernah dipakai laporan apa pun.
     *
     * @throws RuntimeException kalau jurnal ini sudah 'posted' (terkunci).
     */
    public function update(JournalEntry $entry, array $header, array $lines): JournalEntry
    {
        if (! $entry->isDraft()) {
            throw new RuntimeException('Jurnal yang sudah diposting terkunci — tidak bisa diedit langsung. Buat jurnal pembalik kalau perlu koreksi.');
        }

        $lines = $this->validateLines($lines);

        return DB::transaction(function () use ($entry, $header, $lines) {
            $entry->update($header);
            $entry->lines()->delete();

            foreach ($lines as $line) {
                $entry->lines()->create($line);
            }

            return $entry->refresh();
        });
    }

    /**
     * Draft → Posted. TERKUNCI setelah ini — divalidasi ulang balance-nya
     * (jaring pengaman kalau baris sempat berubah di luar dugaan sejak
     * dibuat), bukan cuma percaya status draft-nya sudah pernah valid.
     *
     * @throws RuntimeException kalau bukan draft, atau ternyata tidak balance.
     */
    public function post(JournalEntry $entry, ?int $userId): JournalEntry
    {
        if (! $entry->isDraft()) {
            throw new RuntimeException('Cuma jurnal berstatus Draft yang bisa diposting.');
        }

        if (! $entry->isBalanced()) {
            throw new RuntimeException('Jurnal ini tidak balance — total debit dan kredit harus sama sebelum diposting.');
        }

        $entry->update([
            'status' => 'posted',
            'posted_by' => $userId,
            'posted_at' => now(),
        ]);

        return $entry->refresh();
    }

    /**
     * Koreksi jurnal yang SUDAH posted — BUKAN edit/hapus langsung
     * (integritas riwayat pembukuan), tapi bikin jurnal BARU dengan
     * debit/kredit dibalik dari aslinya, langsung berstatus posted juga
     * (jurnal pembalik itu sendiri adalah fakta keuangan yang sah,
     * tidak perlu direview lagi sebagai draft).
     *
     * @throws RuntimeException kalau jurnal aslinya belum posted, atau sudah pernah dibalik sebelumnya.
     */
    public function reverse(JournalEntry $entry, ?int $userId, ?string $note = null): JournalEntry
    {
        if (! $entry->isPosted()) {
            throw new RuntimeException('Cuma jurnal yang sudah diposting yang bisa dibalik.');
        }

        if ($entry->reversal()->exists()) {
            throw new RuntimeException('Jurnal ini sudah pernah dibalik sebelumnya.');
        }

        return DB::transaction(function () use ($entry, $userId, $note) {
            $description = "Pembalik jurnal {$entry->entry_number}" . ($note ? " — {$note}" : '');

            $reversal = JournalEntry::create([
                'entry_number' => self::generateEntryNumber(),
                'entry_date' => now()->toDateString(),
                'store_id' => $entry->store_id,
                'description' => $description,
                'reference_type' => 'reversal',
                'reference_id' => $entry->id,
                'status' => 'posted',
                'created_by' => $userId,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            foreach ($entry->lines as $line) {
                $reversal->lines()->create([
                    'chart_of_account_id' => $line->chart_of_account_id,
                    // Dibalik persis — sisi debit jadi kredit & sebaliknya,
                    // supaya efek bersih ke saldo akun jadi nol seolah-olah
                    // jurnal aslinya tidak pernah terjadi, TAPI keduanya
                    // tetap tersimpan permanen sebagai jejak audit.
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'description' => $line->description,
                ]);
            }

            return $reversal;
        });
    }

    /**
     * @param array<int, array{chart_of_account_id: int, debit?: float|null, credit?: float|null, description?: ?string}> $lines
     * @return array<int, array{chart_of_account_id: int, debit: float, credit: float, description: ?string}>
     */
    private function validateLines(array $lines): array
    {
        $lines = array_values(array_filter($lines, fn ($l) => ! empty($l['chart_of_account_id'])));

        if (count($lines) < 2) {
            throw new RuntimeException('Jurnal butuh minimal 2 baris (sisi debit dan sisi kredit).');
        }

        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $accountIds = [];
        $normalized = [];

        foreach ($lines as $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if ($debit > 0 && $credit > 0) {
                throw new RuntimeException('Satu baris tidak boleh diisi debit dan kredit sekaligus — pilih salah satu.');
            }

            if ($debit <= 0 && $credit <= 0) {
                throw new RuntimeException('Setiap baris harus diisi nominal debit ATAU kredit (tidak boleh dua-duanya kosong).');
            }

            $totalDebit += $debit;
            $totalCredit += $credit;
            $accountIds[] = $line['chart_of_account_id'];

            $normalized[] = [
                'chart_of_account_id' => $line['chart_of_account_id'],
                'debit' => $debit,
                'credit' => $credit,
                'description' => $line['description'] ?? null,
            ];
        }

        if (round($totalDebit, 2) !== round($totalCredit, 2)) {
            $selisih = number_format(abs($totalDebit - $totalCredit), 0, ',', '.');
            throw new RuntimeException("Jurnal tidak balance — total debit dan kredit berselisih Rp {$selisih}.");
        }

        $invalidAccounts = ChartOfAccount::whereIn('id', array_unique($accountIds))
            ->where(fn ($q) => $q->where('is_postable', false)->orWhere('is_active', false))
            ->pluck('name');

        if ($invalidAccounts->isNotEmpty()) {
            throw new RuntimeException('Akun berikut tidak bisa dipakai jurnal (header/nonaktif): ' . $invalidAccounts->implode(', '));
        }

        return $normalized;
    }

    public static function generateEntryNumber(): string
    {
        do {
            $candidate = 'JE-' . now()->format('Ym') . '-' . Str::upper(Str::random(4));
        } while (JournalEntry::where('entry_number', $candidate)->exists());

        return $candidate;
    }
}
