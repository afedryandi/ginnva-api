<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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
     * Tahap PER PRODUK — Kaca Film & PPF punya urutan/nama beda-beda untuk
     * 3 tahap pertamanya, baru menyatu ke SHARED_STAGES di akhir. Booking
     * yang cuma pesan 1 produk cukup pakai 1 track ini + SHARED_STAGES;
     * yang pesan keduanya jalan paralel (lihat Booking::stageColumnFor()).
     */
    public const PRODUCT_STAGES = [
        'kaca_film' => [
            'kf_cleaning'     => 'Pembersihan',
            'kf_heating'      => 'Pemanasan',
            'kf_installation' => 'Instalasi Kaca Film',
        ],
        'ppf' => [
            'ppf_washing'      => 'Proses Cuci',
            'ppf_detailing'    => 'Detailing',
            'ppf_installation' => 'Pemasangan PPF',
        ],
    ];

    /**
     * Tahap akhir BERSAMA — berlaku 1x untuk seluruh mobil, terlepas dari
     * berapa produk yang dipesan (mobil cuma diserahterimakan sekali).
     */
    public const SHARED_STAGES = [
        'qc'        => 'Quality Check',
        'completed' => 'Serah Terima Unit',
    ];

    /**
     * Gabungan semua tahap yang valid (dari kedua track produk + tahap
     * bersama) — dipakai untuk validasi generik & lookup label tanpa perlu
     * tahu produk booking-nya di titik itu (mis. validasi request masuk).
     */
    public static function allStages(): array
    {
        return array_merge(
            self::PRODUCT_STAGES['kaca_film'],
            self::PRODUCT_STAGES['ppf'],
            self::SHARED_STAGES
        );
    }

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

    public function photos()
    {
        return $this->hasMany(BookingMessagePhoto::class);
    }

    /**
     * Gabungan foto dari tabel booking_message_photos (baru, boleh lebih
     * dari 1) — fallback ke kolom photo_path lama untuk pesan yang
     * terkirim SEBELUM tabel ini ada, supaya histori chat lama tidak
     * kehilangan fotonya. Sebelumnya method ini duplikat identik di
     * Api\Customer\BookingMessageController dan Api\Staff\BookingMessageController
     * — dipindah ke sini supaya cuma ada satu sumber kebenaran.
     */
    public function photoUrls(): array
    {
        $urls = $this->photos->map(fn (BookingMessagePhoto $p) => static::fullImageUrl($p->path))->values()->all();

        if (empty($urls) && $this->photo_path) {
            return [static::fullImageUrl($this->photo_path)];
        }

        return $urls;
    }

    public static function fullImageUrl(?string $path): ?string
    {
        if (! $path) return null;

        $relative = Storage::url($path);

        if (str_starts_with($relative, 'http://') || str_starts_with($relative, 'https://')) {
            return $relative;
        }

        return rtrim(config('app.url'), '/') . '/' . ltrim($relative, '/');
    }
}
