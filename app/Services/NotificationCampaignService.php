<?php

namespace App\Services;

use App\Http\Controllers\Api\NotificationController;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\DeviceToken;
use App\Models\NotificationCampaign;
use App\Models\Partner;
use App\Support\QuietHours;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Kirim Notifikasi (Notifikasi Fase 5, 2026-10-21): setiap kiriman manual menjadi satu kampanye yang tercatat beserta hasilnya,
 * bisa dijadwalkan, dan penerimanya bisa dipratinjau sebelum dikirim. Pengiriman sebenarnya tetap lewat NotificationController
 * (aturan opt-in, batas mingguan, jam tenang, dan token tidak diduplikasi di sini).
 */
class NotificationCampaignService
{
    /** Rute yang membutuhkan ID record pada deep link. */
    private const ROUTES_NEEDING_ID = ['/account/my-warranties', '/account/my-bookings'];

    /**
     * Susun data deep link dari isian form. Field ID hanya relevan untuk dua rute di atas; nilai basi untuk rute lain dibuang.
     *
     * @return array<string, mixed>|null
     */
    public static function deepLinkData(array $state): ?array
    {
        if (empty($state['deep_link_route'])) {
            return null;
        }

        $data = ['route' => $state['deep_link_route']];

        if (in_array($state['deep_link_route'], self::ROUTES_NEEDING_ID, true) && ! empty($state['deep_link_param_id'])) {
            $data['params'] = ['id' => (string) $state['deep_link_param_id']];
        }

        return $data;
    }

    /**
     * Kriteria segmen dari isian form, atau null bila kiriman bukan ke segmen. Hanya untuk pelanggan, saat "semua pengguna" mati dan mode
     * sasaran "segmen" dipilih. Kunci: group_ids, booking (any|has|never), inactive_months, warranty_active.
     *
     * @return array<string, mixed>|null
     */
    public static function segmentFromState(array $state): ?array
    {
        if (($state['audience'] ?? 'customer') !== 'customer' || ! empty($state['broadcast']) || ($state['target_mode'] ?? 'pick') !== 'segment') {
            return null;
        }

        return array_filter([
            'group_ids' => array_values(array_filter((array) ($state['segment_group_ids'] ?? []))),
            'booking' => in_array($state['segment_booking'] ?? 'any', ['has', 'never'], true) ? $state['segment_booking'] : null,
            'inactive_months' => filled($state['segment_inactive_months'] ?? null) ? max(1, (int) $state['segment_inactive_months']) : null,
            'warranty_active' => ! empty($state['segment_warranty_active']) ? true : null,
        ], fn ($value) => $value !== null && $value !== []);
    }

    /** Ringkasan kriteria segmen untuk ditampilkan ke admin. */
    public static function segmentLabel(array $segment): string
    {
        $parts = [];

        if (! empty($segment['group_ids'])) {
            $parts[] = 'Grup: ' . \App\Models\CustomerGroup::whereIn('id', $segment['group_ids'])->orderBy('name')->pluck('name')->implode(', ');
        }
        if (($segment['booking'] ?? null) === 'has') {
            $parts[] = 'pernah booking';
        } elseif (($segment['booking'] ?? null) === 'never') {
            $parts[] = 'belum pernah booking';
        }
        if (! empty($segment['inactive_months'])) {
            $parts[] = "belum booking sejak {$segment['inactive_months']} bulan";
        }
        if (! empty($segment['warranty_active'])) {
            $parts[] = 'punya garansi aktif';
        }

        return $parts === [] ? '—' : implode('; ', $parts);
    }

