<?php

namespace App\Models;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * "Template tagihan rutin" (audit Majoo, f48) — lihat migrasi
 * create_recurring_bill_templates_table & RecurringBillGenerationService
 * untuk alur generate-nya.
 */
class RecurringBillTemplate extends Model
{
    use LogsActivity;

    /** Akun tujuan template harus bertipe beban (sama dengan opsi di form). */
    public const EXPENSE_TYPES = ['beban_operasional', 'beban_lain', 'beban_pokok'];

    protected $fillable = [
        'name',
        'supplier_name',
        'supplier_id',
        'store_id',
        'chart_of_account_id',
        'amount',
        'day_of_month',
        'next_run_date',
        'end_date',
        'max_occurrences',
        'paused_until',
        'is_active',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'day_of_month' => 'integer',
        'next_run_date' => 'date',
        'end_date' => 'date',
        'paused_until' => 'date',
        'max_occurrences' => 'integer',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Template yang sudah pernah menghasilkan tagihan tidak boleh dihapus (source_id di
        // Payable jadi yatim) -- nonaktifkan saja. Hanya aktif untuk aksi user (auth()->check()).
        static::deleting(function (RecurringBillTemplate $template) {
            if (auth()->check() && $template->generatedPayables()->exists()) {
                return false;
            }

            return null;
        });

        // Perubahan yang mempengaruhi jurnal otomatis berikutnya -> direksi LAIN diberi tahu
        // (template auto-post tiap bulan tanpa approval, jadi minimal harus terlihat).
        static::updated(function (RecurringBillTemplate $template) {
            if (! auth()->check() || ! $template->wasChanged(['amount', 'chart_of_account_id', 'store_id', 'is_active', 'day_of_month', 'next_run_date', 'supplier_name', 'end_date', 'max_occurrences', 'paused_until'])) {
                return;
            }

            try {
                $changed = collect(['amount' => 'nominal', 'chart_of_account_id' => 'akun beban', 'store_id' => 'toko', 'is_active' => 'status aktif', 'day_of_month' => 'tanggal generate', 'next_run_date' => 'jadwal berikutnya', 'supplier_name' => 'supplier', 'end_date' => 'tanggal berakhir', 'max_occurrences' => 'batas jumlah tagihan', 'paused_until' => 'jeda'])
                    ->filter(fn ($label, $field) => $template->wasChanged($field))
                    ->values()
                    ->implode(', ');

                foreach (User::where('is_active', true)->where('id', '!=', auth()->id())->get()->filter(fn (User $u) => $u->isFullAccess()) as $user) {
                    Notification::make()
                        ->title("Template tagihan rutin \"{$template->name}\" diubah")
                        ->body(auth()->user()->name . " mengubah: {$changed}.")
                        ->warning()
                        ->sendToDatabase($user);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Tagihan (Payable) yang pernah di-generate dari template ini. */
    public function generatedPayables(): HasMany
    {
        return $this->hasMany(Payable::class, 'source_id')->where('source_type', 'recurring_bill_template');
    }

    /**
     * Tanggal jatuh tempo bulan berikutnya dari next_run_date SEKARANG
     * (dipanggil SETELAH generate berhasil, bukan sebelumnya) — clamp ke
     * hari terakhir bulan itu kalau day_of_month lebih besar dari jumlah
     * hari bulan tsb (mis. day_of_month=31 di bulan Februari -> 28/29).
     */
    public function computeNextRunDate(): Carbon
    {
        return $this->nextAfter($this->next_run_date);
    }

    /** Jadwal 1 bulan setelah $date, di tanggal day_of_month (clamp akhir bulan). */
    public function nextAfter(Carbon $date): Carbon
    {
        $next = $date->copy()->addMonthNoOverflow();

        return $next->day(min($this->day_of_month, $next->daysInMonth));
    }

    /** Jumlah tagihan aktif (tidak dibatalkan) yang sudah dihasilkan template ini. */
    public function generatedCount(): int
    {
        return $this->generatedPayables()->where('status', '!=', 'cancelled')->count();
    }

    /** Sudah lewat masa berlaku atau batas jumlah tagihan tercapai untuk jadwal $runDate. */
    public function isFinishedFor(Carbon $runDate): bool
    {
        if ($this->end_date && $runDate->gt($this->end_date)) {
            return true;
        }

        return $this->max_occurrences !== null && $this->generatedCount() >= $this->max_occurrences;
    }

    /**
     * Pratinjau N jadwal berikutnya (memperhitungkan end_date, max_occurrences, dan jeda).
     *
     * @return array<int, array{date: Carbon, skipped: bool}>
     */
    public function upcomingSchedule(int $limit = 5): array
    {
        $result = [];
        $date = $this->next_run_date->copy();
        $made = $this->exists ? $this->generatedCount() : 0;

        for ($i = 0; $i < 60 && count($result) < $limit; $i++) {
            if ($this->end_date && $date->gt($this->end_date)) {
                break;
            }

            $skipped = $this->paused_until !== null && $date->lte($this->paused_until);

            if (! $skipped && $this->max_occurrences !== null && $made >= $this->max_occurrences) {
                break;
            }

            $result[] = ['date' => $date->copy(), 'skipped' => $skipped];

            if (! $skipped) {
                $made++;
            }

            $date = $this->nextAfter($date);
        }

        return $result;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'supplier_name', 'store_id', 'chart_of_account_id', 'amount', 'day_of_month', 'next_run_date', 'end_date', 'max_occurrences', 'paused_until', 'supplier_id', 'is_active', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('recurring_bill_template')
            ->setDescriptionForEvent(fn (string $eventName) => "Template Tagihan Rutin \"{$this->name}\" {$eventName}");
    }
}
