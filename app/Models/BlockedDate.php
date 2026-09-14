<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;

class BlockedDate extends Model
{
    // Global Scope store-id (audit framework 2026-09-14, "Isolasi data
    // multi-tenant") — lihat App\Models\Scopes\StoreScope. Pola manual
    // yang sudah ada di BlockedDateResource::getEloquentQuery() SENGAJA
    // DIBIARKAN sebagai defense-in-depth.
    use HasStoreScope;

    protected $fillable = ['store_id', 'date', 'reason'];

    protected $casts = ['date' => 'date'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
