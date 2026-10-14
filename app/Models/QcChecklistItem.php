<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Butir daftar periksa Quality Check per layanan (Fase 3A pengerjaan ulang booking, 2026-10-17). Diatur dari Filament
 * (menu Booking → Daftar Periksa QC). Saat QC dilakukan, butir disalin ke booking_qc_result_items.
 */
class QcChecklistItem extends Model
{
    use LogsActivity;

    protected $fillable = ['service_key', 'label', 'is_required', 'sort_order', 'is_active'];

    protected $casts = [
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('qc_checklist_item')
            ->logOnly(['service_key', 'label', 'is_required', 'sort_order', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
