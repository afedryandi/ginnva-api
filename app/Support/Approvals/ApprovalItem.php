<?php

namespace App\Support\Approvals;

use Illuminate\Support\Carbon;

/**
 * Satu pengajuan yang menunggu keputusan, dalam bentuk seragam untuk Pusat Persetujuan (2026-11-13).
 */
final class ApprovalItem
{
    /**
     * @param  array<string, mixed>  $extra  data tambahan untuk formulir persetujuan (mis. jumlah diminta, jam dinas)
     */
    public function __construct(
        public readonly string $source,
        public readonly int $id,
        public readonly string $typeLabel,
        public readonly string $icon,
        public readonly string $who,
        public readonly string $summary,
        public readonly ?string $store,
        public readonly ?Carbon $submittedAt,
        public readonly ?string $url,
        public readonly bool $inline,
        public readonly array $extra = [],
    ) {
    }

    /** Sudah menunggu lebih dari sehari: ditandai supaya tidak terlewat. */
    public function isStale(): bool
    {
        return $this->submittedAt !== null && $this->submittedAt->lt(now()->subDay());
    }
}
