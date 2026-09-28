<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ChartOfAccount extends Model
{
    use LogsActivity;

    protected $fillable = [
        'code',
        'name',
        'type',
        'normal_balance',
        'parent_id',
        'is_postable',
        'is_active',
        'is_cash',
        'is_contra',
        'cash_flow_category',
        'description',
    ];

    protected $casts = [
        'is_postable' => 'boolean',
        'is_active' => 'boolean',
        'is_cash' => 'boolean',
        'is_contra' => 'boolean',
    ];

    /**
     * Kelas akun yang normal saldonya DEBIT — dipakai
     * normalBalanceFor()/isDebitNormal() supaya aturan ini SATU tempat,
     * tidak diketik ulang di seeder/form/jurnal nanti.
     */
    private const DEBIT_NORMAL_TYPES = ['aset', 'beban_pokok', 'beban_operasional', 'beban_lain'];

    /**
     * Kode akun yang dicari LANGSUNG oleh posting otomatis (Booking, DP,
     * Refund, Payroll, Hutang/Piutang, Stok, Penyusutan) -- ditambahkan
     * 2026-09-28 (audit Bagan Akun). Akun ini tidak boleh dihapus, tidak
     * boleh dinonaktifkan, dan klasifikasi/postable-nya tidak boleh
     * diubah, kalau tidak posting otomatis gagal. 1200 = induk akun aset
     * tetap (AssetResource).
     */
    public const SYSTEM_CODES = [
        '1101', '1110', '1130', '1131', '1200', '2110', '2140',
        '4100', '4200', '4400', '5300', '6110', '6120', '6420', '6520',
    ];

    /**
     * Digit pertama kode -> klasifikasi yang diizinkan (pola seeder).
     * Dipakai validasi form supaya akun 4xxx tidak bisa jadi beban, dst.
     */
    public const CODE_PREFIX_TYPES = [
        '1' => ['aset'],
        '2' => ['kewajiban'],
        '3' => ['modal'],
        '4' => ['pendapatan'],
        '5' => ['beban_pokok'],
        '6' => ['beban_operasional'],
        '7' => ['pendapatan_lain', 'beban_lain'],
        '8' => ['pajak'],
    ];

    public static function normalBalanceFor(string $type): string
    {
        return in_array($type, self::DEBIT_NORMAL_TYPES, true) ? 'debit' : 'kredit';
    }

    public function isDebitNormal(): bool
    {
        return $this->normal_balance === 'debit';
    }

    public function isSystem(): bool
    {
        return in_array($this->code, self::SYSTEM_CODES, true);
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
     * Saldo akun (bertanda, sesuai saldo normalnya): debit-kredit untuk
     * akun bersaldo normal debit, kredit-debit untuk kredit. Akun kontra
     * (akumulasi penyusutan, retur) memang bisa negatif -- laporan
     * menjumlahkan saldo bertanda ini, jadi hasilnya tetap benar.
     */
    public function balance(): float
    {
        // Hanya jurnal berstatus 'posted' -- draft belum masuk laporan.
        $row = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entry_lines.chart_of_account_id', $this->id)
            ->where('journal_entries.status', 'posted')
            ->selectRaw('COALESCE(SUM(journal_entry_lines.debit), 0) as d, COALESCE(SUM(journal_entry_lines.credit), 0) as c')
            ->first();

        return round((float) ($this->isDebitNormal() ? $row->d - $row->c : $row->c - $row->d), 2);
    }

    public function hasJournal(): bool
    {
        return DB::table('journal_entry_lines')->where('chart_of_account_id', $this->id)->exists();
    }

    /**
     * Pemakaian akun di seluruh sistem. FK bank_statement_lines &
     * finance_dashboard_widgets memakai cascadeOnDelete dan finance_categories/
     * assets nullOnDelete -- menghapus akun yang masih dipakai mereka
     * menghapus/melepas data diam-diam, jadi dicek di sini.
     *
     * @return array<string,int> label => jumlah (hanya yang > 0)
     */
    public function usageSummary(): array
    {
        $usage = [
            'jurnal' => DB::table('journal_entry_lines')->where('chart_of_account_id', $this->id)->count(),
            'mutasi bank' => DB::table('bank_statement_lines')->where('chart_of_account_id', $this->id)->count(),
            'kategori keuangan' => DB::table('finance_categories')->where('chart_of_account_id', $this->id)->count(),
            'transaksi keuangan' => DB::table('finance_transactions')->where('chart_of_account_id', $this->id)->count(),
            'aset tetap' => DB::table('assets')
                ->where(fn ($q) => $q->where('chart_of_account_id', $this->id)->orWhere('accumulated_depreciation_account_id', $this->id))
                ->count(),
            'template tagihan berulang' => DB::table('recurring_bill_templates')->where('chart_of_account_id', $this->id)->count(),
            'widget dashboard keuangan' => DB::table('finance_dashboard_widgets')->where('chart_of_account_id', $this->id)->count(),
            'akun anak' => self::where('parent_id', $this->id)->count(),
        ];

        return array_filter($usage);
    }

    public function isInUse(): bool
    {
        return $this->usageSummary() !== [];
    }

    /**
     * Alasan akun ini tidak boleh dihapus, atau null kalau boleh.
     */
    public function deletionBlocker(): ?string
    {
        if ($this->isSystem()) {
            return "Akun {$this->code} dipakai posting otomatis sistem, tidak boleh dihapus.";
        }

        $usage = $this->usageSummary();
        if ($usage) {
            $parts = collect($usage)->map(fn ($n, $label) => "{$n} {$label}")->implode(', ');

            return "Akun ini masih dipakai ({$parts}). Nonaktifkan saja kalau tidak mau dipakai lagi.";
        }

        return null;
    }

    /**
     * "1101 — Kas di Tangan" — dipakai konsisten di Select/label mana pun
     * akun ini ditampilkan (form jurnal nanti, laporan, dst).
     */
    public function getDisplayNameAttribute(): string
    {
        return "{$this->code} — {$this->name}";
    }

    /**
     * Guard integritas (audit Bagan Akun 2026-09-28). Sengaja DILEWATI kalau
     * tidak ada user login (seeder, migrasi, tinker, job) -- guard ini untuk
     * edit lewat UI; seeder/migrasi harus tetap bisa membuat & memperbaiki
     * akun sistem (mis. menambah akun 6520 yang hilang).
     */
    protected static function booted(): void
    {
        static::deleting(function (ChartOfAccount $account) {
            if (! auth()->check()) {
                return;
            }

            if ($reason = $account->deletionBlocker()) {
                throw new \RuntimeException($reason);
            }
        });

        static::updating(function (ChartOfAccount $account) {
            if (! auth()->check()) {
                return;
            }

            if ($account->isDirty('code')) {
                throw new \RuntimeException('Kode akun tidak boleh diubah.');
            }

            if ($account->isSystem()) {
                if ($account->isDirty(['type', 'is_postable', 'parent_id'])) {
                    throw new \RuntimeException("Akun {$account->code} dipakai posting otomatis; klasifikasi, status postable, dan induknya tidak boleh diubah.");
                }
                if ($account->isDirty('is_active') && ! $account->is_active) {
                    throw new \RuntimeException("Akun {$account->code} dipakai posting otomatis, tidak boleh dinonaktifkan.");
                }
            }

            if ($account->isDirty(['type', 'parent_id', 'is_postable']) && $account->hasJournal()) {
                throw new \RuntimeException('Akun ini sudah punya jurnal: klasifikasi, akun induk, dan status postable tidak boleh diubah (laporan historis akan berubah).');
            }

            // Standar akuntansi: akun yang masih bersaldo tidak boleh
            // dinonaktifkan/ditutup -- saldonya hilang dari pilihan jurnal
            // tapi tetap tampil di Neraca.
            if ($account->isDirty('is_active') && ! $account->is_active && abs($account->balance()) > 0.005) {
                throw new \RuntimeException('Akun ini masih punya saldo ' . number_format($account->balance(), 0, ',', '.') . '. Nolkan saldonya dulu (jurnal penyesuaian) sebelum dinonaktifkan.');
            }

            if ($account->isDirty('is_postable') && $account->is_postable && self::where('parent_id', $account->id)->exists()) {
                throw new \RuntimeException('Akun header yang masih punya akun anak tidak bisa dijadikan postable.');
            }

            if ($account->isDirty('parent_id') && $account->parent_id) {
                $cursor = $account->parent_id;
                $guard = 0;
                while ($cursor && $guard++ < 50) {
                    if ((int) $cursor === (int) $account->id) {
                        throw new \RuntimeException('Akun induk tidak valid: akan membentuk siklus hierarki.');
                    }
                    $cursor = self::where('id', $cursor)->value('parent_id');
                }
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'type', 'parent_id', 'is_postable', 'is_active', 'is_cash', 'is_contra', 'cash_flow_category'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('chart_of_account')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Akun {$this->code} — {$this->name} dibuat",
                'updated' => "Akun {$this->code} — {$this->name} diubah",
                'deleted' => "Akun {$this->code} — {$this->name} dihapus",
                default => "Akun {$this->code} — {$eventName}",
            });
    }
}
