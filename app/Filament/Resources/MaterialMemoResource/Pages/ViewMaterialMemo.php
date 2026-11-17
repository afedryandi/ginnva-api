<?php

namespace App\Filament\Resources\MaterialMemoResource\Pages;

use App\Filament\Resources\MaterialMemoResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * Halaman Lihat: memo dan barangnya hanya dibaca. Mengubah memo dan mencatat barang butuh izin Ubah (MaterialMemoResource::canEdit()).
 *
 * Memo terkunci begitu booking-nya Selesai (MaterialMemo::isLocked()). Akses penuh bisa membuka kunci dengan alasan wajib (tercatat di
 * Histori Aktivitas) dan mengunci lagi sesudah koreksi.
 */
class ViewMaterialMemo extends ViewRecord
{
    protected static string $resource = MaterialMemoResource::class;

    public function getSubheading(): ?string
    {
        if ($this->record->isRequestFlow()) {
            $label = \App\Models\MaterialMemo::REQUEST_STATUS_LABELS[$this->record->request_status] ?? '';
            $extra = match ($this->record->request_status) {
                'rejected' => ': ' . $this->record->reject_reason,
                'fulfilled' => $this->record->isHandoverLocked() ? '. Terkunci, barang dan jumlahnya tidak bisa diubah. Akses penuh bisa membuka kunci.' : '.',
                default => '.',
            };

            return "Pengajuan: {$label}{$extra}";
        }

        if ($this->record->isLocked()) {
            return 'Terkunci: booking-nya sudah Selesai, jadi memo dan barangnya tidak bisa diubah. Akses penuh bisa membuka kunci.';
        }

        if ($this->record->isUnlockedAfterCompletion()) {
            return 'Kunci dibuka oleh akses penuh' . ($this->record->unlock_reason ? ': ' . $this->record->unlock_reason : '.') . ' Kunci lagi setelah koreksi selesai.';
        }

        return null;
    }

    /** Jalankan satu langkah alur pengajuan; pesan kesalahan layanan ditampilkan apa adanya. */
    private function runStep(callable $step, string $success): void
    {
        try {
            $step(app(\App\Services\MaterialMemoRequestService::class));
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title('Tidak bisa diproses')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($success)->success()->send();
        $this->record->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

            // ---- Memo pengajuan: setujui / tolak (store manager atau akses penuh), selesai diserahkan (gudang), minta tambahan (pengaju).
            Actions\Action::make('approve_request')
                ->label('Setujui')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn () => $this->record->request_status === \App\Models\MaterialMemo::REQ_PENDING
                    && app(\App\Services\MaterialMemoRequestService::class)->canApprove(auth()->user(), $this->record))
                ->requiresConfirmation()
                ->modalDescription('Setelah disetujui, gudang mencatat barang dan jumlah yang benar-benar diambil.')
                ->action(fn () => $this->runStep(fn ($service) => $service->approve($this->record, auth()->user()), 'Memo disetujui.')),

            Actions\Action::make('reject_request')
                ->label('Tolak')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->record->request_status === \App\Models\MaterialMemo::REQ_PENDING
                    && app(\App\Services\MaterialMemoRequestService::class)->canApprove(auth()->user(), $this->record))
                ->form([Forms\Components\Textarea::make('reason')->label('Alasan')->required()->maxLength(500)->rows(2)])
                ->action(fn (array $data) => $this->runStep(fn ($service) => $service->reject($this->record, auth()->user(), $data['reason']), 'Memo ditolak.')),

            Actions\Action::make('complete_handover')
                ->label('Selesai Diserahkan')
                ->icon('heroicon-o-archive-box-arrow-down')
                ->color('success')
                ->visible(fn () => $this->record->request_status === \App\Models\MaterialMemo::REQ_APPROVED
                    && app(\App\Services\MaterialMemoRequestService::class)->canHandOver(auth()->user(), $this->record))
                ->requiresConfirmation()
                ->modalHeading('Selesai diserahkan?')
                ->modalDescription('Setelah ini barang dan jumlahnya terkunci dan tidak bisa diubah lagi (hanya akses penuh yang bisa membuka). Direksi diberi tahu.')
                ->action(fn () => $this->runStep(fn ($service) => $service->complete($this->record, auth()->user()), 'Barang diserahkan, memo terkunci.')),

            Actions\Action::make('request_more')
                ->label(fn () => $this->record->request_status === \App\Models\MaterialMemo::REQ_REJECTED ? 'Ajukan Ulang' : 'Minta Tambahan')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => in_array($this->record->request_status, [\App\Models\MaterialMemo::REQ_REJECTED, \App\Models\MaterialMemo::REQ_FULFILLED], true)
                    && ((int) $this->record->created_by === (int) auth()->id() || (auth()->user()?->isFullAccess() ?? false)))
                ->form([Forms\Components\Textarea::make('note')->label('Catatan (apa yang perlu ditambah)')->required()->maxLength(500)->rows(2)])
                ->action(fn (array $data) => $this->runStep(fn ($service) => $service->resubmit($this->record, auth()->user(), $data['note']), 'Pengajuan dikirim.')),

            Actions\Action::make('unlock')
                ->label('Buka Kunci')
                ->icon('heroicon-o-lock-open')
                ->color('warning')
                ->visible(fn () => $this->record->isLocked() && (auth()->user()?->isFullAccess() ?? false))
                ->modalHeading('Buka kunci memo?')
                ->modalDescription('Pemakaian bahan di memo ini sudah dibukukan saat booking selesai. Gunakan hanya untuk koreksi, dan kunci lagi sesudahnya.')
                ->form([
                    Forms\Components\Textarea::make('reason')->label('Alasan')->required()->maxLength(500),
                ])
                ->action(function (array $data) {
                    $this->record->update(['unlocked_at' => now(), 'unlocked_by' => auth()->id(), 'unlock_reason' => $data['reason']]);

                    activity('material_memo')->performedOn($this->record)->causedBy(auth()->user())
                        ->withProperties(['alasan' => $data['reason']])
                        ->log('Kunci memo dibuka: ' . $data['reason']);

                    Notification::make()->title('Kunci memo dibuka.')->success()->send();
                    $this->record->refresh();
                }),

            Actions\Action::make('relock')
                ->label('Kunci Lagi')
                ->icon('heroicon-o-lock-closed')
                ->color('gray')
                ->visible(fn () => $this->record->isUnlockedAfterCompletion() && (auth()->user()?->isFullAccess() ?? false))
                ->requiresConfirmation()
                ->action(function () {
                    $this->record->update(['unlocked_at' => null, 'unlocked_by' => null, 'unlock_reason' => null]);

                    activity('material_memo')->performedOn($this->record)->causedBy(auth()->user())->log('Memo dikunci lagi');

                    Notification::make()->title('Memo dikunci.')->success()->send();
                    $this->record->refresh();
                }),
        ];
    }
}
