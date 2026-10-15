<?php

namespace App\Console\Commands;

use App\Filament\Resources\PartnershipInquiryResource;
use App\Filament\Resources\ProductInquiryResource;
use App\Models\PartnershipInquiry;
use App\Models\ProductInquiry;
use App\Models\User;
use App\Services\PushNotificationService;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Pengingat lead telat (Marketing/Konten Fase 1, 2026-10-23): inquiry produk dan kemitraan yang masih berstatus 'new' dan belum pernah
 * ditindak (contacted_at kosong) melewati target respons (config marketing.lead_response_sla_hours). Quotation sudah punya pengingatnya
 * sendiri (quotations:notify-stale), jadi tidak diulang di sini.
 *
 * Penerima: pemilik lead (owner_user_id) bila ada; lead tanpa pemilik diingatkan ke semua staf yang punya akses menu terkait.
 * Seperti NotifyStaleQuotations, sengaja berulang tiap hari sampai lead ditindak (bukan Acknowledgeable).
 */
class RemindStaleLeads extends Command
{
    protected $signature = 'leads:remind-stale';

    protected $description = 'Ingatkan pemilik/staf soal inquiry produk dan kemitraan yang belum ditindak melewati target respons';

    public function handle(): int
    {
        $sla = (int) config('marketing.lead_response_sla_hours', 24);
        $cutoff = now()->subHours($sla);

        $types = [
            ['label' => 'inquiry produk', 'title' => 'Inquiry Produk', 'resource' => ProductInquiryResource::class, 'leads' => ProductInquiry::class, 'route' => '/staff'],
            ['label' => 'kemitraan', 'title' => 'Kemitraan', 'resource' => PartnershipInquiryResource::class, 'leads' => PartnershipInquiry::class, 'route' => '/staff'],
        ];

        $sent = 0;

        foreach ($types as $type) {
            $stale = $type['leads']::query()
                ->where('status', 'new')
                ->whereNull('contacted_at')
                ->where('created_at', '<=', $cutoff)
                ->get(['id', 'owner_user_id']);

            if ($stale->isEmpty()) {
                continue;
            }

            $byOwner = $stale->groupBy(fn ($lead) => $lead->owner_user_id ?: 0);

            // Pemilik menerima hitungan lead miliknya; lead tanpa pemilik dikirim ke seluruh staf bermenu.
            $fallback = $byOwner->has(0)
                ? User::query()->where('is_active', true)->get()->filter(fn (User $u) => $u->canAccessStaffArea() && $u->hasMenuAccess($type['resource']))
                : collect();

            $recipients = $this->recipientCounts($byOwner, $fallback);

            foreach ($recipients as $userId => $count) {
                $user = User::find($userId);

                if (! $user) {
                    continue;
                }

                $body = "{$count} {$type['label']} belum ditindak lebih dari {$sla} jam.";

                Notification::make()
                    ->title("Lead {$type['title']} Belum Ditindak")
                    ->body($body)
                    ->warning()
                    ->actions([
                        Action::make('view')
                            ->label('Lihat')
                            ->url($type['resource']::getUrl('index', ['tableFilters' => ['status' => ['value' => 'new']]]))
                            ->markAsRead(),
                    ])
                    ->sendToDatabase($user);

                app(PushNotificationService::class)->sendToUsers(
                    [$userId],
                    "Lead {$type['title']} Belum Ditindak",
                    $body,
                    ['type' => 'lead_stale', 'route' => $type['route']],
                );

                $sent++;
            }
        }

        $this->info($sent > 0 ? "Pengingat terkirim ke {$sent} penerima." : 'Tidak ada lead yang telat ditindak.');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int|string, Collection>  $byOwner  lead telat dikelompokkan per pemilik (0 = tanpa pemilik)
     * @param  Collection<int, User>  $fallback  penerima untuk lead tanpa pemilik
     * @return array<int, int> user_id => jumlah lead yang diingatkan
     */
    private function recipientCounts(Collection $byOwner, Collection $fallback): array
    {
        $counts = [];

        foreach ($byOwner as $ownerId => $leads) {
            if ($ownerId) {
                $counts[(int) $ownerId] = ($counts[(int) $ownerId] ?? 0) + $leads->count();

                continue;
            }

            foreach ($fallback as $user) {
                $counts[$user->id] = ($counts[$user->id] ?? 0) + $leads->count();
            }
        }

        return $counts;
    }
}
