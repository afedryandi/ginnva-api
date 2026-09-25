<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Modul Invoice (2026-09-15, analog "Daftar Invoice" Majoo) -- V1 murni
 * dokumen tagihan/cetak, TIDAK posting ke Jurnal Umum. Lihat migrasi
 * untuk alasan lengkap & keterbatasan (tidak ada kolom pajak, PPN
 * masih pending keputusan bisnis).
 */
class Invoice extends Model
{
    use LogsActivity;
    use HasStoreScope;

    public const STATUS_LABELS = [
        'draft' => 'Draf',
        'unpaid' => 'Belum Lunas',
        'paid' => 'Lunas',
        'void' => 'Void',
    ];

    protected $fillable = [
        'invoice_number',
        'store_id',
        'booking_id',
        'customer_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'billing_address',
        'shipping_address',
        'issue_date',
        'due_date',
        'status',
        'subtotal',
        'transaction_discount_type',
        'transaction_discount_value',
        'shipping_cost',
        'other_cost',
        'total',
        'amount_paid',
        'notes',
        'terms_conditions',
        'created_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'transaction_discount_value' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'other_cost' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function remainingAmount(): float
    {
        return round((float) $this->total - (float) $this->amount_paid, 2);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'unpaid'], true);
    }

    public function generateInvoiceNumber(): string
    {
        return self::generateNumberForStore($this->store_id);
    }

    /**
     * BUG DIPERBAIKI 2026-09-25 (audit Invoice) -- SEBELUMNYA murni
     * count()+1 (rawan tabrakan kalau 2 invoice dibuat nyaris bersamaan
     * untuk toko & hari yang sama -- transaction DB saja tidak cukup
     * mencegah ini tanpa locking eksplisit). Sekarang do-while(exists())
     * sama pola dengan Booking::generateBookingNumber()/Spk::
     * generateNumberForStore() (diperbaiki lebih dulu sesi ini) --
     * dipasangkan dengan jaring pengaman terakhir di
     * InvoiceService::create() (catch QueryException).
     */
    public static function generateNumberForStore(int $storeId): string
    {
        $prefix = 'INV/' . $storeId . '/' . now()->format('ymd') . '/';
        $sequence = self::where('invoice_number', 'like', $prefix . '%')->count() + 1;

        do {
            $candidate = $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            $sequence++;
        } while (self::where('invoice_number', $candidate)->exists());

        return $candidate;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'total', 'amount_paid', 'due_date'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('invoice');
    }
}
