<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class StoreReview extends Model
{
    use LogsActivity;

    protected $fillable = [
        'booking_id',
        'customer_id',
        'store_id',
        'sentiment',
        'tags',
        'comment',
        'followed_up_at',
        'followed_up_by',
        'follow_up_note',
    ];

    // SEBELUMNYA tidak ada audit trail — perubahan followed_up_at/
    // followed_up_by cuma bisa dilihat dari kolom itu sendiri, tidak ada
    // histori kalau di-unmark/diubah staff lain. Sama pola dengan
    // Warranty/WarrantyClaim. Lihat audit modul Review Toko 2026-08-27.
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['followed_up_at', 'followed_up_by', 'follow_up_note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $casts = [
        'tags'           => 'array',
        'followed_up_at' => 'datetime',
    ];

    /**
     * Daftar tag aspek yang bisa dipilih customer — dipisah biar label
     * Indonesia-nya konsisten dipakai backend (validasi) & referensi
     * admin, tanpa perlu tabel master terpisah untuk sesuatu yang jarang
     * berubah.
     */
    public const TAGS = [
        'pelayanan_ramah'      => 'Pelayanan Ramah',
        'hasil_rapi'           => 'Hasil Rapi & Memuaskan',
        'harga_worth_it'       => 'Harga Sepadan (Worth It)',
        'proses_cepat'         => 'Proses Cepat',
        'pelayanan_kurang'     => 'Pelayanan Kurang Ramah',
        'hasil_kurang_rapi'    => 'Hasil Kurang Rapi',
        'harga_kurang_sesuai'  => 'Harga Kurang Sesuai',
        'proses_lambat'        => 'Proses Lambat',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function followedUpBy()
    {
        return $this->belongsTo(User::class, 'followed_up_by');
    }
}
