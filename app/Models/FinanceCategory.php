<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FinanceCategory extends Model
{
    use LogsActivity;

    protected $fillable = [
        'code',
        'name',
        'description',
        'type',
        'is_group',
        'parent_id',
        'chart_of_account_id',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_group' => 'boolean',
    ];

    /**
     * Klasifikasi akun Bagan Akun yang boleh dipakai per tipe kategori
     * (in = pemasukan, out = pengeluaran). Satu tempat -- dipakai form
     * (dropdown) DAN validasi server.
     */
    public const ACCOUNT_TYPES = [
        'in' => ['pendapatan', 'pendapatan_lain'],
        'out' => ['beban_pokok', 'beban_operasional', 'beban_lain', 'pajak'],
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class);
    }

    /** Grup pembungkus (hierarki 1 tingkat) -- null untuk kategori tingkat atas. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * Akun Bagan Akun yang didebit/dikredit saat transaksi kategori ini
     * diposting otomatis ke Jurnal Umum — lihat
     * FinanceTransactionPostingService.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }

    /**
     * Dipakai buat menutup jalur hapus permanen kategori yang sudah pernah
     * dipakai transaksi — restrictOnDelete() di DB sudah menjaga level
     * database, ini dicek juga di Resource supaya errornya jadi pesan
     * Filament yang jelas, bukan SQL constraint mentah. Sama pola dengan
     * Partner::hasHistory().
     */
    public function hasTransactions(): bool
    {
        return $this->transactions()->exists();
    }

    /** Grup yang masih membungkus kategori anak. */
    public function hasChildren(): bool
    {
        return $this->exists && $this->children()->exists();
    }

    /**
     * Validasi SERVER akun yang dipilih (audit Kategori Keuangan
     * 2026-09-28): sebelumnya cuma difilter di dropdown UI, payload
     * manual bisa menyimpan akun non-postable/nonaktif/salah klasifikasi
     * dan baru ditolak belakangan saat posting jurnal.
     *
     * @return string|null pesan error, null kalau valid
     */
    public static function validateAccount(?int $accountId, ?string $type): ?string
    {
        if (! $accountId) {
            return null;
        }

        $account = ChartOfAccount::find($accountId);

        if (! $account || ! $account->is_active || ! $account->is_postable) {
            return 'Akun harus aktif dan bisa diposting.';
        }

        if (! in_array($account->type, self::ACCOUNT_TYPES[$type] ?? [], true)) {
            return $type === 'in'
                ? 'Kategori pemasukan harus memakai akun pendapatan.'
                : 'Kategori pengeluaran harus memakai akun beban atau pajak.';
        }

        return null;
    }

    /**
     * Guard integritas (audit Kategori Keuangan 2026-09-28): dilewati kalau
     * tidak ada user login (seeder/migrasi/tinker/job), sama pola dengan
     * ChartOfAccount.
     * - type & akun TIDAK boleh diubah setelah kategori dipakai transaksi:
     *   jurnal lama tetap menunjuk akun lama sementara laporan per-kategori
     *   pindah ke akun baru, dan mengubah type membalik transaksi lama saat
     *   diedit. Bikin kategori baru dan nonaktifkan yang lama.
     * - akun harus valid untuk type-nya; kategori BARU wajib punya akun.
     */
    protected static function booted(): void
    {
        // Menghapus grup yang masih punya anak melepas anak-anaknya dari pengelompokan secara diam-diam
        // (FK nullOnDelete): pindahkan/hapus dulu anaknya.
        static::deleting(function (FinanceCategory $category) {
            if (auth()->check() && $category->is_group && $category->hasChildren()) {
                throw new \RuntimeException('Grup ini masih punya kategori anak. Pindahkan atau hapus anak-anaknya dulu.');
            }
        });

        static::saving(function (FinanceCategory $category) {
            if (! auth()->check()) {
                return;
            }

            // Hierarki 1 tingkat (audit Kategori Keuangan 2026-09-28): induk
            // harus kategori GRUP, tipe sama, dan tidak punya induk sendiri;
            // grup tidak menerima transaksi & tidak punya akun.
            if ($category->parent_id) {
                $parent = self::find($category->parent_id);

                if (! $parent || ! $parent->is_group || (int) $parent->id === (int) $category->id) {
                    throw new \RuntimeException('Induk harus berupa kategori grup lain.');
                }
                if ($parent->type !== $category->type) {
                    throw new \RuntimeException('Induk harus bertipe sama (pemasukan/pengeluaran).');
                }
                if ($category->is_group) {
                    throw new \RuntimeException('Kategori grup tidak boleh punya induk (hierarki maksimal 1 tingkat).');
                }
            }

            if ($category->is_group) {
                // Tipe grup menentukan tipe anak-anaknya (anak wajib bertipe sama): tidak boleh berubah
                // selama masih ada anak, kalau tidak pemasukan & pengeluaran tercampur dalam satu grup.
                if ($category->exists && $category->isDirty('type') && $category->hasChildren()) {
                    throw new \RuntimeException('Grup ini masih punya kategori anak: tipenya tidak bisa diubah.');
                }
                if ($category->exists && $category->transactions()->exists()) {
                    throw new \RuntimeException('Kategori yang sudah dipakai transaksi tidak bisa dijadikan grup.');
                }
                if ($category->chart_of_account_id) {
                    throw new \RuntimeException('Kategori grup tidak memakai akun; akun dipasang di kategori anaknya.');
                }

                return; // grup tidak perlu validasi akun di bawah
            }

            if ($category->exists && $category->isDirty('is_group') && $category->children()->exists()) {
                throw new \RuntimeException('Grup yang masih punya kategori anak tidak bisa dijadikan kategori biasa.');
            }

            if ($category->exists && $category->isDirty(['type', 'chart_of_account_id']) && $category->hasTransactions()) {
                throw new \RuntimeException('Kategori ini sudah dipakai transaksi: tipe dan akunnya tidak boleh diubah. Buat kategori baru dan nonaktifkan yang lama.');
            }

            if (! $category->exists && ! $category->chart_of_account_id) {
                throw new \RuntimeException('Kategori baru wajib dihubungkan ke akun Bagan Akun.');
            }

            if (($category->isDirty(['type', 'chart_of_account_id']) || ! $category->exists) && ($error = static::validateAccount($category->chart_of_account_id, $category->type))) {
                throw new \RuntimeException($error);
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'type', 'chart_of_account_id', 'sort_order', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('finance_category')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Kategori keuangan \"{$this->name}\" dibuat",
                'updated' => "Kategori keuangan \"{$this->name}\" diubah",
                'deleted' => "Kategori keuangan \"{$this->name}\" dihapus",
                default => "Kategori keuangan \"{$this->name}\" — {$eventName}",
            });
    }
}
