<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Satu-satunya jalur resmi menulis Jurnal Umum — validasi aturan
 * double-entry (minimal 2 baris, tiap baris HANYA debit ATAU kredit,
 * total debit = total kredit, akun harus postable & aktif) SEMUANYA di
 * sini, supaya tidak mungkin ada jurnal tidak balance lolos ke DB lewat
 * jalur mana pun (Filament sekarang, integrasi otomatis Fase 3 nanti).
 *
 * Juga menegakkan Tutup Periode — assertPeriodOpen() dipanggil di
 * create()/update()/post()/reverse(), menolak jurnal apa pun dengan
 * tanggal yang jatuh di bulan yang sudah ditutup (lihat AccountingPeriod
 * & AccountingPeriodService).
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
        $this->assertPeriodOpen($header['entry_date']);
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

    /** Batas nominal per baris (decimal(14,2) di database) & jumlah baris per jurnal. */
    public const MAX_AMOUNT = 99_999_999_999.99;

    public const MAX_LINES = 200;

    /**
     * Jurnal draft boleh diedit BEBAS (ganti header & susun ulang
     * baris) — baris lama dihapus total lalu diganti baris baru,
     * lebih sederhana & tidak rawan bug dibanding diff baris satu-satu,
     * dan aman karena draft belum pernah dipakai laporan apa pun.
     *
     * Audit Jurnal Umum 2026-09-29: cek status dilakukan pada baris yang
     * DIKUNCI (lockForUpdate) di dalam transaksi -- sebelumnya memakai objek
     * basi dari Livewire, jadi kalau user lain memposting jurnal ini di antara
     * halaman dibuka dan Simpan ditekan, baris jurnal yang sudah POSTED
     * tertimpa diam-diam. Baris lama dihapus per-model (bukan mass delete)
     * supaya nilai lamanya tercatat di activity log.
     *
     * @throws RuntimeException kalau jurnal ini sudah 'posted' (terkunci).
     */
    public function update(JournalEntry $entry, array $header, array $lines): JournalEntry
    {
        return DB::transaction(function () use ($entry, $header, $lines) {
            $locked = JournalEntry::withoutGlobalScopes()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new RuntimeException('Jurnal yang sudah diposting terkunci — tidak bisa diedit langsung. Buat jurnal pembalik kalau perlu koreksi.');
            }

            // Tanggal LAMA dan BARU keduanya harus di periode terbuka.
            $this->assertPeriodOpen($locked->entry_date->toDateString());
            $this->assertPeriodOpen($header['entry_date']);
            $lines = $this->validateLines($lines);

            $locked->update($header);
            $locked->lines()->get()->each->delete();

            foreach ($lines as $line) {
                $locked->lines()->create($line);
            }

            return $locked->refresh();
        });
    }

    /**
     * Draft → Posted. TERKUNCI setelah ini. Semua pengecekan (status, periode,
     * balance, akun) dilakukan di dalam transaksi pada baris yang DIKUNCI --
     * dua user yang memposting bersamaan tidak lagi sama-sama lolos dan
     * menimpa posted_by/posted_at (audit Jurnal Umum 2026-09-29). Balance
     * dihitung ulang dari baris di database (dalam sen, bukan float), dan akun
     * dicek ulang (bisa saja dinonaktifkan sejak draft dibuat).
     *
     * @throws RuntimeException kalau bukan draft, tidak balance, periode tertutup, atau akun tidak valid.
     */
    public function post(JournalEntry $entry, ?int $userId): JournalEntry
    {
        return DB::transaction(function () use ($entry, $userId) {
            $locked = JournalEntry::withoutGlobalScopes()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new RuntimeException('Cuma jurnal berstatus Draft yang bisa diposting.');
            }

            // Draft-nya sendiri bisa saja dibuat SEBELUM periode ditutup —
            // dicek ULANG di sini (bukan cuma saat create()) supaya draft
            // lama yang tanggalnya jatuh di periode yang belakangan ditutup
            // tidak bisa diam-diam lolos diposting setelahnya.
            $this->assertPeriodOpen($locked->entry_date->toDateString());

            $rows = $locked->lines()->get(['chart_of_account_id', 'debit', 'credit']);

            if ($rows->count() < 2) {
                throw new RuntimeException('Jurnal butuh minimal 2 baris sebelum diposting.');
            }

            $debitCents = $rows->sum(fn ($l) => $this->toCents($l->debit));
            $creditCents = $rows->sum(fn ($l) => $this->toCents($l->credit));

            if ($debitCents !== $creditCents) {
                throw new RuntimeException('Jurnal ini tidak balance — total debit dan kredit harus sama sebelum diposting.');
            }

            $this->assertAccountsUsable($rows->pluck('chart_of_account_id')->all());

            $locked->update([
                'status' => 'posted',
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Koreksi jurnal yang SUDAH posted — BUKAN edit/hapus langsung
     * (integritas riwayat pembukuan), tapi bikin jurnal BARU dengan
     * debit/kredit dibalik dari aslinya, langsung berstatus posted juga
     * (jurnal pembalik itu sendiri adalah fakta keuangan yang sah,
     * tidak perlu direview lagi sebagai draft).
     *
     * Audit Jurnal Umum 2026-09-29: baris asal DIKUNCI dan pengecekan
     * "sudah dibalik?" dilakukan di dalam transaksi -- dua user yang menekan
     * "Balik Jurnal" bersamaan tidak lagi menghasilkan dua pembalik (saldo
     * akun terbalik dari aslinya). Jurnal pembalik sendiri tidak boleh dibalik
     * lagi (hasilnya "jurnal asli versi kedua" yang membingungkan).
     *
     * @throws RuntimeException kalau jurnal aslinya belum posted, sudah pernah dibalik, atau sendiri adalah pembalik.
     */
    public function reverse(JournalEntry $entry, ?int $userId, ?string $note = null, ?string $date = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $userId, $note, $date) {
            $locked = JournalEntry::withoutGlobalScopes()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPosted()) {
                throw new RuntimeException('Cuma jurnal yang sudah diposting yang bisa dibalik.');
            }

            if ($locked->reference_type === 'reversal') {
                throw new RuntimeException('Jurnal pembalik tidak bisa dibalik lagi. Kalau pembaliknya keliru, buat jurnal koreksi baru.');
            }

            if ($locked->reversal()->exists()) {
                throw new RuntimeException('Jurnal ini sudah pernah dibalik sebelumnya.');
            }

            // Tanggal pembalik: default HARI INI (perilaku modul-modul otomatis
            // tidak berubah). Dari UI Jurnal Umum tanggal boleh dipilih (audit
            // 2026-09-29) -- tidak boleh sebelum tanggal jurnal asli, tidak boleh
            // di masa depan, dan periodenya harus terbuka.
            $reversalDate = $date ? \Illuminate\Support\Carbon::parse($date)->toDateString() : now()->toDateString();

            if ($reversalDate < $locked->entry_date->toDateString()) {
                throw new RuntimeException('Tanggal pembalik tidak boleh sebelum tanggal jurnal asli (' . $locked->entry_date->format('d M Y') . ').');
            }

            if ($reversalDate > now()->toDateString()) {
                throw new RuntimeException('Tanggal pembalik tidak boleh di masa depan.');
            }

            $this->assertPeriodOpen($reversalDate);

            $description = "Pembalik jurnal {$locked->entry_number}" . ($note ? " — {$note}" : '');

            $reversal = JournalEntry::create([
                'entry_number' => self::generateEntryNumber(),
                'entry_date' => $reversalDate,
                'store_id' => $locked->store_id,
                'description' => $description,
                'reference_type' => 'reversal',
                'reference_id' => $locked->id,
                'status' => 'posted',
                'created_by' => $userId,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            foreach ($locked->lines as $line) {
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

            // Mutasi bank yang tadinya dicocokkan ke jurnal ini (Rekonsiliasi Bank) ditandai perlu
            // ditinjau ulang -- efeknya sudah dibalik, tapi status 'matched' tidak diubah diam-diam.
            app(BankReconciliationService::class)->invalidateForReversal($locked->id);

            return $reversal;
        });
    }

    /** Nominal decimal -> sen (integer), supaya perbandingan balance bebas dari error float. */
    private function toCents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * @throws RuntimeException kalau ada akun yang tidak ada, header, atau nonaktif.
     */
    private function assertAccountsUsable(array $accountIds): void
    {
        $ids = array_values(array_unique($accountIds));
        $accounts = ChartOfAccount::whereIn('id', $ids)->get(['id', 'name', 'is_postable', 'is_active']);

        // Akun yang tidak ada di database -> pesan jelas (bukan QueryException/500).
        if ($accounts->count() !== count($ids)) {
            throw new RuntimeException('Ada akun yang tidak ditemukan di Bagan Akun.');
        }

        $invalid = $accounts->filter(fn ($a) => ! $a->is_postable || ! $a->is_active)->pluck('name');

        if ($invalid->isNotEmpty()) {
            throw new RuntimeException('Akun berikut tidak bisa dipakai jurnal (header/nonaktif): ' . $invalid->implode(', '));
        }
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

        if (count($lines) > self::MAX_LINES) {
            throw new RuntimeException('Jurnal maksimal ' . self::MAX_LINES . ' baris. Pecah menjadi beberapa jurnal.');
        }

        $totalDebitCents = 0;
        $totalCreditCents = 0;
        $accountIds = [];
        $normalized = [];

        foreach ($lines as $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if ($debit < 0 || $credit < 0) {
                throw new RuntimeException('Nominal tidak boleh negatif.');
            }

            if ($debit > self::MAX_AMOUNT || $credit > self::MAX_AMOUNT) {
                throw new RuntimeException('Nominal per baris maksimal Rp' . number_format(self::MAX_AMOUNT, 0, ',', '.') . '.');
            }

            if ($debit > 0 && $credit > 0) {
                throw new RuntimeException('Satu baris tidak boleh diisi debit dan kredit sekaligus — pilih salah satu.');
            }

            if ($debit <= 0 && $credit <= 0) {
                throw new RuntimeException('Setiap baris harus diisi nominal debit ATAU kredit (tidak boleh dua-duanya kosong).');
            }

            // Dijumlah dalam SEN (integer) -- perbandingan balance bebas dari
            // error float (selisih 0,01 tidak lagi lolos/tampil "Rp 0").
            $totalDebitCents += $this->toCents($debit);
            $totalCreditCents += $this->toCents($credit);
            $accountIds[] = $line['chart_of_account_id'];

            $normalized[] = [
                'chart_of_account_id' => $line['chart_of_account_id'],
                'debit' => $debit,
                'credit' => $credit,
                'description' => $line['description'] ?? null,
            ];
        }

        if ($totalDebitCents !== $totalCreditCents) {
            $selisih = number_format(abs($totalDebitCents - $totalCreditCents) / 100, 2, ',', '.');
            throw new RuntimeException("Jurnal tidak balance — total debit dan kredit berselisih Rp {$selisih}.");
        }

        $this->assertAccountsUsable($accountIds);

        return $normalized;
    }

    /**
     * @throws RuntimeException kalau periode bulan dari $date sudah ditutup (lihat AccountingPeriod).
     */
    private function assertPeriodOpen(string $date): void
    {
        $parsed = Carbon::parse($date);

        if (AccountingPeriod::isClosedFor($parsed)) {
            throw new RuntimeException('Periode ' . $parsed->translatedFormat('F Y') . ' sudah ditutup — tidak bisa membuat, mengubah, atau memposting jurnal dengan tanggal di bulan ini. Buka kembali periodenya dulu lewat menu Tutup Periode kalau memang perlu.');
        }
    }

    /**
     * Nomor berurutan per bulan: JE-YYYYMM-0001, 0002, ... (keputusan audit
     * Jurnal Umum 2026-09-29 -- auditor lebih mudah mendeteksi nomor yang hilang).
     * Nomor acak lama (JE-YYYYMM-AB3F) tetap valid dan diabaikan saat menghitung
     * urutan. Kalau dua proses berebut nomor yang sama, unique index
     * entry_number menolak yang kedua (gagal bersih, bukan duplikat) -- ulangi simpan.
     */
    public static function generateEntryNumber(): string
    {
        $prefix = 'JE-' . now()->format('Ym') . '-';

        $last = JournalEntry::withoutGlobalScopes()
            ->where('entry_number', 'like', $prefix . '%')
            ->pluck('entry_number')
            ->map(fn (string $n) => substr($n, strlen($prefix)))
            ->filter(fn (string $suffix) => ctype_digit($suffix))
            ->map(fn (string $suffix) => (int) $suffix)
            ->max() ?? 0;

        do {
            $candidate = $prefix . str_pad((string) (++$last), 4, '0', STR_PAD_LEFT);
        } while (JournalEntry::withoutGlobalScopes()->where('entry_number', $candidate)->exists());

        return $candidate;
    }
}
