<?php

namespace App\Support;

use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Penyajian Histori Aktivitas yang terbaca (Histori Aktivitas Fase 0, 2026-10-21): label kolom berbahasa Indonesia, nilai yang
 * dikenali (nama toko alih-alih angka, rupiah, tanggal WIB, ya/tidak, status), deskripsi bawaan paket yang diganti kalimat utuh,
 * serta label dan tautan objek. Hanya memengaruhi tampilan; data di activity_log tidak diubah.
 *
 * Log lama bisa merujuk model yang sudah dihapus dari kode (mis. RollScrapPool), jadi objek TIDAK dimuat lewat relasi morph
 * (class yang hilang melempar error), melainkan lewat guard class_exists.
 */
class ActivityPresenter
{
    public const TIMEZONE = 'Asia/Jakarta';

    private const KEY_LABELS = [
        'status' => 'Status', 'name' => 'Nama', 'is_active' => 'Aktif', 'notes' => 'Catatan', 'note' => 'Catatan',
        'store_id' => 'Toko', 'sort_order' => 'Urutan', 'amount' => 'Nominal', 'type' => 'Jenis', 'title' => 'Judul',
        'category' => 'Kategori', 'booking_id' => 'Booking', 'reason' => 'Alasan', 'description' => 'Deskripsi',
        'chart_of_account_id' => 'Akun', 'code' => 'Kode', 'user_id' => 'Pengguna', 'received_date' => 'Tanggal Diterima',
        'label' => 'Label', 'service_type' => 'Jenis Layanan', 'voided_at' => 'Dibatalkan Pada', 'journal_entry_id' => 'Jurnal',
        'reviewed_by' => 'Ditinjau Oleh', 'value' => 'Nilai', 'effective_from' => 'Berlaku Mulai', 'date' => 'Tanggal',
        'partner_id' => 'Partner', 'cancel_reason' => 'Alasan Pembatalan', 'decided_by' => 'Diputuskan Oleh',
        'decision_note' => 'Catatan Keputusan', 'source' => 'Sumber', 'film_product_id' => 'Produk', 'kind' => 'Jenis',
        'is_published' => 'Dipublikasikan', 'unit' => 'Satuan', 'valid_until' => 'Berlaku Sampai', 'amount_paid' => 'Sudah Dibayar',
        'due_date' => 'Jatuh Tempo', 'phone' => 'Telepon', 'phone_number' => 'Telepon', 'quantity' => 'Jumlah',
        'discount_amount' => 'Diskon', 'level' => 'Tingkat', 'period_month' => 'Periode', 'purchase_cost' => 'Harga Beli',
        'voucher_discount' => 'Diskon Voucher', 'current_stock' => 'Stok Saat Ini', 'reorder_point' => 'Batas Pesan Ulang',
        'unit_cost' => 'Harga Satuan', 'address' => 'Alamat', 'customer_group_id' => 'Grup Pelanggan', 'customer_id' => 'Pelanggan',
        'price' => 'Harga', 'base_price' => 'Harga Dasar', 'vehicle_size' => 'Ukuran Kendaraan', 'item_name' => 'Nama Barang',
        'posted_at' => 'Diposting Pada', 'supplier_id' => 'Supplier', 'supplier_name' => 'Nama Supplier', 'payment_date' => 'Tanggal Bayar',
        'void_reason' => 'Alasan Pembatalan', 'bank_name' => 'Nama Bank', 'customer_name' => 'Nama Pelanggan',
        'expiry_date' => 'Tanggal Kedaluwarsa', 'commission_amount' => 'Komisi', 'technician_id' => 'Teknisi', 'brand' => 'Merek',
        'model' => 'Model', 'expires_at' => 'Kedaluwarsa Pada', 'rejection_reason' => 'Alasan Penolakan', 'closed_by' => 'Ditutup Oleh',
        'assigned_to' => 'Ditugaskan Kepada', 'purchase_date' => 'Tanggal Beli', 'clock_in_at' => 'Jam Masuk', 'clock_out_at' => 'Jam Pulang',
        'late_minutes' => 'Terlambat (menit)', 'current_stage' => 'Tahap Saat Ini', 'secondary_stage' => 'Tahap Kedua',
        'preferred_date' => 'Tanggal Diinginkan', 'transaction_amount' => 'Nilai Transaksi', 'referral_code' => 'Kode Referral',
        'cancelled_at' => 'Dibatalkan Pada', 'completed_at' => 'Selesai Pada', 'refunded_at' => 'Direfund Pada',
        'payment_account_id' => 'Rekening Kas/Bank', 'from_account_id' => 'Dari Rekening', 'to_account_id' => 'Ke Rekening',
        'counted_amount' => 'Kas Terhitung', 'system_balance' => 'Saldo Sistem', 'difference' => 'Selisih', 'transfer_date' => 'Tanggal Pemindahan',
        'finance_category_id' => 'Kategori Keuangan', 'transaction_date' => 'Tanggal Transaksi', 'created_by' => 'Dibuat Oleh',
        'approved_by' => 'Disetujui Oleh', 'manager_approved_by' => 'Disetujui Manajer', 'direksi_approved_by' => 'Disetujui Direksi',
        'rejected_by' => 'Ditolak Oleh', 'rejection_note' => 'Catatan Penolakan', 'total' => 'Total', 'total_value' => 'Total Nilai',
        'debit' => 'Debit', 'credit' => 'Kredit', 'entry_date' => 'Tanggal Jurnal', 'posted_by' => 'Diposting Oleh', 'paid_by' => 'Dibayar Oleh',
        'start_date' => 'Tanggal Mulai', 'end_date' => 'Tanggal Selesai', 'base_salary' => 'Gaji Pokok', 'min_salary' => 'Gaji Minimum',
        'max_salary' => 'Gaji Maksimum', 'net_pay' => 'Gaji Bersih', 'total_deduction' => 'Total Potongan', 'menu_access' => 'Akses Menu',
        'menu_permissions' => 'Izin per Menu', 'email' => 'Email', 'city' => 'Kota', 'capacity' => 'Kapasitas', 'sku' => 'SKU',
        'slug' => 'Slug', 'excerpt' => 'Ringkasan', 'published_at' => 'Dipublikasikan Pada', 'points' => 'Poin', 'points_balance' => 'Saldo Poin',
        'points_cost' => 'Harga (poin)', 'stock' => 'Stok', 'starts_at' => 'Mulai', 'ends_at' => 'Berakhir', 'starts_on' => 'Mulai',
        'ends_on' => 'Berakhir', 'attendance_radius_meters' => 'Radius Absen (meter)', 'late_tolerance_minutes' => 'Toleransi Terlambat (menit)',
        'install_capacity_per_day' => 'Kapasitas Instalasi per Hari', 'is_default' => 'Bawaan', 'is_featured' => 'Unggulan',
        'is_required' => 'Wajib', 'is_recurring' => 'Berulang', 'is_cash' => 'Kas', 'is_postable' => 'Bisa Diposting', 'is_contra' => 'Akun Kontra',
        'invoice_number' => 'Nomor Invoice', 'spk_number' => 'Nomor SPK', 'transfer_number' => 'Nomor Pemindahan', 'refund_number' => 'Nomor Refund',
        'receipt_number' => 'Nomor Kuitansi', 'transaction_number' => 'Nomor Transaksi', 'opname_number' => 'Nomor Opname',
        'memo_number' => 'Nomor Memo', 'warranty_id' => 'Garansi', 'scroll_code_id' => 'Kode Gulungan', 'vehicle_id' => 'Kendaraan',
        'shift_id' => 'Shift', 'work_schedule_id' => 'Jadwal Kerja', 'effective_to' => 'Berlaku Sampai', 'days' => 'Hari',
        'total_length_meters' => 'Panjang Total (m)', 'remaining_length_meters' => 'Sisa Panjang (m)', 'max_usage' => 'Pemakaian Maksimum',
        'closed_at' => 'Ditutup Pada', 'net_income' => 'Laba Bersih', 'year' => 'Tahun',
    ];

