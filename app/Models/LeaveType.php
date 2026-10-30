<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Jenis cuti/izin/sakit yang bisa diatur staf (Karyawan Fase 2, 2026-10-30). "category" (izin/sakit/cuti) tetap dipakai laporan dan
 * absensi; jenis menentukan batas hari per pengajuan, syarat lampiran, dan apakah memotong jatah cuti tahunan.
 *
 * Angka bawaan di ensureDefaults() mengikuti UU 13/2003 Pasal 82 dan 93 sebagaimana diringkas sumber sekunder; belum diverifikasi ke
 * teks resmi, dan aturan ketenagakerjaan masih bisa berubah (putusan MK 168/PUU-XXI/2023), jadi semuanya bisa diubah di panel.
 */
class LeaveType extends Model
{
    use LogsActivity;

    public const CATEGORIES = ['izin' => 'Izin', 'sakit' => 'Sakit', 'cuti' => 'Cuti'];

    /** Jenis dasar menurut kategori, dipakai bila pengajuan hanya menyebut kategori (aplikasi lama). */
    public const CODE_BY_CATEGORY = ['izin' => 'izin', 'sakit' => 'sakit', 'cuti' => 'cuti_tahunan'];

    /** Tiga jenis dasar: cadangan untuk aplikasi lama dan dasar jatah tahunan. Tidak boleh dihapus maupun dinonaktifkan. */
    public const BASE_CODES = ['izin', 'sakit', 'cuti_tahunan'];

    /** Jenis bawaan di luar tiga jenis dasar (lihat ensureDefaults()). Tidak boleh dihapus (akan dibuat ulang); cukup dinonaktifkan. */
    public const DEFAULT_CODES = [
        'cuti_haid', 'cuti_melahirkan', 'cuti_keguguran', 'cuti_menikah', 'cuti_menikahkan_anak', 'cuti_khitan_baptis_anak',
        'cuti_istri_melahirkan', 'cuti_keluarga_meninggal', 'cuti_serumah_meninggal', 'cuti_ibadah',
    ];

    protected $fillable = ['code', 'name', 'category', 'deducts_annual_quota', 'max_days', 'document_required_from_days', 'notes', 'sort_order', 'is_active'];

    protected static function booted(): void
    {
        // Penjaga di model (bukan hanya di form) supaya tidak bisa dilewati dari tempat lain.
        static::updating(function (LeaveType $type) {
            if ($type->isDirty('is_active') && ! $type->is_active && $type->isBaseType()) {
                throw new \RuntimeException("Jenis dasar \"{$type->name}\" tidak bisa dinonaktifkan.");
            }

            // Pemakaian jatah dihitung dari pengaturan jenis saat ini; mengubahnya setelah dipakai menulis ulang riwayat jatah semua karyawan.
            if ($type->isDirty('deducts_annual_quota') && $type->requests()->exists()) {
                throw new \RuntimeException("Pengaturan potong jatah \"{$type->name}\" tidak bisa diubah karena sudah dipakai pengajuan. Buat jenis baru.");
            }
        });

        static::deleting(function (LeaveType $type) {
            if ($type->isBuiltIn()) {
                throw new \RuntimeException("Jenis bawaan \"{$type->name}\" tidak bisa dihapus; nonaktifkan saja bila tidak dipakai.");
            }
        });
    }

    public function isBaseType(): bool
    {
        return in_array($this->code, self::BASE_CODES, true);
    }

    /** Jenis dasar atau bawaan sistem: tidak bisa dihapus. */
    public function isBuiltIn(): bool
    {
        return $this->isBaseType() || in_array($this->code, self::DEFAULT_CODES, true);
    }

    protected $casts = [
        'deducts_annual_quota' => 'boolean',
        'is_active' => 'boolean',
        'max_days' => 'integer',
        'document_required_from_days' => 'integer',
        'sort_order' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'category', 'deducts_annual_quota', 'max_days', 'document_required_from_days', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('leave_type');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public static function forCategory(string $category): ?self
    {
        return static::where('code', self::CODE_BY_CATEGORY[$category] ?? $category)->first();
    }

    /** Pengajuan sepanjang $days hari wajib melampirkan dokumen? */
    public function requiresDocumentFor(int $days): bool
    {
        return $this->document_required_from_days !== null && $days >= $this->document_required_from_days;
    }

    /** Keterangan singkat syarat jenis ini untuk layar karyawan. */
    public function ruleSummary(): string
    {
        $parts = [];

        if ($this->max_days) {
            $parts[] = "maksimal {$this->max_days} hari";
        }

        if ($this->document_required_from_days !== null) {
            $parts[] = $this->document_required_from_days <= 1
                ? 'wajib melampirkan dokumen'
                : "wajib melampirkan dokumen bila {$this->document_required_from_days} hari atau lebih";
        }

        if ($this->deducts_annual_quota) {
            $parts[] = 'memotong jatah cuti tahunan';
        }

        return $parts ? ucfirst(implode(', ', $parts)) . '.' : '';
    }

    /** Jenis bawaan di luar tiga jenis dasar; aman dipanggil berulang (tidak menimpa yang sudah diubah staf). */
    public static function ensureDefaults(): void
    {
        $defaults = [
            ['cuti_haid', 'Cuti Haid', 'cuti', 2, null, 'Hari pertama dan kedua haid bila merasa sakit.', 40],
            ['cuti_melahirkan', 'Cuti Melahirkan', 'cuti', 90, 1, '1,5 bulan sebelum dan 1,5 bulan sesudah melahirkan; lampirkan surat dokter/bidan.', 50],
            ['cuti_keguguran', 'Cuti Keguguran', 'cuti', 45, 1, 'Sekitar 1,5 bulan; lampirkan surat dokter/bidan.', 60],
            ['cuti_menikah', 'Cuti Menikah', 'cuti', 3, null, 'Pekerja menikah.', 70],
            ['cuti_menikahkan_anak', 'Cuti Menikahkan Anak', 'cuti', 2, null, null, 80],
            ['cuti_khitan_baptis_anak', 'Cuti Khitanan/Pembaptisan Anak', 'cuti', 2, null, null, 90],
            ['cuti_istri_melahirkan', 'Cuti Istri Melahirkan/Keguguran', 'cuti', 2, null, 'Untuk suami.', 100],
            ['cuti_keluarga_meninggal', 'Cuti Keluarga Inti Meninggal', 'cuti', 2, null, 'Suami/istri, orang tua/mertua, anak, atau menantu.', 110],
            ['cuti_serumah_meninggal', 'Cuti Anggota Keluarga Serumah Meninggal', 'cuti', 1, null, null, 120],
            ['cuti_ibadah', 'Cuti Ibadah Keagamaan', 'cuti', null, null, 'Mis. ibadah haji atau ziarah keagamaan; lama diatur Peraturan Perusahaan.', 130],
        ];

        foreach ($defaults as [$code, $name, $category, $maxDays, $docFrom, $notes, $sort]) {
            static::firstOrCreate(['code' => $code], [
                'name' => $name,
                'category' => $category,
                'deducts_annual_quota' => false,
                'max_days' => $maxDays,
                'document_required_from_days' => $docFrom,
                'notes' => $notes,
                'sort_order' => $sort,
                'is_active' => true,
            ]);
        }
    }
}
