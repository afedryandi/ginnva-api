<?php

namespace App\Filament\Pages;

use App\Models\Refund;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * "Laporan Refund" — diminta 2026-09-09, analog "Laporan Refund" Majoo.
 * SEBELUMNYA blocked total (tidak ada mekanisme refund sama sekali) --
 * dibangun setelah aturan dikonfirmasi user: refund bisa PARSIAL, tiap
 * refund WAJIB bikin jurnal kontra otomatis (lihat RefundService, aksi
 * "Proses Refund" di BookingResource).
 *
 * Data di sini murni membaca tabel refunds (sumber kebenaran terpisah
 * dari Booking.transaction_amount) -- SalesSummaryReport/
 * SalesByPeriodReport/SalesByOutletReport ikut diperbarui memakai
 * sumber yang SAMA supaya "Pengembalian" konsisten di semua laporan.
 */
class RefundReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-refund';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Penjualan';

    protected static ?string $navigationLabel = 'Laporan Refund';

    protected static ?string $title = 'Laporan Refund';

    protected static ?int $navigationSort = 9;

    protected static string $view = 'filament.pages.refund-report';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
        ])->columns(2)->statePath('data');
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();
        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;

        $refunds = Refund::query()
            ->whereBetween('created_at', [$from, $to])
            ->with([
                'booking:id,booking_number,customer_name,store_id',
                'booking.store:id,name',
                'creator:id,name',
                // BUG DIPERBAIKI 2026-09-11: deskripsi halaman ("klik
                // No. Jurnal untuk lihat detailnya") menjanjikan kolom
                // ini, tapi SEBELUMNYA tidak pernah di-eager-load ATAU
                // ditampilkan di tabel sama sekali.
                'journalEntry:id,entry_number',
            ])
            ->when(! $isFullAccess, fn ($q) => $q->whereHas('booking', fn ($q2) => $q2->where('store_id', $user?->store_id)))
            ->orderByDesc('created_at')
            ->get();

        return [
            'from' => $from,
            'to' => $to,
            'refunds' => $refunds,
            'totalAmount' => (float) $refunds->sum('amount'),
            'totalCount' => $refunds->count(),
        ];
    }
}