    /** Kolom yang nilainya rupiah. */
    private const MONEY_KEYS = [
        'amount', 'amount_paid', 'discount_amount', 'voucher_discount', 'purchase_cost', 'unit_cost', 'price', 'base_price',
        'commission_amount', 'total_commission', 'transaction_amount', 'counted_amount', 'system_balance', 'difference',
        'min_purchase_amount', 'late_deduction_amount', 'late_deduction_per_block', 'alpha_fine_per_day', 'net_pay',
        'total_deduction', 'alpha_deduction', 'min_salary', 'max_salary', 'base_salary', 'total_value', 'debit', 'credit', 'net_income',
    ];

    /** Kolom kunci asing => [kelas model, kolom nama]. */
    private const REFERENCES = [
        'store_id' => [\App\Models\Store::class, 'name'],
        'user_id' => [\App\Models\User::class, 'name'], 'created_by' => [\App\Models\User::class, 'name'],
        'reviewed_by' => [\App\Models\User::class, 'name'], 'decided_by' => [\App\Models\User::class, 'name'],
        'approved_by' => [\App\Models\User::class, 'name'], 'closed_by' => [\App\Models\User::class, 'name'],
        'posted_by' => [\App\Models\User::class, 'name'], 'paid_by' => [\App\Models\User::class, 'name'],
        'rejected_by' => [\App\Models\User::class, 'name'], 'manager_approved_by' => [\App\Models\User::class, 'name'],
        'direksi_approved_by' => [\App\Models\User::class, 'name'], 'assigned_to' => [\App\Models\User::class, 'name'],
        'revoked_by' => [\App\Models\User::class, 'name'], 'payment_requested_by' => [\App\Models\User::class, 'name'],
        'followed_up_by' => [\App\Models\User::class, 'name'],
        'customer_id' => [\App\Models\Customer::class, 'name'], 'referred_by_customer_id' => [\App\Models\Customer::class, 'name'],
        'partner_id' => [\App\Models\Partner::class, 'business_name'], 'film_product_id' => [\App\Models\FilmProduct::class, 'name'],
        'supplier_id' => [\App\Models\Supplier::class, 'name'], 'booking_id' => [\App\Models\Booking::class, 'booking_number'],
        'customer_group_id' => [\App\Models\CustomerGroup::class, 'name'], 'shift_id' => [\App\Models\Shift::class, 'name'],
        'work_schedule_id' => [\App\Models\WorkSchedule::class, 'name'], 'payment_account_id' => [\App\Models\PaymentAccount::class, 'name'],
        'from_account_id' => [\App\Models\PaymentAccount::class, 'name'], 'to_account_id' => [\App\Models\PaymentAccount::class, 'name'],
        'finance_category_id' => [\App\Models\FinanceCategory::class, 'name'], 'ppe_type_id' => [\App\Models\PpeType::class, 'name'],
        'material_category_id' => [\App\Models\MaterialCategory::class, 'name'],
        'payroll_item_category_id' => [\App\Models\PayrollItemCategory::class, 'name'],
        'chart_of_account_id' => [\App\Models\ChartOfAccount::class, 'name'],
    ];

