<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Struktur dan skala upah (Karyawan Fase 3, 2026-10-31): rentang gaji pokok per golongan/jabatan. Pengusaha wajib menyusun dan
 * mengomunikasikannya; sistem memakainya untuk memeriksa gaji pokok karyawan berada dalam rentang golongannya (hanya peringatan,
 * tidak menolak penyimpanan). Batas bawah sebaiknya tidak di bawah upah minimum setempat, yang dicek manual oleh HR.
 */
class SalaryGrade extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'min_salary', 'max_salary', 'notes', 'is_active'];

    protected $casts = ['min_salary' => 'decimal:2', 'max_salary' => 'decimal:2', 'is_active' => 'boolean'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'min_salary', 'max_salary', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('salary_grade');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function contains(float $salary): bool
    {
        return $salary >= (float) $this->min_salary && $salary <= (float) $this->max_salary;
    }
}
