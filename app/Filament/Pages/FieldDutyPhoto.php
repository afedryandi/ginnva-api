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
            && $user->hasMenuAccess(FieldDutyRequestResource::class);
    }

    /** Dinas luar milik sendiri yang disetujui, hari ini, dan belum ada foto di lokasi. */
    public function pending(): ?FieldDutyRequest
    {
        return FieldDutyRequest::withoutGlobalScopes()
            ->where('user_id', auth()->id())
            ->where('status', FieldDutyRequest::STATUS_APPROVED)
            ->whereDate('date', today())
            ->whereNull('location_photo')
            ->orderBy('id')
            ->first();
    }

    public function submitPhoto(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->validate(
            ['photo' => 'required|image|max:5120|dimensions:min_width=200,min_height=200'],
            [
                'photo.required' => 'Ambil foto di lokasi dulu.',
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
            app(FieldDutyRequestService::class)->attachLocationPhoto($duty, auth()->user(), $this->photo);
        } catch (\RuntimeException $e) {
            Notification::make()->title('Foto ditolak')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->photo = null;

        Notification::make()->title('Foto di lokasi tersimpan')->body('Dinas luar Anda tercatat hadir.')->success()->send();
    }
}
