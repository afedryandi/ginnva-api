<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartOfAccount extends Model
{
    protected $fillable = [
        'code',
        'name',
        'type',
        'normal_balance',
        'parent_id',
        'is_postable',
        'is_active',
        'is_cash',
        'cash_flow_category',
        'description',
    ];

    protected $casts = [
        'is_postable' => 'boolean',
        'is_active' => 'boolean',
        'is_cash' => 'boolean',
    ];

    /**
     * Kelas akun yang normal saldonya DEBIT — dipakai
     * normalBalanceFor()/isDebitNormal() supaya aturan ini SATU tempat,
     * tidak diketik ulang di seeder/form/jurnal nanti.
     */
    private const DEBIT_NORMAL_TYPES = ['aset', 'beban_pokok', 'beban_operasional', 'beban_lain'];

    public static function normalBalanceFor(string $type): string
    {
        return in_array($type, self::DEBIT_NORMAL_TYPES, true) ? 'debit' : 'kredit';
    }

    public function isDebitNormal(): bool
    {
        return $this->normal_balance === 'debit';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    /**
     * "1101 — Kas di Tangan" — dipakai konsisten di Select/label mana pun
     * akun ini ditampilkan (form jurnal nanti, laporan, dst).
     */
    public function getDisplayNameAttribute(): string
    {
        return "{$this->code} — {$this->name}";
    }
}
