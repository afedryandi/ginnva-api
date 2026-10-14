<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu pemeriksaan Quality Check pada sebuah booking (Fase 3A pengerjaan ulang booking, 2026-10-17). Bisa ada beberapa per
 * booking (pemeriksaan ulang setelah perbaikan); yang menentukan adalah yang TERBARU. Append-only: tidak diedit setelah dibuat.
 */
class BookingQcResult extends Model
{
    protected $fillable = ['booking_id', 'inspector_user_id', 'passed', 'notes'];

    protected $casts = ['passed' => 'boolean'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BookingQcResultItem::class);
    }
}
