<?php

namespace App\Console\Commands;

use App\Filament\Resources\PayableResource;
use App\Models\Payable;
use App\Models\User;
use App\Services\PushNotificationService;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Pengingat Hutang Usaha jatuh tempo (audit Hutang Usaha 2026-09-29): 1 ringkasan
 * per full-access per hari -- tagihan yang jatuh tempo dalam 3 hari ke depan atau
 * sudah lewat. Idempotent alami (hanya membaca status); tagihan lunas/dibatalkan
 * otomatis keluar.
 */
class RemindPayableDue extends Command
{
    protected $signature = 'payables:remind-due';

    protected $description = 'Ingatkan direksi soal Hutang Usaha yang jatuh tempo (H-3 s/d terlewat)';

    public function handle(): int
    {
        $due = Payable::withoutGlobalScopes()
            ->whereIn('status', ['unpaid', 'partial'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', now()->addDays(3))
            ->get();

        if ($due->isEmpty()) {
            $this->info('Tidak ada hutang yang jatuh tempo dekat.');

            return self::SUCCESS;
        }

        $overdue = $due->filter(fn (Payable $p) => $p->due_date->lt(today()))->count();
        $total = $due->sum(fn (Payable $p) => $p->remainingAmount());
        $body = $due->count() . ' tagihan (' . $overdue . ' terlambat), total sisa Rp ' . number_format($total, 0, ',', '.') . '.';

        $pushed = [];

        foreach (User::where('is_active', true)->get()->filter(fn (User $u) => $u->isFullAccess()) as $user) {
            Notification::make()
                ->title('Hutang usaha jatuh tempo')
                ->body($body)
                ->warning()
                ->actions([
                    \Filament\Notifications\Actions\Action::make('view')
                        ->label('Lihat')
                        ->url(PayableResource::getUrl('index'))
                        ->markAsRead(),
                ])
                ->sendToDatabase($user);

            $pushed[] = $user->id;
        }

        try {
            app(PushNotificationService::class)->sendToUsers($pushed, 'Hutang Usaha Jatuh Tempo', $body);
        } catch (\Throwable $e) {
            report($e);
        }

        $this->info('Reminder dikirim ke ' . count($pushed) . " direksi ({$due->count()} tagihan).");

        return self::SUCCESS;
    }
}
