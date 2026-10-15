<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan serah terima unit (Fase 3B pengerjaan ulang booking, 2026-10-18); lihat migrasi create_booking_handovers_table.
 */
class BookingHandover extends Model
{
    protected $fillable = [
        'booking_id', 'recorded_by_user_id', 'care_instructions_given', 'wet_signature_required',
        'wet_signature_received', 'final_photo_path', 'notes', 'handed_over_at',
    ];

    protected $casts = [
        'care_instructions_given' => 'boolean',
        'wet_signature_required' => 'boolean',
        'wet_signature_received' => 'boolean',
        'handed_over_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
