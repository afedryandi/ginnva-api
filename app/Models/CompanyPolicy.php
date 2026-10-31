<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Dokumen Peraturan Perusahaan, kebijakan, dan SOP beserta bukti tiap karyawan menerimanya (Karyawan Fase 3, 2026-10-31). Potongan telat
 * dan Alpha hanya sah bila tertulis di Peraturan Perusahaan yang sudah diterima karyawan, jadi sistem menyimpan dokumennya dan siapa yang
 * sudah menerima. Berkas disimpan di disk privat. Dokumen baru versi berikutnya dibuat sebagai baris baru; versi lama tinggal dicabut
 * dari terbit supaya bukti penerimaan versi lama tetap utuh.
 */
class CompanyPolicy extends Model
{
    use LogsActivity;

    public const CATEGORIES = [
        'peraturan_perusahaan' => 'Peraturan Perusahaan',
        'kebijakan' => 'Kebijakan',
        'sop' => 'SOP',
    ];

    protected $fillable = [
        'title', 'category', 'version', 'effective_from', 'description', 'file_path', 'original_filename',
        'requires_acknowledgment', 'is_published', 'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'requires_acknowledgment' => 'boolean',
        'is_published' => 'boolean',
    ];

    /** Kolom yang membentuk isi dokumen dan syarat penerimaannya; terkunci begitu ada karyawan yang menerima. */
    public const LOCKED_AFTER_ACKNOWLEDGMENT = [
        'title', 'category', 'version', 'effective_from', 'description', 'file_path', 'original_filename', 'requires_acknowledgment',
    ];