    private const STATUS_LABELS = [
        'pending' => 'Menunggu', 'confirmed' => 'Dikonfirmasi', 'in_progress' => 'Dikerjakan', 'completed' => 'Selesai',
        'cancelled' => 'Dibatalkan', 'canceled' => 'Dibatalkan', 'approved' => 'Disetujui', 'rejected' => 'Ditolak',
        'active' => 'Aktif', 'inactive' => 'Nonaktif', 'paid' => 'Lunas', 'unpaid' => 'Belum Dibayar', 'partial' => 'Sebagian',
        'draft' => 'Draf', 'posted' => 'Diposting', 'open' => 'Terbuka', 'closed' => 'Ditutup', 'voided' => 'Dibatalkan',
        'sent' => 'Terkirim', 'failed' => 'Gagal', 'new' => 'Baru', 'contacted' => 'Sudah Dihubungi', 'expired' => 'Kedaluwarsa',
    ];

    private const EVENT_LABELS = [
        'created' => 'Dibuat', 'updated' => 'Diubah', 'deleted' => 'Dihapus', 'restored' => 'Dipulihkan',
        'login' => 'Masuk', 'login_failed' => 'Gagal Masuk', 'logout' => 'Keluar',
        'password_changed' => 'Sandi Diganti', 'password_reset' => 'Sandi Direset',
    ];

