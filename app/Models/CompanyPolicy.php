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
    public static function audienceQuery(): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'));
    }

    /** Karyawan aktif yang belum menerima dokumen ini. */
    public function pendingUsers(): Builder
    {
        return static::audienceQuery()
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
