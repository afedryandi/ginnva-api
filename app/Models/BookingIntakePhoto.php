<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Foto penerimaan kendaraan (Fase 3C pengerjaan ulang booking, 2026-10-19): empat sudut baku + foto kerusakan tambahan.
 */
class BookingIntakePhoto extends Model
{
    /** Sudut baku yang wajib (keputusan pemilik: 4 sudut). Kunci → label. */
    public const ANGLES = [
        'depan' => 'Depan',
        'belakang' => 'Belakang',
        'kiri' => 'Kiri',
        'kanan' => 'Kanan',
    ];

    public const DAMAGE = 'kerusakan';

    public const MAX_DAMAGE_PHOTOS = 10;

    protected $fillable = ['booking_id', 'angle', 'path', 'note', 'taken_by_user_id'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}