    /**
     * ID pelanggan aktif yang cocok dengan kriteria segmen (semua kriteria berlaku bersamaan). Dipanggil saat pratinjau dan saat kirim,
     * jadi kampanye terjadwal memakai keanggotaan terkini.
     *
     * @return list<int>
     */
    public function resolveSegment(array $segment): array
    {
        $query = Customer::query();

        if (! empty($segment['group_ids'])) {
            $query->whereIn('customer_group_id', $segment['group_ids']);
        }

        if (($segment['booking'] ?? null) === 'has') {
            $query->whereHas('bookings');
        } elseif (($segment['booking'] ?? null) === 'never') {
            $query->whereDoesntHave('bookings');
        }

        if (! empty($segment['inactive_months'])) {
            $cutoff = now()->subMonths((int) $segment['inactive_months']);
            $query->whereHas('bookings')->whereDoesntHave('bookings', fn ($q) => $q->where('created_at', '>=', $cutoff));
        }

        if (! empty($segment['warranty_active'])) {
            $query->whereHas('warranties', fn ($q) => $q->whereDate('expiry_date', '>=', now()->toDateString()));
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Isian form dari kampanye yang sudah ada (untuk "Duplikat"). Jadwal tidak ikut. */
    public static function stateFromCampaign(NotificationCampaign $campaign): array
    {
        $partner = $campaign->audience === 'partner';
        $state = [
            'audience' => $partner ? 'partner' : 'customer',
            'title' => $campaign->title,
            'body' => $campaign->body,
        ];

        if ($partner) {
            $state['broadcast_partner'] = empty($campaign->partner_ids);
            $state['partner_ids'] = $campaign->partner_ids ?? [];

            return $state;
        }

        $state['category'] = $campaign->category === 'info' ? 'info' : 'promo';
        $state['deep_link_route'] = $campaign->data['route'] ?? null;
        $state['deep_link_param_id'] = $campaign->data['params']['id'] ?? null;

        if (! empty($campaign->segment)) {
            $state += [
                'broadcast' => false,
                'target_mode' => 'segment',
                'segment_group_ids' => $campaign->segment['group_ids'] ?? [],
                'segment_booking' => $campaign->segment['booking'] ?? 'any',
                'segment_inactive_months' => $campaign->segment['inactive_months'] ?? null,
                'segment_warranty_active' => ! empty($campaign->segment['warranty_active']),
            ];
        } elseif (! empty($campaign->customer_ids)) {
            $state += ['broadcast' => false, 'target_mode' => 'pick', 'customer_ids' => $campaign->customer_ids];
        } else {
            $state['broadcast'] = true;
        }

        return $state;
    }

    /** Buat kampanye dari isian form. Tanpa $scheduledAt, statusnya langsung "sedang dikirim" (siap di-dispatch). */
    public function create(array $state, ?CarbonInterface $scheduledAt, ?int $userId): NotificationCampaign
    {
        $partner = ($state['audience'] ?? 'customer') === 'partner';

        return NotificationCampaign::create([
            'audience'     => $partner ? 'partner' : 'customer',
            'category'     => $partner ? null : (($state['category'] ?? 'promo') === 'info' ? 'info' : 'promo'),
            'title'        => $state['title'],
            'body'         => $state['body'],
            'data'         => $partner ? null : self::deepLinkData($state),
            'customer_ids' => ! $partner && empty($state['broadcast']) && self::segmentFromState($state) === null ? array_values($state['customer_ids'] ?? []) : null,
            'segment'      => self::segmentFromState($state) ?: null,
            'partner_ids'  => $partner && empty($state['broadcast_partner']) ? array_values($state['partner_ids'] ?? []) : null,
            'status'       => $scheduledAt ? NotificationCampaign::SCHEDULED : NotificationCampaign::SENDING,
            'scheduled_at' => $scheduledAt,
            'created_by'   => $userId,
        ]);
    }

    /**
     * Kirim kampanye sekarang. Hasilnya dicatat di kampanye; pemanggil memakai nilai kembalian untuk pesan ke admin.
     *
     * @return array{ok: bool, message: string|null, result: array<string, mixed>}
     */
    public function dispatch(NotificationCampaign $campaign): array
    {
        $campaign->update(['status' => NotificationCampaign::SENDING]);

        $isPartner = $campaign->audience === 'partner';

        if (! $isPartner && ! empty($campaign->segment)) {
            $ids = $this->resolveSegment($campaign->segment);

            if ($ids === []) {
                $message = 'Tidak ada pelanggan yang cocok dengan segmen ini, jadi tidak ada yang dikirimi.';
                $campaign->update(['status' => NotificationCampaign::FAILED, 'message' => $message]);

                return ['ok' => false, 'message' => $message, 'result' => []];
            }

            $campaign->customer_ids = $ids;
        }
        $request = $isPartner
            ? Request::create('/api/partner-notifications/send', 'POST', [
                'title'       => $campaign->title,
                'body'        => $campaign->body,
                'partner_ids' => $campaign->partner_ids,
            ])
            : Request::create('/api/notifications/send', 'POST', [
                'title'        => $campaign->title,
                'body'         => $campaign->body,
                'customer_ids' => $campaign->customer_ids,
                'data'         => $campaign->data,
                'category'     => $campaign->category === 'info' ? 'info' : 'promo',
            ]);

        PushNotificationService::$deliveryCampaignId = $campaign->id;

        // Controller dipanggil langsung (bukan lewat HTTP kernel), jadi ValidationException harus ditangkap di sini
        // (mis. pelanggan yang dipilih sempat dihapus sebelum form dikirim).
        try {
            $controller = app(NotificationController::class);
            $response = $isPartner ? $controller->sendPartner($request) : $controller->send($request);
            $code = $response->getStatusCode();
            $result = $response->getData(true);
        } catch (ValidationException $e) {
            $code = 422;
            $result = ['message' => collect($e->errors())->flatten()->implode(' ')];
        } catch (\Throwable $e) {
            report($e);
            $code = 500;
            $result = ['message' => 'Terjadi kesalahan sistem saat mengirim.'];
        } finally {
            PushNotificationService::$deliveryCampaignId = null;
        }

        if ($code >= 400) {
            $campaign->update(['status' => NotificationCampaign::FAILED, 'message' => $result['message'] ?? 'Terjadi kesalahan.']);

            return ['ok' => false, 'message' => $result['message'] ?? 'Terjadi kesalahan.', 'result' => $result];
        }

        $campaign->update([
            'status'         => NotificationCampaign::SENT,
            'sent_at'        => now(),
            'recipients'     => $result['recipients'] ?? null,
            'skipped'        => $result['skipped'] ?? 0,
            'devices_sent'   => $result['sent'] ?? 0,
            'devices_failed' => $result['failed'] ?? 0,
            'campaign_key'   => $result['campaign_key'] ?? null,
            'message'        => $result['message'] ?? null,
        ]);

        return ['ok' => true, 'message' => null, 'result' => $result];
    }

    /**
     * Pratinjau penerima tanpa mengirim apa pun: berapa penerima dan perangkat, berapa yang dikecualikan dan kenapa, dan apakah
     * kiriman akan ditolak (jam tenang, batas mingguan).
     *
     * @return array{recipients: int, devices: int, excluded: int|null, excluded_reason: string|null, warnings: list<string>, blocked: bool}
     */
    public function preview(array $state): array
    {
        if (($state['audience'] ?? 'customer') === 'partner') {
            return $this->previewPartners($state);
        }

        $targeted = empty($state['broadcast']) ? array_values(array_unique($state['customer_ids'] ?? [])) : [];
        $segment = self::segmentFromState($state);

        if ($segment !== null) {
            if ($segment === []) {
                return ['recipients' => 0, 'devices' => 0, 'excluded' => null, 'excluded_reason' => null, 'warnings' => ['Pilih minimal satu kriteria segmen.'], 'blocked' => true];
            }

            $targeted = $this->resolveSegment($segment);

            if ($targeted === []) {
                return ['recipients' => 0, 'devices' => 0, 'excluded' => null, 'excluded_reason' => null, 'warnings' => ['Tidak ada pelanggan yang cocok dengan segmen ini.'], 'blocked' => true];
            }
        }

        $warnings = [];
        $blocked = false;
        $excluded = null;
        $reason = null;

        if (($state['category'] ?? 'promo') === 'info') {
            $recipientIds = Customer::query()
                ->when($targeted !== [], fn ($q) => $q->whereIn('id', $targeted))
                ->whereIn('id', DeviceToken::query()->whereNotNull('customer_id')->select('customer_id'));
            $recipients = $targeted !== [] ? count($targeted) : (clone $recipientIds)->count();
            $devices = DeviceToken::whereNotNull('customer_id')->when($targeted !== [], fn ($q) => $q->whereIn('customer_id', $targeted))->count();
        } else {
            $optedIn = Customer::query()->where('promo_opt_in', true)->when($targeted !== [], fn ($q) => $q->whereIn('id', $targeted));
            $recipients = (clone $optedIn)->count();
            $devices = DeviceToken::whereIn('customer_id', (clone $optedIn)->select('id'))->count();
            $excluded = $targeted !== []
                ? count($targeted) - $recipients
                : Customer::query()->where('promo_opt_in', false)->count();
            $reason = 'belum menyetujui menerima promo';

            if (QuietHours::active()) {
                $blocked = true;
                $warnings[] = 'Sekarang jam tenang (' . QuietHours::label() . '): promosi akan ditolak kalau dikirim sekarang. Jadwalkan di luar jam itu.';
            }

            $max = (int) config('marketing.promo_max_campaigns_per_week', 2);
            $recent = CustomerNotification::where('category', 'promo')->whereNotNull('campaign_key')->where('created_at', '>=', now()->subDays(7))->distinct()->count('campaign_key');

            if ($recent >= $max) {
                $blocked = true;
                $warnings[] = "Batas promosi tercapai: {$recent} dari {$max} kiriman dalam 7 hari terakhir.";
            }

            if ($recipients === 0) {
                $warnings[] = 'Tidak ada pelanggan yang menyetujui promo, jadi tidak ada yang akan dikirimi.';
            }
        }

        return [
            'recipients'      => $recipients,
            'devices'         => $devices,
            'excluded'        => $excluded,
            'excluded_reason' => $reason,
            'warnings'        => $warnings,
            'blocked'         => $blocked,
        ];
    }

    private function previewPartners(array $state): array
    {
        $targeted = empty($state['broadcast_partner']) ? array_values(array_unique($state['partner_ids'] ?? [])) : [];
        $partners = Partner::query()->when($targeted !== [], fn ($q) => $q->whereIn('id', $targeted));

        return [
            'recipients'      => (clone $partners)->count(),
            'devices'         => DeviceToken::whereIn('user_id', (clone $partners)->select('user_id'))->count(),
            'excluded'        => null,
            'excluded_reason' => null,
            'warnings'        => [],
            'blocked'         => false,
        ];
    }
}