    private const EVENT_VERBS = ['created' => 'dibuat', 'updated' => 'diubah', 'deleted' => 'dihapus', 'restored' => 'dipulihkan'];

    /** @var array<string, string|null> */
    private static array $referenceCache = [];

    /**
     * class_exists yang kebal terhadap autoloader teroptimasi yang masih mencatat berkas yang sudah dihapus (mis. RollScrapPool):
     * include berkas hilang itu melempar ErrorException, yang di sini dianggap "kelas tidak ada".
     */
    public static function classExists(string $class): bool
    {
        try {
            return class_exists($class);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Kosongkan cache nama rujukan (dipakai tes; dalam satu permintaan cache cukup untuk menghindari query berulang). */
    public static function flush(): void
    {
        self::$referenceCache = [];
    }

    // ------------------------------------------------------------- kolom dan nilai

    public static function label(string $key): string
    {
        if (isset(self::KEY_LABELS[$key])) {
            return self::KEY_LABELS[$key];
        }

        $base = Str::endsWith($key, '_id') ? Str::beforeLast($key, '_id') : $key;

        return Str::of($base)->replace('_', ' ')->title()->toString();
    }

    public static function value(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_bool($value) || (preg_match('/^(is|has|requires|deducts|tracks)_/', $key) && in_array($value, [0, 1, '0', '1'], true))) {
            return $value ? 'ya' : 'tidak';
        }

        if (isset(self::REFERENCES[$key]) && is_numeric($value)) {
            return self::reference($key, (int) $value);
        }

        if (in_array($key, self::MONEY_KEYS, true) && is_numeric($value)) {
            return 'Rp ' . number_format((float) $value, 0, ',', '.');
        }

        if (in_array($key, ['status', 'review_status'], true) && is_string($value) && isset(self::STATUS_LABELS[$value])) {
            return self::STATUS_LABELS[$value];
        }

        if (is_string($value) && ($formatted = self::date($value)) !== null) {
            return $formatted;
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  mixed  $values  larik kolom => nilai (properties.old / properties.attributes)
     * @return array<string, string> label Indonesia => nilai terbaca
     */
    public static function rows(mixed $values): array
    {
        $rows = [];

        foreach (is_array($values) ? $values : [] as $key => $value) {
            $rows[self::label((string) $key)] = self::value((string) $key, $value);
        }

        return $rows;
    }

    /** Tanggal ISO atau Y-m-d menjadi teks WIB; tanggal tanpa jam (tengah malam di zona aplikasi) tampil tanpa jam. */
    private static function date(string $value): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/', $value)) {
            return null;
        }

        try {
            $at = Carbon::parse($value, config('app.timezone'))->setTimezone(config('app.timezone'));

            if (strlen($value) === 10 || $at->format('H:i:s') === '00:00:00') {
                return $at->format('d M Y');
            }

            return $at->setTimezone(self::TIMEZONE)->format('d M Y H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function reference(string $key, int $id): string
    {
        [$class, $column] = self::REFERENCES[$key];
        $cacheKey = "{$class}:{$id}";

        if (! array_key_exists($cacheKey, self::$referenceCache)) {
            self::$referenceCache[$cacheKey] = null;

            try {
                $model = self::find($class, $id);
                $name = $model?->getAttribute($column);
                self::$referenceCache[$cacheKey] = $model ? ($name !== null && $name !== '' ? (string) $name : ($class === \App\Models\Customer::class ? 'Pelanggan Terhapus' : "#{$id}")) : null;
            } catch (\Throwable) {
                self::$referenceCache[$cacheKey] = null;
            }
        }

        return self::$referenceCache[$cacheKey] ?? "#{$id} (sudah dihapus)";
    }

    /** Muat model tanpa scope global dan termasuk yang terhapus lunak; null bila kelas atau baris tidak ada. */
    private static function find(string $class, int|string $id): ?Model
    {
        if (! self::classExists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        $query = $class::withoutGlobalScopes();

        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($class), true)) {
            $query = $query->withTrashed();
        }

        return $query->find($id);
    }

    /** Label jenis aksi; catatan manual tanpa jenis aksi disebut "Dicatat". */
    public static function eventLabel(?string $event): string
    {
        return $event === null || $event === '' ? 'Dicatat' : (self::EVENT_LABELS[$event] ?? $event);
    }

    public static function sourceLabel(?string $source): string
    {
        return $source ? (ActivityContext::SOURCE_LABELS[$source] ?? $source) : '—';
    }

    // ------------------------------------------------------------- objek dan deskripsi

    public static function subjectLabel(Activity $activity): string
    {
        $type = $activity->subject_type;

        if (! $type) {
            return '—';
        }

        $resource = self::classExists($type) ? Filament::getModelResource($type) : null;
        $label = $resource ? $resource::getModelLabel() : class_basename($type);

        return $label . ' #' . $activity->subject_id;
    }

    /** Tautan ke halaman rekaman bila resource-nya punya halaman lihat/ubah, rekamannya masih ada, dan pengguna boleh membukanya. */
    public static function subjectUrl(Activity $activity): ?string
    {
        $type = $activity->subject_type;

        if (! $type || ! $activity->subject_id || ! self::classExists($type)) {
            return null;
        }

        try {
            $resource = Filament::getModelResource($type);
            $subject = $resource ? self::find($type, $activity->subject_id) : null;

            if (! $subject || ($subject->deleted_at ?? null)) {
                return null;
            }

            if ($resource::hasPage('view') && $resource::canView($subject)) {
                return $resource::getUrl('view', ['record' => $subject]);
            }

            if ($resource::hasPage('edit') && $resource::canEdit($subject)) {
                return $resource::getUrl('edit', ['record' => $subject]);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /** Kalimat utuh; deskripsi bawaan paket ("updated") diganti "Booking #12 diubah (Status, Toko)". */
    public static function describe(Activity $activity): string
    {
        $description = trim((string) $activity->description);
        $event = (string) $activity->event;

        if ($description !== '' && strcasecmp($description, $event) !== 0 && ! isset(self::EVENT_VERBS[strtolower($description)])) {
            return $description;
        }

        $verb = self::EVENT_VERBS[strtolower($description ?: $event)] ?? ($description ?: $event);
        $fields = '';

        if ($event === 'updated') {
            $keys = array_keys((array) ($activity->properties['attributes'] ?? []));
            $labels = array_map([self::class, 'label'], array_slice($keys, 0, 4));

            if ($labels !== []) {
                $fields = ' (' . implode(', ', $labels) . (count($keys) > 4 ? ', …' : '') . ')';
            }
        }

        return trim(self::subjectLabel($activity) . ' ' . $verb . $fields);
    }

    /** Baris kedua di bawah nama pelaku: email staf, atau jenis pelaku bila bukan staf. */
    public static function actorNote(Activity $activity): ?string
    {
        if (! $activity->causer_type) {
            return null;
        }

        if ($activity->causer_type === \App\Models\User::class) {
            return $activity->causer?->email;
        }

        return match (class_basename($activity->causer_type)) {
            'Customer' => 'Pelanggan',
            'Partner' => 'Partner',
            default => class_basename($activity->causer_type),
        };
    }
}
