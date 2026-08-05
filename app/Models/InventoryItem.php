<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class InventoryItem extends Model
{
    use LogsActivity;

    protected $fillable = [
        'code',
        'name',
        'category',
        'status',
        'notes',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class)->latest();
    }

    /**
     * Kode unik untuk 1 unit fisik (kardus/gulungan) — dikodekan ke QR
     * yang ditempel ke barang. Format acak (bukan sequential) supaya
     * orang tidak bisa menebak-nebak kode kardus lain hanya dari 1 kode
     * yang terlihat.
     */
    public static function generateCode(): string
    {
        do {
            $code = 'INV-' . strtoupper(Str::random(8));
        } while (self::where('code', $code)->exists());

        return $code;
    }

    /**
     * Catat 1 kejadian keluar/masuk sekaligus update status — dipakai
     * dari endpoint scan mobile app. Karena 1 kardus = 1 unit, tidak ada
     * kuantitas untuk dihitung — cuma perlu jaga supaya tidak bisa
     * "masuk" barang yang statusnya memang sudah di gudang (in_stock),
     * atau "keluar" barang yang memang sudah keluar (out). Dibungkus
     * transaction + row lock supaya 2 staff yang scan barang sama nyaris
     * bersamaan tidak saling menimpa.
     *
     * @throws \InvalidArgumentException kalau transisi status tidak masuk akal
     *         (mis. "catat masuk" untuk barang yang sudah in_stock).
     */
    public function recordMovement(string $type, ?int $userId, ?string $note = null): InventoryMovement
    {
        return DB::transaction(function () use ($type, $userId, $note) {
            $item = self::where('id', $this->id)->lockForUpdate()->firstOrFail();

            if ($type === 'in' && $item->status === 'in_stock') {
                throw new \InvalidArgumentException('Barang ini sudah tercatat ada di gudang (in_stock).');
            }
            if ($type === 'out' && $item->status === 'out') {
                throw new \InvalidArgumentException('Barang ini sudah tercatat keluar sebelumnya.');
            }

            $item->update(['status' => $type === 'in' ? 'in_stock' : 'out']);

            $movement = $item->movements()->create([
                'type' => $type,
                'note' => $note,
                'user_id' => $userId,
            ]);

            $this->setRawAttributes($item->getAttributes());

            return $movement;
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'category', 'status', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('inventory_item')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Barang inventaris \"{$this->name}\" ({$this->code}) didaftarkan",
                'updated' => "Barang inventaris \"{$this->name}\" ({$this->code}) diubah",
                'deleted' => "Barang inventaris \"{$this->name}\" ({$this->code}) dihapus",
                default => "Barang inventaris \"{$this->name}\" — {$eventName}",
            });
    }
}
