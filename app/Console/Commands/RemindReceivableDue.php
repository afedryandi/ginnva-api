<?php

namespace App\Console\Commands;

use App\Filament\Resources\ReceivableResource;
use App\Models\Receivable;
use App\Models\User;
use App\Services\PushNotificationService;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Pengingat penagihan Piutang Usaha (audit Piutang Usaha 2026-09-29): 1 ringkasan per
 * full-access per hari -- piutang yang jatuh tempo dalam 3 hari ke depan atau sudah lewat.
 * Idempotent alami (hanya membaca status); piutang lunas/dibatalkan otomatis keluar.
 */
class RemindReceivableDue extends Command
{
    protected $signature = 'receivables:remind-due';

    protected $description = 'Ingatkan direksi soal Piutang Usaha yang jatuh tempo (H-3 s/d terlewat)';

    public function handle(): int
    {
        $due = Receivable::withoutGlobalScopes()
            ->whereIn('status', ['unpaid', 'partial'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', now()->addDays(3))
            ->get();

        if ($due->isEmpty()) {
            $this->info('Tidak ada piutang yang jatuh tempo dekat.');

            return self::SUCCESS;
        }

        $overdue = $due->filter(fn (Receivable $r) => $r->due_date->lt(today()))->count();
        $total = $due->sum(fn (Receivable $r) => $r->remainingAmount());
        $body = $due->count() . ' piutang (' . $overdue . ' terlambat), total sisa Rp ' . number_format($total, 0, ',', '.') . '.';

        $pushed = [];

        foreach (User::where('is_active', true)->get()->filter(fn (User $u) => $u->isFullAccess()) as $user) {
            Notification::make()
                ->title('Piutang usaha perlu ditagih')
                ->body($body)
                ->warning()
                ->actions([
                    \Filament\Notifications\Actions\Action::make('view')
                        ->label('Lihat')
                        ->url(ReceivableResource::getUrl('index'))
                        ->markAsRead(),
                ])
                ->sendToDatabase($user);

            $pushed[] = $user->id;
        }

        try {
            app(PushNotificationService::class)->sendToUsers($pushed, 'Piutang Usaha Perlu Ditagih', $body);
        } catch (\Throwable $e) {
            report($e);
        }

        $this->info('Reminder dikirim ke ' . count($pushed) . " direksi ({$due->count()} piutang).");

        return self::SUCCESS;
    }
}
