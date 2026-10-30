<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ContractExtension extends Model
{
    use LogsActivity;

    protected $fillable = [
        'user_id',
        'previous_end_date',
        'new_end_date',
        'notes',
        'extended_by',
    ];

    protected $casts = [
        'previous_end_date' => 'date',
        'new_end_date'      => 'date',
    ];

    /** Tidak disimpan: diisi recordExtension() bila perpanjangan ini menghidupkan kembali akun yang dinonaktifkan otomatis. */
    public bool $reactivatedUser = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function extender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'extended_by');
    }

    /**
     * Catat perpanjangan SEKALIGUS sinkronkan users.contract_end_date —
     * dibungkus transaction+lock supaya previous_end_date yang tersimpan
     * selalu akurat (snapshot nilai SEBELUM diubah), sama pola dengan
     * RawMaterial::recordMovement() menyimpan harga batch.
     *
     * Bug diperbaiki 2026-09-27 (audit Perpanjang Kontrak) -- guard
     * $newEndDate harus LEBIH BARU dari contract_end_date yang sekarang
     * berlaku ditaruh DI SINI juga (bukan cuma minDate() di form Filament)
     * supaya invariant "perpanjangan" tidak bisa dilanggar lewat jalur
     * mana pun yang memanggil method ini, konsisten dengan snapshot
     * previous_end_date yang diambil dari baris yang SUDAH dikunci.
     *
     * @throws \InvalidArgumentException kalau $newEndDate <= contract_end_date karyawan saat ini.
     */
    public static function recordExtension(User $user, string $newEndDate, ?int $extendedBy, ?string $notes = null): self
    {
        return DB::transaction(function () use ($user, $newEndDate, $extendedBy, $notes) {
            $freshUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            // PKWT paling lama 5 tahun termasuk perpanjangan (PP 35/2021); batas bisa diubah di config/hr.php.
            $maxYears = (int) config('hr.pkwt_max_years', 5);
            if ($freshUser->join_date && Carbon::parse($newEndDate)->gt($freshUser->join_date->copy()->addYears($maxYears))) {
                throw new \InvalidArgumentException(
                    "Kontrak PKWT paling lama {$maxYears} tahun termasuk perpanjangan. Tanggal {$newEndDate} melewati {$freshUser->join_date->copy()->addYears($maxYears)->format('d M Y')}; pertimbangkan pengangkatan menjadi karyawan tetap."
                );
            }

            $currentEnd = $freshUser->contract_end_date;
            if ($currentEnd && Carbon::parse($newEndDate)->lte($currentEnd)) {
                throw new \InvalidArgumentException(
                    "Tanggal berakhir baru ({$newEndDate}) harus lebih baru dari tanggal akhir kontrak {$freshUser->name} yang sekarang berlaku ({$currentEnd->format('d M Y')})."
                );
            }

            // Perpanjangan sering baru dicatat sesudah tanggal lama lewat; tanggal baru tetap harus belum lewat, kalau tidak kontrak itu
            // langsung berakhir lagi dan dinonaktifkan keesokan paginya.
            if (Carbon::parse($newEndDate)->lt(today())) {
                throw new \InvalidArgumentException("Tanggal berakhir baru ({$newEndDate}) sudah lewat; isi tanggal hari ini atau sesudahnya.");
            }

            // Kontrak yang sempat lewat dan membuat akun dinonaktifkan otomatis (Hari Kerja Terakhir kosong atau sama dengan akhir kontrak
            // lama) diaktifkan kembali. Akun yang berhenti karena alasan lain (Hari Kerja Terakhir lain, atau kontrak belum lewat) tidak disentuh.
            $reactivate = self::wasDeactivatedByLapse($freshUser);

            $extension = self::create([
                'user_id'            => $freshUser->id,
                'previous_end_date'  => $freshUser->contract_end_date,
                'new_end_date'       => $newEndDate,
                'notes'              => $notes,
                'extended_by'        => $extendedBy,
            ]);

            $freshUser->update($reactivate
                ? ['contract_end_date' => $newEndDate, 'is_active' => true, 'last_work_date' => null]
                : ['contract_end_date' => $newEndDate]);
            $extension->reactivatedUser = $reactivate;

            return $extension;
        });
    }

    /**
     * Kontrak pertama yang diisi langsung di form User (tanggal akhir kontrak terisi, riwayat masih kosong) dicatat sebagai baris riwayat
     * pertama supaya riwayat lengkap sejak awal. Tidak mengubah karyawan; idempoten (sekali ada riwayat, tidak mencatat lagi).
     */
    public static function recordFirstContractIfMissing(User $user, ?int $by = null): ?self
    {
        $user->loadMissing('employeeType');

        if (! $user->contract_end_date || ! $user->employeeType?->has_end_date || $user->contractExtensions()->exists()) {
            return null;
        }

        return self::create([
            'user_id' => $user->id,
            'previous_end_date' => null,
            'new_end_date' => $user->contract_end_date->toDateString(),
            'notes' => 'Kontrak pertama (dari data karyawan)',
            'extended_by' => $by,
        ]);
    }

    /** Akun yang dinonaktifkan otomatis karena kontraknya lewat (Hari Kerja Terakhir kosong atau sama dengan akhir kontrak). */
    public static function wasDeactivatedByLapse(User $user): bool
    {
        return ! $user->is_active
            && $user->contract_end_date
            && $user->contract_end_date->lt(today())
            && (! $user->last_work_date || $user->last_work_date->isSameDay($user->contract_end_date));
    }

    /**
     * Karyawan yang boleh diperpanjang kontraknya atau diangkat jadi tetap (audit Riwayat Kontrak, 2026-10-31): aktif dan bertipe kontrak,
     * atau yang sempat dinonaktifkan otomatis karena kontraknya lewat. Bukan partner; akun non-penuh hanya karyawan tokonya sendiri.
     */
    public static function eligibleEmployees(?User $actor = null): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
            ->whereHas('employeeType', fn ($q) => $q->where('has_end_date', true))
            ->where(fn ($q) => $q->where('is_active', true)
                ->orWhere(fn ($lapsed) => $lapsed->where('is_active', false)
                    ->whereNotNull('contract_end_date')
                    ->whereDate('contract_end_date', '<', today())
                    ->where(fn ($day) => $day->whereNull('last_work_date')->orWhereColumn('last_work_date', 'contract_end_date'))))
            ->when($actor && ! $actor->isFullAccess(), fn ($q) => $q->where('store_id', $actor->store_id));
    }

    /**
     * Angkat karyawan kontrak menjadi karyawan tetap: ganti tipe, kosongkan tanggal akhir kontrak (supaya tidak lagi diingatkan atau
     * dinonaktifkan otomatis), dan aktifkan kembali akun yang sempat mati karena kontrak lewat. Dicatat di Histori Aktivitas.
     *
     * @throws \InvalidArgumentException kalau tipe baru masih bertanggal akhir, atau karyawannya bukan karyawan kontrak.
     */
    public static function convertToPermanent(User $user, EmployeeType $type, ?int $by, ?string $notes = null): User
    {
        if ($type->has_end_date || ! $type->is_active) {
            throw new \InvalidArgumentException('Pilih tipe karyawan tetap yang aktif (tanpa tanggal berakhir kontrak).');
        }

        return DB::transaction(function () use ($user, $type, $by, $notes) {
            $fresh = User::where('id', $user->id)->lockForUpdate()->with('employeeType')->firstOrFail();

            if (! $fresh->employeeType?->has_end_date) {
                throw new \InvalidArgumentException("{$fresh->name} bukan karyawan kontrak.");
            }

            $reactivate = self::wasDeactivatedByLapse($fresh);

            if (! $fresh->is_active && ! $reactivate) {
                throw new \InvalidArgumentException("Akun {$fresh->name} nonaktif; aktifkan dulu lewat menu User bila memang masih bekerja.");
            }

            $previousType = $fresh->employeeType->name;
            $previousEnd = $fresh->contract_end_date?->toDateString();

            $fresh->update(['employee_type_id' => $type->id, 'contract_end_date' => null] + ($reactivate ? ['is_active' => true, 'last_work_date' => null] : []));

            activity('contract_extension')
                ->performedOn($fresh)
                ->causedBy($by ? User::find($by) : null)
                ->withProperties(['previous_type' => $previousType, 'new_type' => $type->name, 'previous_end_date' => $previousEnd, 'notes' => $notes, 'reactivated' => $reactivate])
                ->log("{$fresh->name} diangkat menjadi karyawan tetap ({$type->name}), sebelumnya {$previousType}" . ($previousEnd ? " (kontrak sampai {$previousEnd})" : ''));

            return $fresh;
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['new_end_date', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('contract_extension')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => $this->previous_end_date
                    ? "Kontrak {$this->user?->name} diperpanjang sampai {$this->new_end_date?->format('d M Y')}"
                    : "Kontrak pertama {$this->user?->name} tercatat sampai {$this->new_end_date?->format('d M Y')}",
                'deleted' => "Riwayat perpanjangan kontrak {$this->user?->name} dihapus",
                default   => "Perpanjangan kontrak {$this->user?->name} — {$eventName}",
            });
    }
}
