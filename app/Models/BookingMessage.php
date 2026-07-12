<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingMessage extends Model
{
    protected $fillable = [
        'booking_id',
        'sender_type',
        'sender_customer_id',
        'sender_user_id',
        'type',
        'body',
        'stage',
        'photo_path',
    ];

    /**
     * Label tahap untuk ditampilkan ke customer — urutannya sekaligus
     * dipakai untuk menghitung progress bar (index / total tahap).
     */
    public const STAGES = [
        'received'      => 'Diterima',
        'preparation'   => 'Persiapan',
        'installation'  => 'Proses Instalasi',
        'qc'            => 'Quality Check',
        'completed'     => 'Selesai',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function senderCustomer()
    {
        return $this->belongsTo(Customer::class, 'sender_customer_id');
    }

    public function senderUser()
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
