<?php

namespace App\Support;

use App\Filament\Pages\ApprovalCenter;
use App\Filament\Pages\EmployeeDataCompletenessReport;
use App\Filament\Resources\AssetResource;
use App\Filament\Resources\AttendanceCorrectionRequestResource;
use App\Filament\Resources\AttendanceResource;
use App\Filament\Resources\BookingResource;
use App\Filament\Resources\CompanyPolicyResource;
use App\Filament\Resources\ContractExtensionResource;
use App\Filament\Resources\EmployeeTrainingResource;
use App\Filament\Resources\FieldDutyRequestResource;
use App\Filament\Resources\FinanceTransactionApprovalRequestResource;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\LeaveRequestResource;
use App\Filament\Resources\OvertimeRequestResource;
use App\Filament\Resources\PayableResource;
use App\Filament\Resources\PayrollResource;
use App\Filament\Resources\PurchaseRequestResource;
use App\Filament\Resources\QuotationResource;
use App\Filament\Resources\ReceivableResource;
use App\Filament\Resources\ScheduledPriceChangeResource;
use App\Filament\Resources\ShiftResource;
use App\Filament\Resources\SpkResource;
use App\Filament\Resources\StoreReviewResource;
use App\Filament\Resources\TechnicianResource;
use App\Filament\Resources\TransactionApprovalRequestResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\WarningLetterResource;
use App\Filament\Resources\WarrantyResource;
use App\Filament\Resources\WorkScheduleResource;
use Filament\Notifications\Actions\Action;
use Filament\Resources\Resource;

/**
 * Tombol "Buka" pada notifikasi di lonceng panel admin (2026-11-13): notifikasi tidak lagi sekadar teks, ada tombol yang membawa langsung
 * ke halaman terkait. Tujuan dipilih dari judul notifikasi (aturan di bawah, urutan penting: yang pertama cocok menang) atau diberikan
 * langsung sebagai kelas Resource/Page. Judul yang tidak dikenali tidak mendapat tombol (tidak ada tombol yang menyesatkan).
 */
class PanelNotificationLink
{
    private const PANEL = 'admin';

    /** @var list<array{0: string, 1: class-string}> pola judul (regex, tanpa membedakan huruf besar kecil) => tujuan */
    private const RULES = [
        // Pengajuan baru yang menunggu keputusan membuka Pusat Persetujuan, tempat pengajuan bisa langsung disetujui atau ditolak.
        ['/^(pengajuan (lembur|dinas luar|izin\/cuti) baru|permohonan pembelian baru|permintaan koreksi absensi baru)$/i', ApprovalCenter::class],
        ['/lembur/i', OvertimeRequestResource::class],
        ['/dinas luar/i', FieldDutyRequestResource::class],
        ['/koreksi absen/i', AttendanceCorrectionRequestResource::class],
        ['/izin|cuti/i', LeaveRequestResource::class],
        ['/pengajuan menunggu keputusan/i', LeaveRequestResource::class],
        ['/absensi belum lengkap|belum absen masuk|lupa absen/i', AttendanceResource::class],
        ['/surat peringatan/i', WarningLetterResource::class],
        ['/gaji/i', PayrollResource::class],
        ['/permohonan pembelian/i', PurchaseRequestResource::class],
        ['/jam shift/i', ShiftResource::class],
        ['/jadwal kerja/i', WorkScheduleResource::class],
        ['/kontrak|status kepegawaian/i', ContractExtensionResource::class],
        ['/data karyawan belum lengkap/i', EmployeeDataCompletenessReport::class],
        ['/sertifikat installer/i', EmployeeTrainingResource::class],
        ['/installer/i', TechnicianResource::class],
        ['/dokumen|peraturan/i', CompanyPolicyResource::class],
        ['/pengeluaran/i', FinanceTransactionApprovalRequestResource::class],
        ['/menunggu persetujuan|pengajuan transaksi|pengajuan anda tertahan/i', TransactionApprovalRequestResource::class],
        ['/hutang usaha/i', PayableResource::class],
        ['/piutang/i', ReceivableResource::class],
        ['/penyusutan|aset/i', AssetResource::class],
        ['/perubahan harga/i', ScheduledPriceChangeResource::class],
        ['/garansi/i', WarrantyResource::class],
        ['/review negatif/i', StoreReviewResource::class],
        ['/invoice/i', InvoiceResource::class],
        ['/spk/i', SpkResource::class],
        ['/lead|quotation/i', QuotationResource::class],
        ['/booking|penugasan|jadwal instalasi|jadwal ulang|pembatalan|pesan pelanggan|pengajuan pelanggan/i', BookingResource::class],
        ['/pengguna|akun/i', UserResource::class],
    ];

    /** Kelas tujuan untuk sebuah judul, atau null bila tidak ada yang cocok. */
    public static function targetFor(string $title): ?string
    {
        foreach (self::RULES as [$pattern, $target]) {
            if (preg_match($pattern, $title) === 1) {
                return $target;
            }
        }

        return null;
    }

    /** Alamat halaman tujuan (daftar resource atau halaman), atau null bila tidak bisa dibentuk. */
    public static function url(string $titleOrClass): ?string
    {
        $target = class_exists($titleOrClass) ? $titleOrClass : self::targetFor($titleOrClass);

        if ($target === null || ! class_exists($target)) {
            return null;
        }

        try {
            return is_subclass_of($target, Resource::class)
                ? $target::getUrl('index', panel: self::PANEL)
                : $target::getUrl(panel: self::PANEL);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Tombol untuk notifikasi Filament (Notification::make()->actions(...)). Kosong bila tujuan tidak diketahui.
     * Menekan tombol menandai notifikasi sudah dibaca.
     *
     * @return list<Action>
     */
    public static function actions(string $titleOrClass): array
    {
        $url = self::url($titleOrClass);

        if ($url === null) {
            return [];
        }

        return [Action::make('buka')->label('Buka')->url($url)->button()->markAsRead()];
    }

    /**
     * Bentuk tersimpan (array) untuk baris kotak masuk staf, sama seperti yang ditulis Filament ke tabel notifications.
     *
     * @return list<array<string, mixed>>
     */
    public static function storedActions(string $title): array
    {
        return array_map(fn (Action $action) => $action->toArray(), self::actions($title));
    }
}
