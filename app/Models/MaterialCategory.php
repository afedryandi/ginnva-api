<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MaterialCategory extends Model
{
    // Audit trail (audit Kategori Materi 2026-09-29) -- SEBELUMNYA perubahan kategori tidak
    // tercatat sama sekali.
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'sort_order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('material_category');
    }

    protected $fillable = ['name', 'sort_order'];

    public function materials()
    {
        return $this->hasMany(Material::class);
    }
}
