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

// Alert bahan baku menipis/kedaluwarsa/tidak bergerak yang belum
// ditinjau — lihat App\Console\Commands\NotifyExpiringMaterials.
Schedule::command('materials:notify-expiring')->dailyAt('07:00');

// Alert aset yang jadwal maintenance-nya jatuh tempo — lihat
// App\Console\Commands\NotifyAssetMaintenanceDue.
Schedule::command('assets:notify-maintenance-due')->dailyAt('07:00');

// Alert kontrak karyawan yang akan berakhir dalam 30 hari — lihat
// App\Console\Commands\NotifyExpiringContracts.
Schedule::command('contracts:notify-expiring')->dailyAt('07:00');

// Tandai Alpha/Izin untuk hari KEMARIN yang belum punya baris Attendance
// sama sekali — dijadwalkan dini hari supaya "kemarin" sudah pasti hari
// yang selesai penuh. Lihat App\Console\Commands\MarkAbsences.
Schedule::command('attendance:mark-absences')->dailyAt('01:00');

// Alert lead Quotation yang masih 'New' lebih dari 24 jam — lihat
// App\Console\Commands\NotifyStaleQuotations.
Schedule::command('quotations:notify-stale')->dailyAt('08:00');