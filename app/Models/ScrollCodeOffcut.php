<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Satu potongan sisa (wasted) dari satu gulungan PPF. Bukan stok inventori dan tanpa nilai uang; lihat migrasi
 * 2026_12_01_000001 untuk alasan desainnya. Status: stored (tersimpan), used (dipakai lewat baris memo), discarded (dibuang).
 */
class ScrollCodeOffcut extends Model
{
    use LogsActivity;

    public const STORED = 'stored';

    public const USED = 'used';

    public const DISCARDED = 'discarded';

    protected $fillable = [
        'scroll_code_id',
        'store_id',
        'origin_memo_id',
        'location',
        'notes',
        'status',
        'used_in_memo_item_id',
        'car_part',
        'used_at',
        'used_by',
        'discarded_at',
        'discarded_by',
        'discard_reason',
        'created_by',
    ];

    protected $casts = [
        'used_at' => 'datetime',
        'discarded_at' => 'datetime',
    ];

    public static function statusLabels(): array
    {
        return [
            self::STORED => 'Tersimpan',
            self::USED => 'Terpakai',
            self::DISCARDED => 'Dibuang',
        ];
    }

    /** Staf gudang (yang boleh mengubah Kode Gulungan) dan akses penuh yang mengelola potongan. */
    public static function canManage(?User $user): bool
    {
        return $user !== null && ($user->isFullAccess() || $user->hasModuleAction(\App\Filament\Resources\ScrollCodeResource::class, 'update', false));
    }

    public function scopeStored($query)
    {
        return $query->where('status', self::STORED);
    }

    public function scrollCode(): BelongsTo
    {
        return $this->belongsTo(ScrollCode::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function originMemo(): BelongsTo
    {
        return $this->belongsTo(MaterialMemo::class, 'origin_memo_id');
    }

    public function usedInMemoItem(): BelongsTo
    {
        return $this->belongsTo(MaterialMemoItem::class, 'used_in_memo_item_id');
    }

    /** Nama yang tampil di memo dan daftar: kode roll asal + nomor potongan. */
    public function label(): string
    {
        $code = $this->scrollCode?->code ?? '—';

        return "Potongan sisa {$code} #{$this->id}" . ($this->location ? " ({$this->location})" : '');
    }

    /**
     * Buang potongan yang masih tersimpan. Baris tidak dihapus: siapa, kapan, dan alasannya tersimpan.
     *
     * @throws \InvalidArgumentException
     */
    public function discard(string $reason, int $userId): void
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Alasan membuang potongan wajib diisi.');
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($reason, $userId) {
            $locked = static::where('id', $this->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== self::STORED) {
                throw new \InvalidArgumentException('Hanya potongan yang masih tersimpan yang bisa dibuang.');
            }

            $locked->update([
                'status' => self::DISCARDED,
                'discarded_at' => now(),
                'discarded_by' => $userId,
                'discard_reason' => trim($reason),
            ]);

            $this->setRawAttributes($locked->getAttributes());
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'location', 'notes', 'car_part', 'discard_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('scroll_code_offcut')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Potongan sisa #{$this->id} disimpan",
                'updated' => "Potongan sisa #{$this->id} diubah",
                'deleted' => "Potongan sisa #{$this->id} dihapus",
                default => "Potongan sisa #{$this->id} — {$eventName}",
            });
    }
}
