<?php

namespace App\Services;

use App\Filament\Resources\BookingResource;
use App\Filament\Resources\RewardRedemptionResource;
use App\Filament\Resources\TechnicianResource;
use App\Filament\Resources\WarrantyResource;
use App\Models\BookingCancellationRequest;
use App\Models\BookingRescheduleRequest;
use App\Models\RewardRedemption;
use App\Models\Technician;
use App\Models\User;
use App\Models\Warranty;
use App\Support\Approvals\ApprovalItem;
use App\Support\Approvals\ApprovalSource;
use App\Support\Approvals\Sources\CorrectionSource;
use App\Support\Approvals\Sources\FieldDutySource;
use App\Support\Approvals\Sources\LeaveSource;
use App\Support\Approvals\Sources\LinkSource;
use App\Support\Approvals\Sources\OvertimeSource;
use App\Support\Approvals\Sources\PurchaseRequestSource;
use App\Support\Approvals\Sources\TransactionSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Pusat Persetujuan (2026-11-13): satu tempat untuk semua pengajuan yang menunggu keputusan, supaya direksi tidak perlu membuka
 * menu satu per satu. Setiap jenis adalah sebuah ApprovalSource; enam jenis bisa diputuskan langsung (memakai layanan keputusan yang
 * sama dengan menunya), sisanya berupa tautan ke menu aslinya. Tiap pengguna hanya melihat yang berhak ia putuskan.
 */
class ApprovalCenterService
{
    /** Hitungan untuk lencana menu disimpan sebentar supaya tidak menjalankan belasan pencarian di setiap halaman panel. */
    private const COUNT_TTL_SECONDS = 30;

    /** @return array<string, ApprovalSource> dikunci menurut key() */
    public function sources(): array
    {
        $sources = [
            new OvertimeSource(),
            new FieldDutySource(),
            new LeaveSource(),
            new CorrectionSource(),
            new PurchaseRequestSource(),
            new TransactionSource(),
            $this->warrantySource(),
            $this->technicianSource(),
            $this->rewardSource(),
            $this->cancellationSource(),
            $this->rescheduleSource(),
        ];

        $keyed = [];
        foreach ($sources as $source) {
            $keyed[$source->key()] = $source;
        }

        return $keyed;
    }

    public function source(string $key): ?ApprovalSource
    {
        return $this->sources()[$key] ?? null;
    }

    /** Semua pengajuan menunggu milik pengguna ini, dari semua jenis; yang paling lama menunggu di atas. @return Collection<int, ApprovalItem> */
    public function items(User $user, ?string $onlySource = null): Collection
    {
        $items = collect();

        foreach ($this->sources() as $key => $source) {
            if ($onlySource !== null && $onlySource !== $key) {
                continue;
            }

            try {
                $items = $items->merge($source->items($user));
            } catch (\Throwable $e) {
                // Satu jenis yang bermasalah tidak boleh mengosongkan seluruh Pusat Persetujuan.
                report($e);
            }
        }

        return $items->sortBy(fn (ApprovalItem $i) => $i->submittedAt?->getTimestamp() ?? PHP_INT_MAX)->values();
    }

    /** Jumlah menunggu per jenis, untuk chip penyaring. @return array<string, int> */
    public function countsBySource(User $user): array
    {
        $counts = [];

        foreach ($this->items($user)->groupBy('source') as $key => $group) {
            $counts[$key] = $group->count();
        }

        return $counts;
    }

    /** Jumlah total untuk lencana menu (disimpan 30 detik). */
    public function badgeCount(User $user): int
    {
        return (int) Cache::remember("approvals.count.{$user->id}", self::COUNT_TTL_SECONDS, fn () => $this->items($user)->count());
    }

    public function forgetBadge(User $user): void
    {
        Cache::forget("approvals.count.{$user->id}");
    }

    /** Halaman Pusat Persetujuan hanya untuk yang punya hak memutuskan sesuatu: akses penuh, store manager, atau supervisor keuangan. */
    public function canUse(?User $user): bool
    {
        return $user !== null && ($user->isFullAccess() || $user->isStoreManager() || $user->hasRole('spv_finance'));
    }

    // ------------------------------------------------------------------ sumber berupa tautan

