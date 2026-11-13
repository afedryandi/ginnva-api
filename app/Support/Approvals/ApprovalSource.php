<?php

namespace App\Support\Approvals;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Satu jenis pengajuan di Pusat Persetujuan. Sumber "inline" bisa diputuskan langsung dari Pusat Persetujuan lewat layanan keputusan yang
 * sama dengan menu aslinya; sumber "tautan" hanya menampilkan daftar dan tombol Buka ke menu aslinya.
 */
interface ApprovalSource
{
    /** Kunci unik, dipakai sebagai argumen tombol dan filter. */
    public function key(): string;

    /** Nama jenis untuk filter dan baris, mis. "Lembur". */
    public function label(): string;

    public function icon(): string;

    /** Apakah Setujui/Tolak bisa dilakukan langsung dari Pusat Persetujuan. */
    public function inline(): bool;

    /** Pengajuan menunggu yang BOLEH diputuskan (atau, untuk sumber tautan, dilihat) oleh pengguna ini, yang terlama dulu. @return Collection<int, ApprovalItem> */
    public function items(User $user): Collection;

    /**
     * @param  array<string, mixed>  $data  isian tambahan dari formulir (mis. quantity_approved, start_time, end_time)
     * @return string pesan keberhasilan
     *
     * @throws \RuntimeException bila sudah diputuskan, tidak berwenang, atau aturan lain menolak.
     */
    public function approve(int $id, User $by, array $data = []): string;

    /**
     * @return string pesan keberhasilan
     *
     * @throws \RuntimeException
     */
    public function reject(int $id, User $by, string $note): string;
}
