<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Butir standar checklist onboarding/offboarding karyawan (Karyawan Fase 3, 2026-10-31). Daftar bawaan hanya titik awal dan bisa diubah
 * staf. Saat checklist dimulai untuk seorang karyawan, butir aktif disalin ke EmployeeChecklistItem sehingga perubahan template
 * berikutnya tidak mengubah checklist yang sudah berjalan.
 */
class ChecklistTemplate extends Model
{
    use LogsActivity;

    public const KINDS = ['onboarding' => 'Onboarding (karyawan baru)', 'offboarding' => 'Offboarding (karyawan keluar)'];

    protected $fillable = ['kind', 'title', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['kind', 'title', 'sort_order', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('checklist_template');
    }

    /** Butir bawaan; aman dipanggil berulang. */
    public static function ensureDefaults(): void
    {
        $defaults = [
            'onboarding' => [
                'Dokumen identitas lengkap (KTP, NPWP, rekening bank, nomor BPJS)',
                'Perjanjian kerja/kontrak ditandatangani',
                'Peraturan Perusahaan diterima karyawan (tercatat di aplikasi)',
                'Seragam dan alat pelindung diri diserahkan',
                'Pengenalan produk, SOP pemasangan, dan keselamatan kerja',
                'Akun aplikasi staf dibuat dan hak aksesnya diatur',
                'Aset kerja diserahkan (kunci, perangkat, alat)',
            ],
            'offboarding' => [
                'Aset kerja dikembalikan (kunci, seragam, perangkat, alat)',
                'Sisa kasbon/pinjaman diperhitungkan',
                'Hak akhir dihitung akuntan (gaji terakhir, sisa cuti, uang kompensasi/pesangon bila ada)',
                'Serah terima pekerjaan dan booking yang ditangani',
                'Akun aplikasi dinonaktifkan',
                'Surat keterangan kerja diberikan bila diminta',
            ],
        ];

        foreach ($defaults as $kind => $titles) {
            foreach ($titles as $i => $title) {
                static::firstOrCreate(['kind' => $kind, 'title' => $title], ['sort_order' => ($i + 1) * 10, 'is_active' => true]);
            }
        }
    }
}
