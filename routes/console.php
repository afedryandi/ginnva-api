<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reminder servis berkala — cek tiap pagi, kirim ke booking yang tanggal
// reminder-nya (diset manual oleh store manager di Filament) jatuh tempo
// hari ini. Lihat App\Console\Commands\SendServiceReminders.
Schedule::command('reminders:send-service')->dailyAt('08:00');

// Konfirmasi kedatangan + hanguskan occurrence jadwal maintenance PPF yang
// lewat tanggal tanpa respons -- lihat App\Console\Commands\ProcessMaintenanceSchedules
// (Bagian C, "Klaim Garansi & Maintenance PPF", 2026-10-01).
Schedule::command('maintenance:process-schedules')->dailyAt('08:15');

// Alert bell staff untuk garansi PPF dengan 2+ occurrence maintenance
// forfeited berturut-turut (gap "standar enterprise" ditutup 2026-10-01) --
// lihat App\Console\Commands\NotifyRepeatedMaintenanceForfeits.
Schedule::command('warranty:notify-maintenance-followup')->dailyAt('08:20');

// Pengingat SLA booking pending (>4 jam, ulang tiap 24 jam) -- lihat
// App\Console\Commands\RemindPendingBookings.
Schedule::command('bookings:remind-pending')->hourly();

// Alert bahan baku menipis/kedaluwarsa/tidak bergerak yang belum
// ditinjau — lihat App\Console\Commands\NotifyExpiringMaterials.
Schedule::command('materials:notify-expiring')->dailyAt('07:00');

// Alert aset yang jadwal maintenance-nya jatuh tempo — lihat
// App\Console\Commands\NotifyAssetMaintenanceDue.
Schedule::command('assets:notify-maintenance-due')->dailyAt('07:00');

// Alert kontrak karyawan yang akan berakhir dalam 30 hari — lihat
// App\Console\Commands\NotifyExpiringContracts.
Schedule::command('contracts:notify-expiring')->dailyAt('07:00');

// Nonaktifkan otomatis karyawan kontrak yang tanggal akhirnya sudah
// lewat tanpa diperpanjang — dijadwalkan SETELAH notify-expiring
// (audit Perpanjang Kontrak 2026-09-27). Lihat
// App\Console\Commands\DeactivateExpiredContracts.
Schedule::command('contracts:deactivate-expired')->dailyAt('07:10');

// Ingatkan approver soal pengajuan pengeluaran yang menunggu > 2 hari —
// lihat App\Console\Commands\RemindPendingExpenseApprovals (audit
// Transaksi Keuangan 2026-09-28).
Schedule::command('finance:remind-pending-approvals')->dailyAt('08:30');

// Ingatkan direksi soal pengajuan Referral/Refund/DP yang menunggu > 2 hari —
// lihat App\Console\Commands\RemindPendingTransactionApprovals (audit
// Persetujuan Transaksi 2026-09-29).
Schedule::command('transactions:remind-pending-approvals')->dailyAt('08:35');

// Ingatkan direksi soal Hutang Usaha jatuh tempo (H-3 s/d terlewat) — lihat
// App\Console\Commands\RemindPayableDue (audit Hutang Usaha 2026-09-29).
Schedule::command('payables:remind-due')->dailyAt('08:40');

// Ingatkan direksi soal Piutang Usaha yang perlu ditagih (H-3 s/d terlewat) — lihat
// App\Console\Commands\RemindReceivableDue (audit Piutang Usaha 2026-09-29).
Schedule::command('receivables:remind-due')->dailyAt('08:45');

// Tandai Alpha/Izin untuk hari KEMARIN yang belum punya baris Attendance
// sama sekali — dijadwalkan dini hari supaya "kemarin" sudah pasti hari
// yang selesai penuh. Lihat App\Console\Commands\MarkAbsences.
Schedule::command('attendance:mark-absences')->dailyAt('01:00');

// Alert lead Quotation yang masih 'New' lebih dari 24 jam — lihat
// App\Console\Commands\NotifyStaleQuotations.
Schedule::command('quotations:notify-stale')->dailyAt('08:00');

// Alert garansi yang akan berakhir (bell staff + push customer H-30/H-7) —
// lihat App\Console\Commands\NotifyExpiringWarranties.
Schedule::command('warranty:notify-expiring')->dailyAt('08:00');

// Posting Beban Penyusutan Aset Tetap otomatis untuk BULAN LALU —
// tanggal 1 supaya bulan yang disusutkan sudah selesai penuh. Lihat
// App\Console\Commands\PostAssetDepreciation.
Schedule::command('assets:post-depreciation')->monthlyOn(1, '02:00');

// Backup database harian (audit framework 2026-09-14, "Jadwal backup
// database otomatis") -- otomasi SOP manual RUNBOOK.md §5, dini hari
// supaya tidak bentrok jam sibuk. Lihat App\Console\Commands\BackupDatabase.
Schedule::command('backup:database')->dailyAt('03:00');

// Hapus kode OTP kedaluwarsa (audit framework 2026-09-14, "Retensi &
// penghapusan data historis") -- lihat App\Console\Commands\PruneExpiredOtpCodes.
Schedule::command('otp:prune-expired')->dailyAt('04:00');

// Generate tagihan rutin (audit Majoo f48, "Template tagihan rutin")
// -- lihat App\Console\Commands\GenerateRecurringBills.
Schedule::command('billing:generate-recurring')->dailyAt('05:00')->withoutOverlapping();

// Hapus notifikasi lama (bell Filament + customer + partner) yang sudah
// lewat 180 hari (audit Notifikasi 2026-10-01) -- lihat
// App\Console\Commands\PruneOldNotifications.
Schedule::command('notifications:prune-old')->dailyAt('04:10');

// Gap ditutup 2026-09-26 (audit Absensi Karyawan, "tidak ada notifikasi
// proaktif") -- notif staff lupa clock-out (hari sebelumnya, setelah
// mark-absences supaya tidak tumpang tindih) & staff yang belum absen
// padahal shift sudah mulai (cek tiap jam selama jam operasional wajar).
// Lihat App\Console\Commands\NotifyForgottenClockouts/NotifyMissingClockins.
Schedule::command('attendance:notify-forgotten-clockouts')->dailyAt('08:00');
Schedule::command('attendance:notify-missing-clockins')->hourlyAt(5)->between('07:00', '21:00');
