<?php

namespace App\Filament\Resources\CaseStudyResource\Pages;

use App\Filament\Resources\CaseStudyResource;
use App\Models\Booking;
use App\Models\BookingIntakePhoto;
use App\Models\CaseStudy;
use App\Services\GalleryNominationService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListCaseStudies extends ListRecords
{
    protected static string $resource = CaseStudyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('nominate')
                ->label('Nominasikan dari Booking')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->visible(fn () => CaseStudyResource::canCreate())
                ->modalDescription('Hanya booking yang pelanggannya memberi izin foto dan sudah punya foto akhir serah terima. Materi dibuat TIDAK tayang: ganti fotonya dengan versi plat nomor diburamkan, isi teks alternatif, lalu tayangkan.')
                ->form([
                    Forms\Components\Select::make('booking_id')
                        ->label('Booking')
                        ->searchable()
                        ->required()
                        ->live()
                        ->getSearchResultsUsing(fn (string $search) => $this->eligibleBookings()
                            ->where(fn ($q) => $q->where('booking_number', 'like', "%{$search}%")->orWhere('customer_name', 'like', "%{$search}%"))
                            ->limit(30)->get()
                            ->mapWithKeys(fn (Booking $b) => [$b->id => $this->bookingLabel($b)]))
                        ->options(fn () => $this->eligibleBookings()->limit(30)->get()->mapWithKeys(fn (Booking $b) => [$b->id => $this->bookingLabel($b)])),
                    Forms\Components\Select::make('before_photo_id')
                        ->label('Foto sebelum (opsional)')
                        ->placeholder('Tanpa foto sebelum')
                        ->options(fn (Forms\Get $get) => $get('booking_id')
                            ? BookingIntakePhoto::where('booking_id', $get('booking_id'))->get()->mapWithKeys(fn ($p) => [$p->id => ucfirst($p->angle) . ($p->note ? " — {$p->note}" : '')])
                            : []),
                ])
                ->action(function (array $data) {
                    try {
                        $case = app(GalleryNominationService::class)->nominate(
                            Booking::findOrFail($data['booking_id']),
                            ! empty($data['before_photo_id']) ? BookingIntakePhoto::find($data['before_photo_id']) : null,
                        );
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Tidak bisa dinominasikan')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Dinominasikan')->body('Ganti foto dengan versi plat diburamkan, lalu tayangkan.')->success()->send();
                    $this->redirect(CaseStudyResource::getUrl('edit', ['record' => $case]));
                }),
            Actions\CreateAction::make(),
        ];
    }

    private function eligibleBookings()
    {
        return Booking::query()
            ->whereHas('photoConsent', fn ($q) => $q->whereNull('withdrawn_at'))
            ->whereHas('handover', fn ($q) => $q->whereNotNull('final_photo_path'))
            ->whereNotIn('id', CaseStudy::whereNotNull('booking_id')->pluck('booking_id'))
            ->latest('id');
    }

    private function bookingLabel(Booking $b): string
    {
        return trim("{$b->booking_number} · {$b->customer_name} · {$b->vehicle_brand} {$b->vehicle_model}", ' ·');
    }
}
