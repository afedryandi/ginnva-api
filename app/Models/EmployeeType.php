<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Master Tipe Karyawan kustom (bukan enum tetap) — lihat migrasi
 * create_employee_types_table untuk penjelasan lengkap.
 */
class EmployeeType extends Model
{
    use LogsActivity;

    protected $fillable = [
        'name',
        'has_end_date',
        'is_active',
    ];

    protected $casts = [
        'has_end_date' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'has_end_date', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('employee_type')
            ->setDescriptionForEvent(fn (string $eventName) => "Tipe Karyawan \"{$this->name}\" {$eventName}");
    }
}