    private function warrantySource(): LinkSource
    {
        return new LinkSource('warranty', 'Garansi Baru', 'heroicon-o-shield-check', function (User $user, LinkSource $self) {
            return Warranty::withoutGlobalScopes()->with('store:id,name')->where('review_status', 'pending_review')->orderBy('created_at')->get()
                ->filter(fn (Warranty $w) => $self->visibleTo($user, WarrantyResource::class, $w->store_id ? (int) $w->store_id : null))
                ->map(fn (Warranty $w) => new ApprovalItem('warranty', $w->id, 'Garansi Baru', 'heroicon-o-shield-check', $w->customer_name ?: '—',
                    trim(($w->warranty_code ?? '') . ($w->car_plate ? " · {$w->car_plate}" : '')) ?: 'Garansi menunggu ditinjau',
                    $w->store?->name, $w->created_at, $self->makeUrl(WarrantyResource::class, $w->id), false));
        });
    }

    private function technicianSource(): LinkSource
    {
        return new LinkSource('technician', 'Pendaftaran Installer', 'heroicon-o-wrench-screwdriver', function (User $user, LinkSource $self) {
            return Technician::withoutGlobalScopes()->with('store:id,name')->where('status', 'pending_review')->orderBy('created_at')->get()
                ->filter(fn (Technician $t) => $self->visibleTo($user, TechnicianResource::class, $t->store_id ? (int) $t->store_id : null))
                ->map(fn (Technician $t) => new ApprovalItem('technician', $t->id, 'Pendaftaran Installer', 'heroicon-o-wrench-screwdriver', $t->name ?: '—',
                    'Pendaftaran installer baru menunggu ditinjau', $t->store?->name, $t->created_at, $self->makeUrl(TechnicianResource::class, $t->id), false));
        });
    }

    private function rewardSource(): LinkSource
    {
        return new LinkSource('reward', 'Penukaran Reward', 'heroicon-o-gift', function (User $user, LinkSource $self) {
            if (! $self->visibleTo($user, RewardRedemptionResource::class)) {
                return collect();
            }

            return RewardRedemption::with('reward')->where('status', 'pending')->orderBy('created_at')->get()
                ->map(function (RewardRedemption $r) use ($self) {
                    $who = null;
                    try {
                        $class = $r->redeemer_type;
                        $who = $class && class_exists($class) ? $class::find($r->redeemer_id)?->name : null;
                    } catch (\Throwable) {
                        // Pemilik penukaran tidak bisa dimuat: tampilkan tanda strip.
                    }

                    return new ApprovalItem('reward', $r->id, 'Penukaran Reward', 'heroicon-o-gift', $who ?: '—',
                        ($r->reward?->name ?? 'Reward') . ' · ' . number_format((int) $r->points_spent, 0, ',', '.') . ' poin',
                        null, $r->created_at, $self->makeUrl(RewardRedemptionResource::class, $r->id), false);
                });
        });
    }

    private function cancellationSource(): LinkSource
    {
        return new LinkSource('booking_cancellation', 'Pembatalan Booking', 'heroicon-o-x-circle', function (User $user, LinkSource $self) {
            return BookingCancellationRequest::with('booking')->where('status', 'pending')->orderBy('created_at')->get()
                ->filter(fn (BookingCancellationRequest $r) => $r->booking && $self->visibleTo($user, BookingResource::class, $r->booking->store_id ? (int) $r->booking->store_id : null))
                ->map(fn (BookingCancellationRequest $r) => new ApprovalItem('booking_cancellation', $r->id, 'Pembatalan Booking', 'heroicon-o-x-circle', $r->booking->customer_name ?: '—',
                    "Booking {$r->booking->booking_number}" . ($r->reason ? " · {$r->reason}" : ''), null, $r->created_at, $self->makeUrl(BookingResource::class, $r->booking_id), false));
        });
    }

    private function rescheduleSource(): LinkSource
    {
        return new LinkSource('booking_reschedule', 'Jadwal Ulang Booking', 'heroicon-o-calendar', function (User $user, LinkSource $self) {
            return BookingRescheduleRequest::with('booking')->where('status', 'pending')->orderBy('created_at')->get()
                ->filter(fn (BookingRescheduleRequest $r) => $r->booking && $self->visibleTo($user, BookingResource::class, $r->booking->store_id ? (int) $r->booking->store_id : null))
                ->map(fn (BookingRescheduleRequest $r) => new ApprovalItem('booking_reschedule', $r->id, 'Jadwal Ulang Booking', 'heroicon-o-calendar', $r->booking->customer_name ?: '—',
                    "Booking {$r->booking->booking_number}" . ($r->requested_date ? ' ke ' . $r->requested_date->translatedFormat('d M Y') : '') . ($r->reason ? " · {$r->reason}" : ''),
                    null, $r->created_at, $self->makeUrl(BookingResource::class, $r->booking_id), false));
        });
    }
}
