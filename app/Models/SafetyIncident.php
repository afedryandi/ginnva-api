<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Kejadian K3 (Karyawan Fase 5, 2026-11-02): nyaris celaka, cedera, kerusakan properti. Dicatat, ditindaklanjuti, lalu ditutup. Kejadian
 * dengan cedera mungkin mewajibkan pelaporan ke BPJS Ketenagakerjaan (JKK) dalam batas waktu tertentu; ketentuannya dicek HR/akuntan,
 * sistem hanya menandai kejadian cedera yang belum ditutup.
 */
class SafetyIncident extends Model
{
    use HasStoreScope;
    use LogsActivity;

    public const TYPES = [
        'near_miss' => 'Nyaris celaka',
        'injury' => 'Cedera',
        'property_damage' => 'Kerusakan properti/kendaraan',
        'other' => 'Lainnya',
    ];

    public const SEVERITIES = ['ringan' => 'Ringan', 'sedang' => 'Sedang', 'berat' => 'Berat'];

    protected $fillable = [
        'store_id', 'occurred_at', 'type', 'severity', 'user_id', 'description', 'immediate_action', 'root_cause', 'follow_up',
        'status', 'closed_at', 'reported_by',
    ];

    protected $casts = ['occurred_at' => 'datetime', 'closed_at' => 'datetime'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['store_id', 'occurred_at', 'type', 'severity', 'user_id', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('safety_incident');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * Tutup kejadian. Wajib ada tindak lanjut tertulis supaya kejadian tidak ditutup tanpa penanganan.
     *
     * @throws RuntimeException
     */
    public function close(): void
    {
        if (blank($this->follow_up)) {
            throw new RuntimeException('Isi tindak lanjut/pencegahan sebelum menutup kejadian.');
        }

        $this->update(['status' => 'closed', 'closed_at' => now()]);
    }
}
