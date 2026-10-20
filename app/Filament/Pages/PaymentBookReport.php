<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\BookingDownPayment;
use App\Models\BookingSettlementPayment;
use App\Models\ReceivablePayment;
use App\Models\Store;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Buku Pembayaran" (Penjualan Fase 2, 2026-11-11): satu daftar semua uang masuk dari booking dalam satu rentang tanggal, dari tiga tempat
 * yang sudah ada: uang muka (DP), pembayaran saat penjualan diproses, dan pelunasan piutang booking. Hanya membaca; tidak menyalin data.
 * Toko dibatasi seperti laporan Penjualan lain: staf toko hanya melihat tokonya sendiri.
 */
class PaymentBookReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Penjualan';

    protected static ?string $navigationLabel = 'Buku Pembayaran';

    protected static ?string $title = 'Buku Pembayaran';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.payment-book-report';

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
            'store_id' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;

        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
            Select::make('store_id')
                ->label('Cabang')
                ->placeholder('Semua cabang')
                ->options(fn () => Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->visible($isFullAccess)
                ->live(),
        ])->columns($isFullAccess ? 3 : 2)->statePath('data');
    }

    /** Staf toko dikunci ke tokonya; staf tanpa toko tidak melihat apa pun (-1). */
    private function effectiveStoreId(): ?int
    {
        $user = auth()->user();

        if ($user?->isFullAccess() ?? false) {
            $chosen = $this->data['store_id'] ?? null;

            return $chosen ? (int) $chosen : null;
        }

        return $user?->store_id ?? -1;
    }

    /**
     * @return array{rows: Collection, byKind: array<string, float>, byMethod: array<string, float>, total: float, from: Carbon, to: Carbon}
     */
    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();

        if ($to->lt($from)) {
            $to = $from->copy()->endOfDay();
        }

        $storeId = $this->effectiveStoreId();
        $bookingScope = fn ($q) => $q->when($storeId, fn ($w) => $w->where('store_id', $storeId));
        $methods = Booking::PAYMENT_METHOD_LABELS;

        $rows = collect();

        BookingDownPayment::query()
            ->with(['booking:id,booking_number,customer_id,customer_name,store_id', 'booking.customer:id,name', 'paymentAccount:id,name'])
            ->whereBetween('received_at', [$from->toDateString(), $to->toDateString()])
            ->whereHas('booking', $bookingScope)
            ->get()
            ->each(fn (BookingDownPayment $dp) => $rows->push([
                'date' => $dp->received_at,
                'kind' => 'Uang Muka (DP)',
                'reference' => $dp->booking?->booking_number ?? '—',
                'customer' => $dp->booking?->display_customer_name ?? '—',
                'method' => '—',
                'account' => $dp->paymentAccount?->name ?? 'Kas di Tangan',
                'amount' => (float) $dp->amount,
                'note' => $dp->refunded_at ? 'Dikembalikan' : null,
            ]));

        BookingSettlementPayment::query()
            ->with(['booking:id,booking_number,customer_id,customer_name,store_id', 'booking.customer:id,name', 'paymentAccount:id,name'])
            ->whereBetween('paid_at', [$from->toDateString(), $to->toDateString()])
            ->whereHas('booking', $bookingScope)
            ->get()
            ->each(fn (BookingSettlementPayment $p) => $rows->push([
                'date' => $p->paid_at,
                'kind' => 'Pembayaran saat selesai',
                'reference' => $p->booking?->booking_number ?? '—',
                'customer' => $p->booking?->display_customer_name ?? '—',
                'method' => $methods[$p->method] ?? $p->method,
                'account' => $p->paymentAccount?->name ?? 'Otomatis menurut metode',
                'amount' => (float) $p->amount,
                'note' => null,
            ]));

        ReceivablePayment::query()
            ->with('receivable:id,receivable_number,customer_name,store_id,source_type,source_id')
            ->whereNull('voided_at')
            ->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('receivable', fn ($q) => $q->where('source_type', 'booking')->when($storeId, fn ($w) => $w->where('store_id', $storeId)))
            ->get()
            ->each(fn (ReceivablePayment $p) => $rows->push([
                'date' => $p->payment_date,
                'kind' => 'Pelunasan piutang',
                'reference' => $p->receipt_number ?: ($p->receivable?->receivable_number ?? '—'),
                'customer' => $p->receivable?->customer_name ?? '—',
                'method' => '—',
                'account' => '—',
                'amount' => (float) $p->amount,
                'note' => null,
            ]));

        $rows = $rows->sortByDesc(fn (array $r) => $r['date']?->timestamp ?? 0)->values();

        $counted = $rows->reject(fn (array $r) => $r['note'] === 'Dikembalikan');

        return [
            'rows' => $rows,
            'byKind' => $counted->groupBy('kind')->map(fn ($g) => (float) $g->sum('amount'))->all(),
            'byMethod' => $counted->where('method', '!=', '—')->groupBy('method')->map(fn ($g) => (float) $g->sum('amount'))->all(),
            'total' => (float) $counted->sum('amount'),
            'from' => $from,
            'to' => $to,
        ];
    }
}
