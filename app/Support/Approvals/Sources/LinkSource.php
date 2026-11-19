<?php

namespace App\Support\Approvals\Sources;

use App\Models\User;
use Closure;
use Illuminate\Support\Collection;

/**
 * Sumber yang hanya berupa tautan: pengajuan tampil di Pusat Persetujuan, tetapi keputusannya diambil di menunya sendiri (tombol Buka).
 * Dipakai untuk jenis yang keputusannya butuh isian atau pemeriksaan khusus (garansi, pendaftaran installer, reward, pembatalan dan
 * jadwal ulang booking pelanggan).
 */
class LinkSource extends BaseSource
{
    /** Pengguna yang sedang dibangunkan daftarnya: dipakai makeUrl() untuk menyembunyikan tautan bagi yang tidak punya akses menu aslinya. */
    private ?User $viewer = null;

    /**
     * @param  Closure(User, LinkSource): Collection<int, \App\Support\Approvals\ApprovalItem>  $items
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $icon,
        private readonly Closure $items,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function icon(): string
    {
        return $this->icon;
    }

    public function items(User $user): Collection
    {
        $this->viewer = $user;

        return ($this->items)($user, $this)->values();
    }

    /** Dibuka ke pembangun satuan supaya penutup di registri bisa memakai pembantu yang sama. */
    public function makeUrl(string $resource, mixed $record = null): ?string
    {
        // Yang hanya melihat ringkasan (tingkat "lihat saja", tanpa akses menu aslinya) tidak diberi tautan.
        if ($this->viewer !== null && ! $this->canOpenMenu($this->viewer, $resource)) {
            return null;
        }

        return $this->url($resource, $record);
    }

    public function visibleTo(User $user, string $resource, ?int $storeId = null): bool
    {
        // Tingkat "lihat saja" melihat ringkasan semua jenis (di tokonya sendiri bila pengajuannya punya toko), walau menu aslinya tidak dicentang.
        $mayGlance = app(\App\Services\ApprovalCenterService::class)->level($user) === \App\Services\ApprovalCenterService::LEVEL_VIEW;

        return ($this->canOpenMenu($user, $resource) || $mayGlance) && ($storeId === null || $this->inStore($user, $storeId));
    }
}
