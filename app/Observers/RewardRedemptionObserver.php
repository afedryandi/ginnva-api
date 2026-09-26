<?php

namespace App\Observers;

use App\Models\Customer;
use App\Models\Partner;
use App\Models\PartnerPointTransaction;
use App\Models\PointTransaction;
use App\Models\RewardRedemption;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Sebelumnya mengubah status redemption jadi 'cancelled' lewat Filament
 * tidak mengembalikan APA PUN — poin yang sudah didebit saat redeem()
 * (lihat RewardRedemptionService) hilang permanen dari saldo customer/
 * partner, dan stok reward yang sudah dikurangi tidak dikembalikan,
 * walau reward-nya tidak pernah benar-benar diberikan. Observer ini
 * menutup celah itu — refund otomatis saat status berubah JADI
 * 'cancelled', dan reversal (debit ulang) kalau status DIUBAH KEMBALI
 * dari 'cancelled' ke status lain (staff membatalkan pembatalannya).
 */
class RewardRedemptionObserver
{
    public function updated(RewardRedemption $redemption): void
    {
        if (! $redemption->wasChanged('status')) {
            return;
        }

        $from = $redemption->getOriginal('status');
        $to   = $redemption->status;

        if ($to === 'cancelled' && $from !== 'cancelled') {
            $this->adjustBalance($redemption, refund: true);
        } elseif ($from === 'cancelled' && $to !== 'cancelled') {
            $this->adjustBalance($redemption, refund: false);
        }

        // Bug diperbaiki 2026-09-26 (audit Katalog Reward) -- SEBELUMNYA
        // tidak ada push notifikasi sama sekali saat status redemption
        // berubah, padahal SEMUA modul lain yang punya alur status-change
        // customer-facing sesi ini (Warranty, dst) selalu push. Customer
        // harus buka app & cek "Riwayat Tukar Reward" manual untuk tahu
        // reward-nya sudah "Sudah Dikirim" atau dibatalkan. Partner TIDAK
        // dikirim push -- tidak ada dukungan push notification untuk
        // Partner sama sekali di codebase ini (portal terpisah).
        if ($redemption->redeemer_type === 'customer' && in_array($to, ['fulfilled', 'cancelled'], true)) {
            $customer = Customer::find($redemption->redeemer_id);
            if ($customer) {
                $title = $to === 'fulfilled' ? 'Reward Sudah Dikirim' : 'Penukaran Reward Dibatalkan';
                $body = $to === 'fulfilled'
                    ? "Reward \"{$redemption->reward?->name}\" Anda sudah diproses toko."
                    : "Penukaran reward \"{$redemption->reward?->name}\" dibatalkan, poin Anda sudah dikembalikan.";

                app(PushNotificationService::class)->sendToCustomer($customer->id, $title, $body);
            }
        }
    }

    private function adjustBalance(RewardRedemption $redemption, bool $refund): void
    {
        DB::transaction(function () use ($redemption, $refund) {
            $redeemer = $redemption->redeemer();
            if (! $redeemer) return;

            $isPartner = $redeemer instanceof Partner;
            $balanceField = $isPartner ? 'points_balance' : 'loyalty_points';
            $points = $redemption->points_spent;

            // Lock baris redeemer — supaya tidak balapan dengan transaksi
            // poin lain yang mungkin berjalan bersamaan (input manual,
            // redeem baru, dst), sama seperti pola di RewardRedemptionService
            // & CreatePartnerPointTransaction.
            $redeemer = $redeemer->newQuery()->where('id', $redeemer->id)->lockForUpdate()->first();

            if ($refund) {
                $redeemer->increment($balanceField, $points);
            } else {
                // Reversal (debit ulang) — SEBELUMNYA decrement() tanpa cek
                // saldo dulu, jadi bisa bikin saldo minus diam-diam kalau
                // poinnya sudah kepakai di tempat lain sejak redemption ini
                // dibatalkan. Clamp ke saldo yang tersedia & catat jumlah
                // SEBENARNYA yang didebit (bisa kurang dari points_spent),
                // supaya tetap ada jejak akurat kalau terjadi kekurangan.
                $points = min($points, $redeemer->{$balanceField});
                $redeemer->decrement($balanceField, $points);
            }

            $description = $refund
                ? "Refund pembatalan penukaran reward #{$redemption->id}"
                : "Pembatalan dibatalkan — poin didebit ulang untuk penukaran reward #{$redemption->id}";

            $payload = [
                'type'           => $refund ? 'earn' : 'spend',
                'points'         => $points,
                'description'    => $description,
                'reference_type' => $refund ? 'reward_redemption_refund' : 'reward_redemption_reversal',
                'reference_id'   => $redemption->id,
            ];

            if ($isPartner) {
                PartnerPointTransaction::create($payload + ['partner_id' => $redeemer->id]);
            } else {
                PointTransaction::create($payload + ['customer_id' => $redeemer->id]);
            }

            // Stok reward juga ikut disesuaikan — dikurangi saat redeem(),
            // jadi harus dikembalikan saat refund (dan dikurangi lagi kalau
            // reversal), TAPI cuma untuk reward yang memang lacak stok
            // (stock !== null, lihat RewardRedemptionService::redeem()).
            $reward = $redemption->reward;
            if ($reward && $reward->stock !== null) {
                $refund ? $reward->increment('stock') : $reward->decrement('stock');
            }
        });
    }
}
