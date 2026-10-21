<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Templat pesan untuk menu Kirim Notifikasi (judul, isi, kategori, dan deep link yang dipakai ulang). */
class NotificationTemplate extends Model
{
    protected $fillable = ['name', 'audience', 'category', 'title', 'body', 'data', 'created_by'];

    protected $casts = ['data' => 'array'];
}
