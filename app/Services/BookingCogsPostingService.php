<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HPP (beban pokok) per booking dijurnal otomatis (Keuangan Fase 2, 2026-11-06). Dihitung dari pemakaian bahan yang sudah tercatat
 * (film dari Kode Gulungan, bahan baku dan barang habis pakai dari Memo Barang) lewat BookingCogsService:
 *
 *   Debit 5100 Pemakaian Bahan Baku        (film + bahan baku)
 *   Debit 5200 Pemakaian Barang Habis Pakai
 *     Kredit 1132 Persediaan Produk PPF/Kaca Film     (film)
 *     Kredit 1130 Persediaan Bahan Baku               (bahan baku)
 *     Kredit 1131 Persediaan Barang Habis Pakai       (barang habis pakai)
 *
 * Satu jurnal aktif per booking (reference_type "booking_cogs"). sync() aman dipanggil berulang: bila angkanya sama tidak melakukan apa pun,
 * bila berbeda jurnal lama dibalik lalu dibuat yang baru. Hanya booking yang pendapatannya sudah dijurnal yang diberi HPP. DIMATIKAN secara
 * bawaan (config features.inventory_gl): nyalakan setelah saldo awal persediaan disinkronkan (Sinkronkan Persediaan), kalau tidak akun
 * persediaan menjadi negatif. Nilai memakai harga modal terkini bahan, bukan FIFO per batch, jadi selisih kecil ke sub-buku wajar dan
 * dikoreksi lewat Sinkronkan Persediaan atau Stok Opname.
 */
class BookingCogsPostingService
{
    public const REF_TYPE = 'booking_cogs';

    public static function enabled(): bool
    {
        return (bool) config('features.finance') && (bool) config('features.inventory_gl', false);
    }

    /** Jurnal HPP aktif (diposting dan belum dibalik) milik booking ini. */
    public function activeEntry(int $bookingId): ?JournalEntry
    {
        return JournalEntry::withoutGlobalScopes()
            ->where('reference_type', self::REF_TYPE)
            ->where('reference_id', $bookingId)
            ->where('status', 'posted')
            ->whereDoesntHave('reversal')
            ->latest('id')
            ->first();
    }

    public function sync(Booking $booking): ?JournalEntry
    {
        return DB::transaction(function () use ($booking) {
            $locked = Booking::query()->where('id', $booking->id)->lockForUpdate()->firstOrFail();
            $existing = $this->activeEntry($locked->id);

            $parts = null;

            if ($locked->journal_entry_id) {
                $result = app(BookingCogsService::class)->forBookings([$locked->id])[$locked->id] ?? null;
                $parts = $result ? array_map(fn ($v) => round((float) $v, 2), $result['parts']) : null;
                $missing = (bool) ($result['hasMissingCost'] ?? false);
            }

            $total = $parts ? array_sum($parts) : 0.0;

            if ($total < 0.005) {
                $this->reverse($existing, 'HPP booking dihitung ulang: tidak ada pemakaian bahan');

                return null;
            }

            if ($existing && $this->sameAmounts($existing, $parts)) {
                return $existing;
            }

            $this->reverse($existing, 'HPP booking dihitung ulang');

            return $this->post($locked, $parts, $missing ?? false);
        });
    }

    /** @param array{film: float, raw: float, consumable: float} $parts */
    private function post(Booking $booking, array $parts, bool $missing): JournalEntry
    {
        $account = fn (string $code) => ChartOfAccount::where('code', $code)->first()
            ?? throw new RuntimeException("Akun {$code} tidak ditemukan di Bagan Akun.");

        $lines = [];
        $cogsMaterial = round($parts['film'] + $parts['raw'], 2);

        if ($cogsMaterial > 0) {
            $lines[] = ['chart_of_account_id' => $account('5100')->id, 'debit' => $cogsMaterial];
        }
        if ($parts['consumable'] > 0) {
            $lines[] = ['chart_of_account_id' => $account('5200')->id, 'debit' => $parts['consumable']];
        }
        if ($parts['film'] > 0) {
            $lines[] = ['chart_of_account_id' => $account('1132')->id, 'credit' => $parts['film']];
        }
        if ($parts['raw'] > 0) {
            $lines[] = ['chart_of_account_id' => $account('1130')->id, 'credit' => $parts['raw']];
        }
        if ($parts['consumable'] > 0) {
            $lines[] = ['chart_of_account_id' => $account('1131')->id, 'credit' => $parts['consumable']];
        }

        $service = app(JournalEntryService::class);
        $date = $this->entryDate($booking);

        $entry = $service->create([
            'entry_date' => $date,
            'store_id' => $booking->store_id,
            'description' => "HPP booking {$booking->booking_number}" . ($missing ? ' (perkiraan: ada bahan tanpa harga modal)' : ''),
            'reference_type' => self::REF_TYPE,
            'reference_id' => $booking->id,
            'created_by' => auth()->id(),
        ], $lines);

        return $service->post($entry, auth()->id());
    }

    /** Tanggal jurnal pendapatan booking bila periodenya masih terbuka, jika tidak hari ini. */
    private function entryDate(Booking $booking): string
    {
        $revenue = $booking->journal_entry_id ? JournalEntry::withoutGlobalScopes()->find($booking->journal_entry_id) : null;
        $date = $revenue?->entry_date;

        if ($date && ! \App\Models\AccountingPeriod::isClosedFor($date)) {
            return $date->toDateString();
        }

        return now()->toDateString();
    }

    private function reverse(?JournalEntry $entry, string $note): void
    {
        if ($entry) {
            app(JournalEntryService::class)->reverse($entry, auth()->id(), $note);
        }
    }

    /** @param array{film: float, raw: float, consumable: float} $parts */
    private function sameAmounts(JournalEntry $entry, array $parts): bool
    {
        $byCode = $entry->lines()->with('account:id,code')->get()
            ->groupBy(fn ($l) => $l->account->code)
            ->map(fn ($rows) => round((float) $rows->sum('debit') + (float) $rows->sum('credit'), 2));

        return abs($byCode->get('1132', 0.0) - $parts['film']) < 0.005
            && abs($byCode->get('1130', 0.0) - $parts['raw']) < 0.005
            && abs($byCode->get('1131', 0.0) - $parts['consumable']) < 0.005;
    }
}