    /**
     * Bukti penerimaan harus tetap merujuk dokumen yang sama persis dengan yang diterima karyawan (audit Peraturan & Kebijakan, 2026-10-31).
     * Penguncian di form saja tidak cukup: jalur lain (tinker, perintah, aksi baru) bisa mengubah isi dokumen atau menghapusnya, dan
     * penghapusan ikut menghapus berantai seluruh bukti penerimaannya. Mencabut terbit tetap boleh (itu cara mengganti versi).
     */
    protected static function booted(): void
    {
        static::updating(function (CompanyPolicy $policy) {
            $normalize = fn ($v) => ($v === '' || $v === null) ? null : $v;

            if ($policy->isDirty('is_published') && $policy->is_published && blank($policy->file_path)) {
                throw new \RuntimeException('Dokumen tanpa berkas PDF tidak boleh diterbitkan.');
            }

            if (! $policy->acknowledgments()->exists()) {
                return;
            }

            foreach (self::LOCKED_AFTER_ACKNOWLEDGMENT as $column) {
                if ($policy->isDirty($column) && $normalize($policy->getOriginal($column)) !== $normalize($policy->getAttribute($column))) {
                    throw new \RuntimeException('Dokumen yang sudah diterima karyawan tidak boleh diubah. Buat dokumen versi baru, lalu cabut terbit versi lama.');
                }
            }
        });

        static::deleting(function (CompanyPolicy $policy) {
            if ($policy->acknowledgments()->exists()) {
                throw new \RuntimeException('Dokumen yang sudah diterima karyawan tidak boleh dihapus; cabut terbit saja agar bukti penerimaan tetap utuh.');
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'category', 'version', 'effective_from', 'requires_acknowledgment', 'is_published'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('company_policy');
    }

    public function acknowledgments(): HasMany
    {
        return $this->hasMany(PolicyAcknowledgment::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /** Karyawan yang wajib menerima: akun aktif selain mitra. */
    public static function audienceQuery(?int $storeId = null): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
            ->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId));
    }

    /**
     * Memberi tahu seluruh karyawan aktif bahwa dokumen wajib-terima ini baru terbit (audit Peraturan & Kebijakan, 2026-10-31). Tanpa ini
     * karyawan baru tahu bila kebetulan membuka Profil. Ketukan pada pesan membuka layar Peraturan & Kebijakan. Mengembalikan jumlah penerima.
     */
    public function announce(): int
    {
        if (! $this->is_published || ! $this->requires_acknowledgment) {
            return 0;
        }

        $ids = static::audienceQuery()->pluck('id')->all();

        if ($ids === []) {
            return 0;
        }

        app(\App\Services\StaffNotifier::class)->toUsers(
            $ids,
            'Dokumen Baru Perlu Anda Terima',
            $this->title . ($this->version ? " ({$this->version})" : '') . ' sudah terbit. Buka Peraturan & Kebijakan untuk membaca dan menerimanya.',
            ['type' => 'company_policy', 'route' => '/staff/policies'],
            'policy-published-' . $this->id,
            'warning'
        );

        return count($ids);
    }

    /**
     * Mengingatkan karyawan aktif yang belum menerima dokumen ini (aksi manual dari daftar; $storeId membatasi ke satu toko). Pesan yang
     * belum dibuka diperbarui, bukan ditumpuk. Mengembalikan jumlah karyawan yang diingatkan.
     */
    public function remindPending(?int $storeId = null): int
    {
        if (! $this->is_published || ! $this->requires_acknowledgment) {
            return 0;
        }

        $ids = $this->pendingUsers($storeId)->pluck('id')->all();

        if ($ids === []) {
            return 0;
        }

        app(\App\Services\StaffNotifier::class)->toUsers(
            $ids,
            'Pengingat Menerima Dokumen',
            $this->title . ($this->version ? " ({$this->version})" : '') . ' belum Anda terima. Buka Peraturan & Kebijakan untuk membaca dan menerimanya.',
            ['type' => 'company_policy', 'route' => '/staff/policies'],
            'policy-remind-' . $this->id,
            'warning'
        );

        return count($ids);
    }

    /** Karyawan aktif yang belum menerima dokumen ini. */
    public function pendingUsers(?int $storeId = null): Builder
    {
        return static::audienceQuery($storeId)
            ->whereNotIn('id', $this->acknowledgments()->select('user_id'));
    }

    /**
     * Peringatan untuk payroll: potongan telat dan Alpha hanya sah bila tertulis di Peraturan Perusahaan yang sudah diterima karyawan.
     * Mengembalikan teks peringatan, atau null bila ada Peraturan Perusahaan terbit dan semua karyawan di daftar sudah menerimanya.
     *
     * @param  \Illuminate\Support\Collection<int, User>  $users
     */
    public static function payrollDeductionWarning(\Illuminate\Support\Collection $users): ?string
    {
        $policies = static::published()->where('category', 'peraturan_perusahaan')->where('requires_acknowledgment', true)->get(['id']);

        if ($policies->isEmpty()) {
            return 'Belum ada Peraturan Perusahaan yang terbit di sistem. Potongan telat dan Alpha baru sah bila tertulis di Peraturan Perusahaan yang diterima karyawan; unggah dan terbitkan di menu Peraturan & Kebijakan.';
        }

        $accepted = PolicyAcknowledgment::whereIn('company_policy_id', $policies->pluck('id'))->get(['user_id', 'company_policy_id'])->groupBy('user_id');

        $missing = $users->filter(function (User $user) use ($policies, $accepted) {
            $done = ($accepted[$user->id] ?? collect())->pluck('company_policy_id');

            return $policies->contains(fn ($p) => ! $done->contains($p->id));
        });

        return $missing->isEmpty()
            ? null
            : 'Belum menerima Peraturan Perusahaan: ' . $missing->pluck('name')->implode(', ') . '. Potongan mereka berisiko tidak sah.';
    }

    public function isAcknowledgedBy(User $user): bool
    {
        return $this->acknowledgments()->where('user_id', $user->id)->exists();
    }

    /** Catat penerimaan; aman dipanggil berulang (penerimaan pertama yang berlaku). */
    public function acknowledge(User $user, ?string $ip = null): PolicyAcknowledgment
    {
        return PolicyAcknowledgment::firstOrCreate(
            ['company_policy_id' => $this->id, 'user_id' => $user->id],
            ['acknowledged_at' => now(), 'ip_address' => $ip],
        );
    }
}
