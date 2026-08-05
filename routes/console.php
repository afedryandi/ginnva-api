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
