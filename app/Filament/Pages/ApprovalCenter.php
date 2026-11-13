<?php

namespace App\Filament\Pages;

use App\Models\FieldDutyRequest;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\ApprovalCenterService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Pusat Persetujuan (2026-11-13): semua pengajuan yang menunggu keputusan dalam satu halaman, supaya direksi tidak perlu membuka menu satu
 * per satu. Isinya dikumpulkan ApprovalCenterService; tiap pengguna hanya melihat yang berhak ia putuskan. Jenis yang bisa diputuskan
 * langsung memakai layanan keputusan yang sama dengan menunya (lihat App\Support\Approvals\Sources), sisanya berupa tombol Buka.
 */
class ApprovalCenter extends Page implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationLabel = 'Pusat Persetujuan';

    protected static ?string $title = 'Pusat Persetujuan';

    protected static ?string $slug = 'pusat-persetujuan';

    protected static ?int $navigationSort = -20;

    protected static string $view = 'filament.pages.approval-center';

    protected static ?string $navigationBadgeTooltip = 'Pengajuan menunggu keputusan Anda';

    /** Penyaring jenis pengajuan; kosong = semua. */
    #[Url(as: 'jenis')]
    public ?string $source = null;

    public static function canAccess(): bool
    {
        return app(ApprovalCenterService::class)->canUse(auth()->user());
    }

    /**
     * Bukan tab di bilah menu atas: bilahnya sudah penuh oleh klaster lain, dan item tambahan membuatnya melebar sampai logo menyusut
     * (2026-11-13). Pintasannya berupa ikon dengan angka di samping lonceng (hook USER_MENU_BEFORE di AdminPanelProvider).
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if (! $user instanceof User || ! app(ApprovalCenterService::class)->canUse($user)) {
            return null;
        }

        $count = app(ApprovalCenterService::class)->badgeCount($user);

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** @return Collection<int, \App\Support\Approvals\ApprovalItem> */
    public function getItems(): Collection
    {
        return app(ApprovalCenterService::class)->items(auth()->user(), $this->source ?: null);
    }

    /** @return array<string, int> */
    public function getCounts(): array
    {
        return app(ApprovalCenterService::class)->countsBySource(auth()->user());
    }

    /** @return array<string, string> kunci => nama jenis */
    public function getSourceLabels(): array
    {
        return collect(app(ApprovalCenterService::class)->sources())->map(fn ($s) => $s->label())->all();
    }

    public function filterBy(?string $source): void
    {
        $this->source = $source ?: null;
    }

    // ------------------------------------------------------------------ tombol

    public function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Setujui')
            ->icon('heroicon-o-check')
            ->color('success')
            ->size('sm')
            ->modalHeading('Setujui pengajuan ini?')
            ->modalSubmitActionLabel('Setujui')
            ->requiresConfirmation(fn (array $arguments) => $this->approveFields($arguments) === [])
            ->form(fn (array $arguments) => $this->approveFields($arguments))
            ->fillForm(fn (array $arguments) => $this->approveDefaults($arguments))
            ->action(fn (array $arguments, array $data) => $this->decide('approve', $arguments, $data));
    }

    public function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Tolak')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->outlined()
            ->size('sm')
            ->modalHeading('Tolak pengajuan ini?')
            ->modalSubmitActionLabel('Tolak')
            ->form([
                Textarea::make('note')->label('Alasan Ditolak')->helperText('Alasan ini dikirim ke pemohon.')->required()->rows(2)->maxLength(500),
            ])
            ->action(fn (array $arguments, array $data) => $this->decide('reject', $arguments, $data));
    }

    /** Isian tambahan saat menyetujui: jumlah untuk Permohonan Pembelian, jam untuk Dinas Luar sebagian hari. @return array<int, \Filament\Forms\Components\Component> */
    private function approveFields(array $arguments): array
    {
        $id = (int) ($arguments['id'] ?? 0);

        if (($arguments['source'] ?? null) === 'purchase_request') {
            $request = PurchaseRequest::withoutGlobalScopes()->find($id);

            return $request === null ? [] : [
                TextInput::make('quantity_approved')
                    ->label('Jumlah yang Disetujui')
                    ->numeric()->required()->minValue(0.01)->maxValue((float) $request->quantity)
                    ->suffix($request->unit)
                    ->helperText('Diminta: ' . rtrim(rtrim(number_format((float) $request->quantity, 2, ',', '.'), '0'), ',') . ($request->unit ? " {$request->unit}" : '') . '. Boleh disetujui lebih kecil.'),
            ];
        }

        if (($arguments['source'] ?? null) === 'field_duty') {
            $request = FieldDutyRequest::withoutGlobalScopes()->find($id);

            return $request === null || ! $request->isPartialDay() ? [] : [
                TimePicker::make('start_time')->label('Jam Mulai')->seconds(false)->required(),
                TimePicker::make('end_time')->label('Jam Selesai')->seconds(false)->required()->after('start_time')
                    ->helperText('Jam boleh diubah sesuai kenyataan sebelum disetujui.'),
            ];
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function approveDefaults(array $arguments): array
    {
        $id = (int) ($arguments['id'] ?? 0);

        if (($arguments['source'] ?? null) === 'purchase_request') {
            $request = PurchaseRequest::withoutGlobalScopes()->find($id);

            return $request ? ['quantity_approved' => (float) $request->quantity] : [];
        }

        if (($arguments['source'] ?? null) === 'field_duty') {
            $request = FieldDutyRequest::withoutGlobalScopes()->find($id);

            return $request && $request->isPartialDay()
                ? ['start_time' => substr((string) $request->start_time, 0, 5), 'end_time' => substr((string) $request->end_time, 0, 5)]
                : [];
        }

        return [];
    }

    private function decide(string $kind, array $arguments, array $data): void
    {
        $service = app(ApprovalCenterService::class);
        $user = auth()->user();
        $source = $service->source((string) ($arguments['source'] ?? ''));

        if ($source === null || ! $source->inline()) {
            Notification::make()->title('Jenis pengajuan ini diputuskan di menunya sendiri')->warning()->send();

            return;
        }

        try {
            $message = $kind === 'approve'
                ? $source->approve((int) $arguments['id'], $user, $data)
                : $source->reject((int) $arguments['id'], $user, (string) ($data['note'] ?? ''));

            Notification::make()->title($message)->success()->send();
        } catch (ModelNotFoundException) {
            Notification::make()->title('Pengajuan tidak ditemukan')->body('Mungkin sudah dibatalkan oleh pemohon atau dihapus.')->warning()->send();
        } catch (\RuntimeException $e) {
            Notification::make()->title($kind === 'approve' ? 'Gagal menyetujui' : 'Gagal menolak')->body($e->getMessage())->danger()->send();
        } finally {
            $service->forgetBadge($user);
        }
    }
}
