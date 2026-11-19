<?php

namespace App\Support\Approvals\Sources;

use App\Models\User;
use App\Support\Approvals\ApprovalItem;
use App\Support\Approvals\ApprovalSource;
use Filament\Resources\Resource;
use Illuminate\Support\Collection;

/**
 * Dasar sumber Pusat Persetujuan: alamat halaman asli, dan perilaku bawaan untuk sumber yang hanya berupa tautan.
 */
abstract class BaseSource implements ApprovalSource
{
    private const PANEL = 'admin';

    public function inline(): bool
    {
        return false;
    }

    public function approve(int $id, User $by, array $data = []): string
    {
        throw new \RuntimeException('Jenis pengajuan ini diputuskan di menunya sendiri. Tekan Buka.');
    }

    public function reject(int $id, User $by, string $note): string
    {
        throw new \RuntimeException('Jenis pengajuan ini diputuskan di menunya sendiri. Tekan Buka.');
    }

    /** Alamat halaman resource (lihat satu baris bila bisa, selain itu daftarnya), atau null bila tidak bisa dibentuk. */
    protected function url(string $resource, mixed $record = null): ?string
    {
        if (! is_subclass_of($resource, Resource::class)) {
            return null;
        }

        try {
            if ($record !== null) {
                $pages = $resource::getPages();

                foreach (['view', 'edit'] as $page) {
                    if (isset($pages[$page])) {
                        return $resource::getUrl($page, ['record' => $record], panel: self::PANEL);
                    }
                }
            }

            return $resource::getUrl('index', panel: self::PANEL);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Pengguna ini boleh melihat data toko tersebut? Akses penuh semua toko; lainnya hanya tokonya sendiri. */
    protected function inStore(User $user, ?int $storeId): bool
    {
        return $user->isFullAccess() || ($user->store_id !== null && (int) $user->store_id === (int) $storeId);
    }

    /**
     * Tingkat "lihat saja" di Pusat Persetujuan: boleh melihat ringkasan pengajuan karyawan tokonya sendiri tanpa harus bisa memutuskannya
     * (tanpa aksi Ubah di menu aslinya dan bukan store manager). Pengajuan miliknya sendiri tidak ikut. Yang bertingkat memutuskan tetap
     * memakai aturan memutuskan masing-masing jenis.
     */
    protected function canGlance(User $user, ?int $storeId, ?int $ownerId = null): bool
    {
        if (app(\App\Services\ApprovalCenterService::class)->level($user) !== \App\Services\ApprovalCenterService::LEVEL_VIEW) {
            return false;
        }

        return ($ownerId === null || (int) $ownerId !== (int) $user->id) && $this->inStore($user, $storeId);
    }

    /** Alamat halaman asli sebuah pengajuan, hanya bagi yang boleh membuka menunya (yang lain cukup melihat ringkasan di Pusat Persetujuan). */
    protected function urlFor(User $user, string $resource, mixed $record = null): ?string
    {
        return $this->canOpenMenu($user, $resource) ? $this->url($resource, $record) : null;
    }

    /** Menu ini terbuka bagi pengguna (akses penuh, atau dicentang di Hak Akses). */
    protected function canOpenMenu(User $user, string $resource): bool
    {
        return $user->isFullAccess() || $user->hasMenuAccess($resource);
    }

    /** @return Collection<int, ApprovalItem> */
    /** Pengajuan keuangan hanya diputuskan akses penuh (direksi); yang lain hanya melihat. */
    protected function requireFullAccess(User $user): void
    {
        if (! $user->isFullAccess()) {
            throw new \RuntimeException('Pengajuan keuangan hanya bisa diputuskan oleh akses penuh (direksi).');
        }
    }

    protected function none(): Collection
    {
        return collect();
    }
}
