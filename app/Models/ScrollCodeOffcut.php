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
        'used_in_spk_id',
        'used_spk_number',
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

    public function usedInSpk(): BelongsTo
    {
        return $this->belongsTo(Spk::class, 'used_in_spk_id');
    }

    /**
     * Pakai potongan ini langsung untuk sebuah SPK (tanpa memo): pilih SPK dari daftar atau ketik nomornya, dan isi bagian mobil yang dikerjakan. Nomor yang
     * diketik dan cocok dengan SPK toko potongan ini ikut tertaut ke SPK itu. Potongan bukan stok dan tanpa nilai uang; status berubah ke Terpakai.
     *
     * @throws \InvalidArgumentException
     */
    public function markUsed(?int $spkId, ?string $spkNumber, string $carPart, int $userId): void
    {
        $carPart = trim($carPart);

        if ($carPart === '') {
            throw new \InvalidArgumentException('Bagian mobil yang dikerjakan wajib diisi.');
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($spkId, $spkNumber, $carPart, $userId) {
            $locked = static::where('id', $this->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== self::STORED) {
                throw new \InvalidArgumentException('Potongan ini sudah tidak tersimpan (terpakai atau dibuang).');
            }

            $spk = null;
            if ($spkId) {
                $spk = Spk::withoutGlobalScopes()->find($spkId);
            } elseif (filled($spkNumber)) {
                $spk = Spk::withoutGlobalScopes()
                    ->where('spk_number', trim((string) $spkNumber))
                    ->when($locked->store_id, fn ($q) => $q->where('store_id', $locked->store_id))
                    ->first();
            }

            $locked->update([
                'status' => self::USED,
                'used_in_spk_id' => $spk?->id,
                'used_spk_number' => $spk?->spk_number ?? (filled($spkNumber) ? trim((string) $spkNumber) : null),
                'car_part' => $carPart,
                'used_at' => now(),
                'used_by' => $userId,
            ]);

            $this->setRawAttributes($locked->getAttributes());
        });
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
