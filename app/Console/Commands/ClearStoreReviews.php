<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\StoreReview;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hapus SEMUA data Review Toko sekaligus reset agregatnya — dibuat untuk
 * bersih-bersih data testing/live-QA, bukan dijadwalkan (tidak didaftarkan
 * di routes/console.php).
 *
 * WAJIB ikut reset stores.reviews_count/positive_reviews_count — dua
 * kolom itu cuma di-increment saat StoreReview::created (lihat
 * StoreReviewObserver::incrementStoreAggregate()), TIDAK ADA handler
 * `deleted` yang men-decrement. Kalau baris review dihapus manual/lewat
 * command tanpa ikut reset ini, angka reviews_count/positive_reviews_count
 * di tabel stores akan basi (tetap menghitung review yang sudah tidak
 * ada), rating toko yang tampil ke customer jadi salah.
 */
class ClearStoreReviews extends Command
{
    protected $signature = 'reviews:clear {--force : Lewati konfirmasi interaktif}';

    protected $description = 'Hapus SEMUA data Review Toko (store_reviews) dan reset agregat rating di tabel stores';

    public function handle(): int
    {
        $reviewCount = StoreReview::count();

        if ($reviewCount === 0) {
            $this->info('Tidak ada data Review Toko untuk dihapus.');
            return self::SUCCESS;
        }

        $storeCount = Store::query()
            ->where(fn ($q) => $q->where('reviews_count', '>', 0)->orWhere('positive_reviews_count', '>', 0))
            ->count();

        if (! $this->option('force')) {
            $confirmed = $this->confirm(
                "Ini akan menghapus PERMANEN {$reviewCount} baris Review Toko dan mereset agregat rating di {$storeCount} toko ke 0. Lanjutkan?",
                false
            );

            if (! $confirmed) {
                $this->warn('Dibatalkan, tidak ada data yang dihapus.');
                return self::FAILURE;
            }
        }

        DB::transaction(function () {
            StoreReview::query()->delete();
            Store::query()->update(['reviews_count' => 0, 'positive_reviews_count' => 0]);
        });

        $this->info("Selesai: {$reviewCount} Review Toko dihapus, agregat rating di {$storeCount} toko direset ke 0.");

        return self::SUCCESS;
    }
}
