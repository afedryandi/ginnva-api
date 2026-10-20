<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Kategori komponen gaji (Karyawan Fase 1, 2026-10-29). Jenis "earning" menambah gaji bersih (tunjangan, bonus, THR, uang kompensasi),
 * "deduction" menguranginya (BPJS, PPh 21, kasbon, potongan lain). Akun pembukuan tiap kategori diatur akuntan di panel; bawaannya dibuat
 * oleh ensureDefaults(). Angka BPJS/PPh 21 dihitung di luar sistem (keputusan pemilik), sistem hanya mencatat hasilnya.
 */
class PayrollItemCategory extends Model
{
    use LogsActivity;

    public const EARNING = 'earning';
    public const DEDUCTION = 'deduction';

    public const TYPES = [
        self::EARNING => 'Tambahan gaji',
        self::DEDUCTION => 'Potongan gaji',
    ];

    protected $fillable = ['code', 'name', 'type', 'chart_of_account_id', 'is_recurring_default', 'sort_order', 'is_active'];

    protected $casts = ['is_recurring_default' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'type', 'chart_of_account_id', 'is_recurring_default', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('payroll_item_category');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function isEarning(): bool
    {
        return $this->type === self::EARNING;
    }

    /**
     * Akun baru yang dibutuhkan komponen gaji (dibuat bila belum ada) dan kategori bawaan. Akun yang sudah ada tidak disentuh.
     * Aman dipanggil berulang.
     */
    public static function ensureDefaults(): void
    {
        $accounts = [
            // code => [nama, tipe, induk, klasifikasi arus kas, keterangan]
            // 6130 dan 6140 sudah ada di ChartOfAccountSeeder (Beban Tunjangan & BPJS, Beban Bonus/Insentif); dibuat hanya bila belum ada.
            '6130' => ['Beban Tunjangan & BPJS', 'beban_operasional', '6100', 'operasional', 'Tunjangan tetap/tidak tetap dan penyesuaian tambahan di slip gaji.'],
            '6140' => ['Beban Bonus/Insentif', 'beban_operasional', '6100', 'operasional', 'Bonus dan insentif yang dibayarkan lewat slip gaji.'],
            '6145' => ['Beban THR', 'beban_operasional', '6100', 'operasional', 'Tunjangan Hari Raya yang dibayarkan lewat slip gaji.'],
            '6150' => ['Beban Kompensasi Kontrak', 'beban_operasional', '6100', 'operasional', 'Uang kompensasi PKWT yang dibayarkan lewat slip gaji.'],
            '2180' => ['Hutang BPJS', 'kewajiban', '2100', 'operasional', 'Potongan BPJS karyawan yang belum disetor.'],
            '2190' => ['Hutang Potongan Karyawan Lainnya', 'kewajiban', '2100', 'operasional', 'Potongan gaji lain yang belum disetor/diselesaikan.'],
        ];

        foreach ($accounts as $code => [$name, $type, $parent, $flow, $description]) {
            $account = ChartOfAccount::firstOrCreate(['code' => $code], [
                'name' => $name,
                'type' => $type,
                'normal_balance' => ChartOfAccount::normalBalanceFor($type),
                'is_postable' => true,
                'is_active' => true,
                'is_cash' => false,
                'is_contra' => false,
                'cash_flow_category' => $flow,
                'description' => $description,
            ]);

            if (! $account->parent_id) {
                $parentId = ChartOfAccount::where('code', $parent)->value('id');
                $parentId && $account->update(['parent_id' => $parentId]);
            }
        }

        $categories = [
            // code => [nama, jenis, kode akun, ikut disalin bulan depan?, urutan]
            'tunjangan_tetap' => ['Tunjangan Tetap', self::EARNING, '6130', true, 10],
            'tunjangan_tidak_tetap' => ['Tunjangan Tidak Tetap', self::EARNING, '6130', false, 20],
            'bonus' => ['Bonus / Insentif', self::EARNING, '6140', false, 30],
            'thr' => ['THR', self::EARNING, '6145', false, 40],
            'uang_kompensasi' => ['Uang Kompensasi Kontrak', self::EARNING, '6150', false, 50],
            'penyesuaian_tambah' => ['Penyesuaian (Tambah)', self::EARNING, '6130', false, 60],
            'bpjs_kesehatan' => ['BPJS Kesehatan (potongan karyawan)', self::DEDUCTION, '2180', true, 110],
            'bpjs_ketenagakerjaan' => ['BPJS Ketenagakerjaan (potongan karyawan)', self::DEDUCTION, '2180', true, 120],
            'pph21' => ['PPh 21', self::DEDUCTION, '2130', false, 130],
            'kasbon' => ['Cicilan Kasbon', self::DEDUCTION, '1120', true, 140],
            'potongan_lain' => ['Potongan Lain', self::DEDUCTION, '2190', false, 150],
        ];

        foreach ($categories as $code => [$name, $type, $accountCode, $recurring, $order]) {
            static::firstOrCreate(['code' => $code], [
                'name' => $name,
                'type' => $type,
                'chart_of_account_id' => ChartOfAccount::where('code', $accountCode)->value('id'),
                'is_recurring_default' => $recurring,
                'sort_order' => $order,
                'is_active' => true,
            ]);
        }
    }
}
