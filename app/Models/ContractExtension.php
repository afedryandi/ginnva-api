<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ContractExtension extends Model
{
    use LogsActivity;

    protected $fillable = [
        'user_id',
        'previous_end_date',
        'new_end_date',
        'notes',
        'extended_by',
    ];

    protected $casts = [
        'previous_end_date' => 'date',
        'new_end_date'      => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function extender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'extended_by');
    }

    /**
     * Catat perpanjangan SEKALIGUS sinkronkan users.contract_end_date —
     * dibungkus transaction+lock supaya previous_end_date yang tersimpan
     * selalu akurat (snapshot nilai SEBELUM diubah), sama pola dengan
     * RawMaterial::recordMovement() menyimpan harga batch.
     */
    public static function recordExtension(User $user, string $newEndDate, ?int $extendedBy, ?string $notes = null): self
    {
        return DB::transaction(function () use ($user, $newEndDate, $extendedBy, $notes) {
            $freshUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            $extension = self::create([
                'user_id'            => $freshUser->id,
                'previous_end_date'  => $freshUser->contract_end_date,
                'new_end_date'       => $newEndDate,
                'notes'              => $notes,
                'extended_by'        => $extendedBy,
            ]);

            $freshUser->update(['contract_end_date' => $newEndDate]);

            return $extension;
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['new_end_date', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('contract_extension')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Kontrak {$this->user?->name} diperpanjang sampai {$this->new_end_date?->format('d M Y')}",
                'deleted' => "Riwayat perpanjangan kontrak {$this->user?->name} dihapus",
                default   => "Perpanjangan kontrak {$this->user?->name} — {$eventName}",
            });
    }
}