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
