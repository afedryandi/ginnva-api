<?php

namespace App\Filament\Pages;

use App\Filament\Resources\FieldDutyRequestResource;
use App\Models\FieldDutyRequest;
use App\Services\FieldDutyRequestService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * "Foto Dinas Luar": kirim foto bukti di lokasi dari panel untuk karyawan yang belum bisa memakai aplikasi staff (mis. iPhone sebelum build iOS ada).
 * Memakai jalur yang SAMA dengan aplikasi: FieldDutyRequestService::attachLocationPhoto() (hanya pemilik, hanya yang sudah disetujui, hanya pada hari dinas).
 * Foto diambil langsung dari kamera browser (getUserMedia, tanpa pilihan galeri), seperti halaman Absen Saya. Tidak muncul di menu; dibuka dari
 * tombol di daftar Pengajuan Dinas Luar.
 */
class FieldDutyPhoto extends Page
{
    use WithFileUploads;

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $title = 'Foto Dinas Luar';

    protected static ?string $slug = 'foto-dinas-luar';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.field-duty-photo';

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->canAccessStaffArea()
            // Cukup hak "Absen Saya" (karyawan tanpa aplikasi tidak dicentang di menu Dinas Luar); menu Dinas Luar tetap boleh.
            && ($user->hasMenuAccess(FieldDutyRequestResource::class) || MyAttendance::canAccess());
    }

    /**
     * Dinas luar milik sendiri hari ini yang masih butuh foto awal atau foto akhir. Termasuk yang masih menunggu persetujuan: karyawan boleh
     * mengirim foto di hari dinas sebelum atasan sempat menyetujui (sama seperti aplikasi dan FieldDutyRequestService), fotonya dinilai begitu disetujui.
     */
    public function pending(): ?FieldDutyRequest
    {
        return FieldDutyRequest::withoutGlobalScopes()
            ->where('user_id', auth()->id())
            ->whereIn('status', [FieldDutyRequest::STATUS_APPROVED, FieldDutyRequest::STATUS_PENDING])
            ->whereDate('date', today())
            ->where(fn ($q) => $q->whereNull('location_photo')->orWhereNull('end_photo'))
            ->orderBy('id')
            ->first();
    }

    /** Tahap foto yang sedang dikirim: 'start' (foto awal) bila belum ada, selain itu 'end' (foto akhir). */
    public function stage(): string
    {
        return filled($this->pending()?->location_photo) ? 'end' : 'start';
    }

    public function submitPhoto(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->validate(
            ['photo' => 'required|image|max:5120|dimensions:min_width=200,min_height=200'],
            [
                'photo.required' => 'Ambil foto dulu.',
                'photo.image' => 'Berkas harus berupa foto.',
                'photo.max' => 'Ukuran foto maksimal 5 MB.',
                'photo.dimensions' => 'Foto terlalu kecil (minimal 200 x 200 piksel).',
            ]
        );

        $duty = $this->pending();

        if (! $duty) {
            Notification::make()->title('Tidak ada dinas luar yang butuh foto hari ini')->warning()->send();

            return;
        }

        try {
            $service = app(FieldDutyRequestService::class);
            $isEnd = filled($duty->location_photo);
            $isEnd ? $service->attachEndPhoto($duty, auth()->user(), $this->photo) : $service->attachLocationPhoto($duty, auth()->user(), $this->photo);
        } catch (\RuntimeException $e) {
            Notification::make()->title('Foto ditolak')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->photo = null;

        Notification::make()
            ->title($isEnd ? 'Foto akhir tersimpan' : 'Foto awal tersimpan')
            ->body($isEnd ? 'Dinas luar Anda lengkap.' : 'Foto akhir dikirim saat dinas selesai.')
            ->success()
            ->send();
    }
}
