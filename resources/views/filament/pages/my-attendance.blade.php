<x-filament-panels::page>
    @include('filament.partials.plain-ui')

    @once
        <style>
            .pu-cam { width: 100%; max-width: 320px; aspect-ratio: 3 / 4; object-fit: cover; border-radius: 10px; border: 1px solid var(--pu-line); background: #000; }
            .pu-cam.is-mirror { transform: scaleX(-1); }
            .pu-cards.is-compact { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .pu-section { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; }
            .pu-list { display: flex; flex-direction: column; gap: 8px; width: 100%; }
            .pu-item { display: flex; flex-direction: column; gap: 3px; padding: 10px 12px; border: 1px solid var(--pu-line); border-radius: 10px; }
            .pu-item-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
            .pu-item-title { font-weight: 600; color: var(--pu-ink); }
            .pu-item-status { font-size: 12px; font-weight: 600; white-space: nowrap; }
            .pu-item-meta { font-size: 12px; color: var(--pu-muted); }
            .pu-item-text { font-size: 13px; }
            .pu-item-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 14px; margin-top: 2px; }
        </style>
    @endonce

    @php
        $today = $this->today();
        $store = $this->store();
    @endphp

    <x-filament::tabs>
        <x-filament::tabs.item :active="$this->tab === 'absen'" wire:click="$set('tab', 'absen')" icon="heroicon-o-finger-print">Absen</x-filament::tabs.item>
        <x-filament::tabs.item :active="$this->tab === 'pengajuan'" wire:click="$set('tab', 'pengajuan')" icon="heroicon-o-clipboard-document-list">Pengajuan</x-filament::tabs.item>
        <x-filament::tabs.item :active="$this->tab === 'riwayat'" wire:click="$set('tab', 'riwayat')" icon="heroicon-o-clock">Riwayat</x-filament::tabs.item>
    </x-filament::tabs>

    @if ($this->tab === 'absen')

    {{-- Kamera dibuka langsung di halaman (getUserMedia, kamera depan saja) dan fotonya diambil dari video, jadi tidak ada pilihan galeri atau
         kamera belakang. Magic Alpine ($wire) dipanggil lewat this.$wire karena berada di dalam method x-data. --}}
    <div class="pu" x-data="{
        busy: false,
        error: null,
        camOn: false,
        camError: null,
        uploading: false,
        previewUrl: null,
        stream: null,
        async openCam() {
            this.camError = null;
            if (! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia) {
                this.camError = 'Browser ini tidak mendukung kamera.';
                return;
            }
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { exact: 'user' } }, audio: false });
                this.camOn = true;
                await this.$nextTick();
                this.$refs.video.srcObject = this.stream;
                await this.$refs.video.play();
            } catch (e) {
                this.closeCam();
                if (e && e.name === 'NotAllowedError') {
                    this.camError = 'Izin kamera ditolak. Buka pengaturan izin situs di browser ini, ubah Kamera menjadi Izinkan, lalu muat ulang halaman.';
                } else if (e && (e.name === 'OverconstrainedError' || e.name === 'NotFoundError')) {
                    this.camError = 'Kamera depan tidak ditemukan di perangkat ini.';
                } else {
                    this.camError = 'Kamera belum bisa dibuka. Tutup aplikasi lain yang memakai kamera, lalu coba lagi.';
                }
            }
        },
        closeCam() {
            if (this.stream) { this.stream.getTracks().forEach((t) => t.stop()); }
            this.stream = null;
            this.camOn = false;
        },
        snap() {
            const video = this.$refs.video;
            if (! video || ! video.videoWidth) { return; }
            const canvas = document.createElement('canvas');
            // Foto dikecilkan (sisi terpanjang 1024 px) supaya hemat memori, kuota, dan penyimpanan server.
            const scale = Math.min(1, 1024 / Math.max(video.videoWidth, video.videoHeight));
            canvas.width = Math.round(video.videoWidth * scale);
            canvas.height = Math.round(video.videoHeight * scale);
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            canvas.toBlob((blob) => {
                if (! blob) { this.camError = 'Gagal mengambil foto, coba lagi.'; return; }
                if (this.previewUrl) { URL.revokeObjectURL(this.previewUrl); }
                this.previewUrl = URL.createObjectURL(blob);
                this.closeCam();
                this.uploading = true;
                this.$wire.upload(
                    'photo',
                    new File([blob], 'selfie.jpg', { type: 'image/jpeg' }),
                    () => { this.uploading = false; },
                    () => { this.uploading = false; this.previewUrl = null; this.camError = 'Foto gagal diunggah, coba lagi.'; }
                );
            }, 'image/jpeg', 0.7);
        },
        go(kind) {
            this.error = null;
            if (! navigator.geolocation) {
                this.error = 'Browser ini tidak mendukung lokasi.';
                return;
            }
            const wire = this.$wire;
            this.busy = true;
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    wire.submitClock(kind, pos.coords.latitude, pos.coords.longitude).finally(() => { this.busy = false; });
                },
                (err) => {
                    this.busy = false;
                    this.error = err.code === 1
                        ? 'Izin lokasi ditolak. Buka pengaturan izin situs di browser ini, ubah Lokasi menjadi Izinkan, lalu muat ulang halaman dan coba lagi.'
                        : 'Lokasi belum bisa ditentukan. Pastikan lokasi perangkat aktif dan pindah ke tempat terbuka, lalu coba lagi.';
                },
                { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 }
            );
        }
    }" x-on:beforeunload.window="closeCam()">
        <div class="pu-stack">
            @if (! $store)
                <div class="pu-bar pu-danger">Akun ini belum terhubung ke toko, jadi belum bisa absen. Hubungi admin.</div>
            @else
                <div class="pu-bar">
                    Absen hanya bisa dilakukan di dalam radius <span class="pu-strong">{{ $this->radiusMeters() }} m</span> dari
                    <span class="pu-strong">{{ $store->name }}</span>. Lokasi dan foto selfie diminta saat menekan tombol.
                </div>

                <div class="pu-cards">
                    <div class="pu-card">
                        <div class="pu-card-label">Absen Masuk hari ini</div>
                        <div class="pu-card-value">{{ $today?->clock_in_at?->format('H:i') ?? '—' }}</div>
                        @if ($today && (int) $today->late_minutes > 0)
                            <div class="pu-card-note pu-warn">Terlambat {{ (int) $today->late_minutes }} menit</div>
                        @endif
                    </div>
                    <div class="pu-card">
                        <div class="pu-card-label">Absen Keluar hari ini</div>
                        <div class="pu-card-value">{{ $today?->clock_out_at?->format('H:i') ?? '—' }}</div>
                    </div>
                </div>

                <div class="pu-stack">
                    <div class="pu-strong">1. Ambil foto selfie</div>

                    <div x-show="camOn" x-cloak class="pu-stack">
                        <video x-ref="video" class="pu-cam is-mirror" playsinline muted autoplay></video>
                        <div class="pu-row">
                            <x-filament::button x-on:click="snap()">Ambil Foto</x-filament::button>
                            <x-filament::button color="gray" x-on:click="closeCam()">Batal</x-filament::button>
                        </div>
                    </div>

                    <div x-show="! camOn" class="pu-stack">
                        <template x-if="previewUrl && $wire.photo">
                            <img x-bind:src="previewUrl" alt="Foto selfie" class="pu-cam is-mirror">
                        </template>
                        <div class="pu-row">
                            <x-filament::button color="gray" x-on:click="openCam()">
                                <span x-text="previewUrl && $wire.photo ? 'Foto Ulang' : 'Buka Kamera Depan'"></span>
                            </x-filament::button>
                            <span x-show="uploading" class="pu-muted pu-small">Mengunggah foto...</span>
                        </div>
                    </div>

                    @if ($photo)
                        <div class="pu-ok pu-small">Foto siap dikirim.</div>
                    @endif
                    <div x-show="camError" x-text="camError" class="pu-danger pu-small"></div>
                    @error('photo') <div class="pu-danger pu-small">{{ $message }}</div> @enderror
                </div>

                <div class="pu-stack">
                    <div class="pu-strong">2. Tekan tombol absen</div>
                    <div class="pu-row">
                        @if (! $today?->clock_in_at)
                            <x-filament::button x-on:click="go('in')" x-bind:disabled="busy || uploading">Absen Masuk</x-filament::button>
                        @elseif (! $today?->clock_out_at)
                            <x-filament::button color="warning" x-on:click="go('out')" x-bind:disabled="busy || uploading">Absen Keluar</x-filament::button>
                        @else
                            <span class="pu-ok pu-strong">Absen hari ini sudah lengkap.</span>
                        @endif
                        <span x-show="busy" class="pu-muted pu-small">Mengambil lokasi...</span>
                    </div>
                    <div x-show="error" x-text="error" class="pu-danger pu-small"></div>
                </div>

                <div class="pu-muted pu-small">
                    Absen ditolak karena lokasi? Aktifkan lokasi, izinkan browser memakai lokasi dan kamera, dan pastikan sedang di toko. Lupa absen? Buka tab Riwayat atau Pengajuan, lalu ajukan koreksi.
                </div>
            @endif
        </div>
    </div>
    @elseif ($this->tab === 'riwayat')
    @php
        $history = $this->myHistory();
        $days = $this->myHistoryDays();
        $entryLabel = ['clock' => 'Normal', 'manual' => 'Manual', 'field_duty' => 'Dinas Luar', 'alpha' => 'Alpha (Tanpa Keterangan)', 'leave' => 'Izin/Cuti'];
        $entryClass = ['clock' => 'pu-ok', 'manual' => 'pu-warn', 'field_duty' => 'pu-warn', 'alpha' => 'pu-danger', 'leave' => 'pu-muted'];
        $totalLate = (int) $history->sum('late_minutes');
        $totalEarly = (int) $history->sum('early_leave_minutes');
        $alphaDays = $history->where('entry_type', 'alpha')->count();
        $presentDays = $history->whereIn('entry_type', ['clock', 'manual'])->whereNotNull('clock_in_at')->count();
    @endphp

    <div class="pu">
        <div class="pu-stack">
            <div class="pu-row" style="justify-content: space-between;">
                <div class="pu-strong">Riwayat Absensi Saya</div>
                <x-filament::input.wrapper style="max-width: 220px;">
                    <x-filament::input.select wire:model.live="month">
                        @foreach ($this->monthOptions() as $value => $label)
                            <option value="{{ $value }}" @selected($value === $this->selectedMonth())>{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            <div class="pu-cards is-compact">
                <div class="pu-card"><div class="pu-card-label">Hari hadir</div><div class="pu-card-value">{{ $presentDays }}</div></div>
                <div class="pu-card"><div class="pu-card-label">Total telat</div><div class="pu-card-value">{{ $totalLate }} <span class="pu-small pu-muted">menit</span></div></div>
                <div class="pu-card"><div class="pu-card-label">Total pulang cepat</div><div class="pu-card-value">{{ $totalEarly }} <span class="pu-small pu-muted">menit</span></div></div>
                <div class="pu-card"><div class="pu-card-label">Alpha</div><div class="pu-card-value {{ $alphaDays > 0 ? 'pu-danger' : '' }}">{{ $alphaDays }}</div></div>
            </div>

            @if ($days->isEmpty())
                <div class="pu-muted pu-small">Belum ada catatan absensi di bulan ini.</div>
            @else
                <div class="pu-list">
                    @foreach ($days as $day)
                        @php $row = $day['attendance']; @endphp
                        @if (! $row)
                            {{-- Hari tanpa catatan absen: libur, belum absen hari ini, atau tanpa catatan --}}
                            <div class="pu-item">
                                <div class="pu-item-head">
                                    <div class="pu-item-title">{{ $day['date']->translatedFormat('D, d M Y') }}</div>
                                    <div class="pu-item-status {{ $day['kind'] === 'libur' ? 'pu-ok' : 'pu-muted' }}">{{ \App\Services\AttendanceCalendar::kindLabel($day['kind']) }}</div>
                                </div>
                                @if (filled($day['label']))
                                    <div class="pu-item-meta">{{ $day['label'] }}</div>
                                @endif
                            </div>
                            @continue
                        @endif
                        @php
                            $needsFix = ! $row->date->isToday() && (
                                $row->entry_type === 'alpha'
                                || (in_array($row->entry_type, ['clock', 'manual'], true) && (! $row->clock_in_at || ! $row->clock_out_at))
                            );
                        @endphp
                        <div class="pu-item">
                            <div class="pu-item-head">
                                <div class="pu-item-title">{{ $row->date->translatedFormat('D, d M Y') }}</div>
                                <div class="pu-item-status {{ $entryClass[$row->entry_type] ?? 'pu-muted' }}">{{ $entryLabel[$row->entry_type] ?? $row->entry_type }}</div>
                            </div>
                            @if (in_array($row->entry_type, ['clock', 'manual'], true))
                                <div class="pu-item-text">Masuk {{ $row->clock_in_at?->format('H:i') ?? '—' }} · Keluar {{ $row->clock_out_at?->format('H:i') ?? '—' }}</div>
                            @endif
                            @if ((int) $row->late_minutes > 0 || (int) $row->early_leave_minutes > 0)
                                <div class="pu-item-meta pu-warn">
                                    @if ((int) $row->late_minutes > 0)Telat {{ (int) $row->late_minutes }} menit @endif
                                    @if ((int) $row->early_leave_minutes > 0)Pulang cepat {{ (int) $row->early_leave_minutes }} menit @endif
                                </div>
                            @endif
                            @if (filled($row->note))
                                <div class="pu-item-meta">{{ $row->note }}</div>
                            @endif
                            @if ($needsFix)
                                <div class="pu-item-actions">
                                    {{ ($this->correctionAction)(['date' => $row->date->toDateString()])->label('Ajukan Koreksi')->size('xs')->color('gray')->icon(null) }}
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="pu-muted pu-small">Lupa absen atau absen tidak lengkap? Tekan <span class="pu-strong">Ajukan Koreksi</span> di tanggalnya.</div>
            @endif
        </div>
    </div>
    @else
    @php
        $leaves = $this->myLeaves();
        [$cutiQuota, $cutiUsed, $cutiRemaining] = $this->cutiQuota();
        $partialLeaves = $this->myPartialLeaves();
        $partialService = app(\App\Services\PartialLeaveRequestService::class);
        $fieldDuties = $this->myFieldDuties();
        $corrections = $this->myCorrections();
        $dutyService = app(\App\Services\FieldDutyRequestService::class);
        $statusLabel = ['pending' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', 'cancelled' => 'Dibatalkan', 'revoked' => 'Dicabut'];
        $statusClass = ['pending' => 'pu-warn', 'approved' => 'pu-ok', 'rejected' => 'pu-danger', 'cancelled' => 'pu-muted', 'revoked' => 'pu-muted'];
    @endphp

    <div class="pu">
        <div class="pu-stack">
            <div class="pu-bar">
                Ajukan dari sini, lalu atasan memutuskannya dan Anda mendapat pemberitahuan. Pengajuan yang sudah diputuskan tidak bisa diubah;
                yang masih menunggu bisa dibatalkan.
            </div>

            {{-- Izin / Cuti / Sakit --}}
            <div class="pu-section" style="margin-top: 8px;">
                <div>
                    <div class="pu-strong">Izin, Cuti, dan Sakit</div>
                    <div class="pu-muted pu-small">Jatah cuti {{ today()->year }}: {{ $cutiQuota }} hari, terpakai {{ $cutiUsed }}, sisa {{ $cutiRemaining }}.</div>
                </div>
                {{ $this->leaveAction }}
            </div>

            @if ($leaves->isEmpty())
                <div class="pu-muted pu-small">Belum ada pengajuan izin, cuti, atau sakit.</div>
            @else
                <div class="pu-list">
                    @foreach ($leaves as $leave)
                        @php $leaveStatus = $leave->isRevoked() ? 'revoked' : $leave->status; @endphp
                        <div class="pu-item">
                            <div class="pu-item-head">
                                <div class="pu-item-title">{{ $leave->leaveType?->name ?? ucfirst((string) $leave->type) }}</div>
                                <div class="pu-item-status {{ $statusClass[$leaveStatus] ?? 'pu-muted' }}">{{ $statusLabel[$leaveStatus] ?? $leaveStatus }}</div>
                            </div>
                            <div class="pu-item-text">{{ $leave->start_date->translatedFormat('d M Y') }}@if (! $leave->start_date->isSameDay($leave->end_date)) – {{ $leave->end_date->translatedFormat('d M Y') }}@endif</div>
                            <div class="pu-item-meta">{{ $leave->request_number }} · {{ $leave->reason }}</div>
                            @if ($leave->status !== 'pending' && filled($leave->review_note))
                                <div class="pu-item-meta">Catatan atasan: {{ $leave->review_note }}</div>
                            @endif
                            @if (filled($leave->document) || $leave->status === 'pending')
                                <div class="pu-item-actions">
                                    @if (filled($leave->document))
                                        <a class="pu-link pu-small" href="{{ \App\Support\SensitiveFiles::url($leave->document) }}" target="_blank" rel="noopener">Lihat lampiran</a>
                                    @endif
                                    @if ($leave->status === 'pending')
                                        {{ ($this->cancelLeaveAction)(['id' => $leave->id]) }}
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Izin Sebagian Hari --}}
            <div class="pu-section" style="margin-top: 8px;">
                <div class="pu-strong">Izin Sebagian Hari</div>
                {{ $this->partialLeaveAction }}
            </div>

            @if ($partialLeaves->isEmpty())
                <div class="pu-muted pu-small">Belum ada pengajuan izin sebagian hari.</div>
            @else
                <div class="pu-list">
                    @foreach ($partialLeaves as $partial)
                        <div class="pu-item">
                            <div class="pu-item-head">
                                <div class="pu-item-title">{{ $partial->categoryLabel() }}</div>
                                <div class="pu-item-status {{ $statusClass[$partial->status] ?? 'pu-muted' }}">{{ $statusLabel[$partial->status] ?? $partial->status }}</div>
                            </div>
                            <div class="pu-item-text">{{ $partial->date->translatedFormat('d M Y') }} · {{ $partial->windowLabel() }}</div>
                            <div class="pu-item-meta">{{ $partial->reason }}</div>
                            @if ($partial->status !== 'pending' && filled($partial->review_notes))
                                <div class="pu-item-meta">Catatan atasan: {{ $partial->review_notes }}</div>
                            @endif
                            @if ($partialService->isCancellable($partial))
                                <div class="pu-item-actions">{{ ($this->cancelPartialLeaveAction)(['id' => $partial->id]) }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Dinas Luar --}}
            <div class="pu-section" style="margin-top: 8px;">
                <div class="pu-strong">Dinas Luar</div>
                {{ $this->fieldDutyAction }}
            </div>

            @if ($fieldDuties->isEmpty())
                <div class="pu-muted pu-small">Belum ada pengajuan dinas luar.</div>
            @else
                <div class="pu-list">
                    @foreach ($fieldDuties as $duty)
                        <div class="pu-item">
                            <div class="pu-item-head">
                                <div class="pu-item-title">{{ $duty->date->translatedFormat('d M Y') }} · {{ $duty->windowLabel() }}</div>
                                <div class="pu-item-status {{ $statusClass[$duty->status] ?? 'pu-muted' }}">{{ $statusLabel[$duty->status] ?? $duty->status }}</div>
                            </div>
                            <div class="pu-item-meta">{{ $duty->description }}</div>
                            @if ($duty->status !== 'pending' && filled($duty->review_notes))
                                <div class="pu-item-meta">Catatan atasan: {{ $duty->review_notes }}</div>
                            @endif
                            @php
                                $photoLink = null;
                                if ($duty->status === 'approved' || ($duty->status === 'pending' && $duty->date->isToday())) {
                                    if (blank($duty->location_photo) && $duty->date->isToday()) {
                                        $photoLink = 'Absen Masuk Lokasi';
                                    } elseif (filled($duty->location_photo) && blank($duty->end_photo) && $duty->date->isToday()) {
                                        $photoLink = 'Absen Keluar Lokasi';
                                    }
                                }
                                $photoDone = filled($duty->location_photo) && filled($duty->end_photo);
                            @endphp
                            @if ($photoLink || $photoDone || $dutyService->isCancellable($duty))
                                <div class="pu-item-actions">
                                    @if ($photoLink)
                                        <a class="pu-link pu-small" href="{{ \App\Filament\Pages\FieldDutyPhoto::getUrl() }}">{{ $photoLink }}</a>
                                    @elseif ($photoDone)
                                        <span class="pu-ok pu-small">Foto awal dan akhir lengkap</span>
                                    @endif
                                    @if ($dutyService->isCancellable($duty))
                                        {{ ($this->cancelFieldDutyAction)(['id' => $duty->id]) }}
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="pu-muted pu-small">Foto awal dan akhir dikirim pada hari dinas, setelah pengajuan disetujui (atau di hari dinas selagi masih menunggu).</div>
            @endif

            {{-- Koreksi Absensi --}}
            <div class="pu-section" style="margin-top: 8px;">
                <div class="pu-strong">Koreksi Absensi</div>
                {{ $this->correctionAction }}
            </div>

            @if ($corrections->isEmpty())
                <div class="pu-muted pu-small">Belum ada pengajuan koreksi absensi.</div>
            @else
                <div class="pu-list">
                    @foreach ($corrections as $correction)
                        <div class="pu-item">
                            <div class="pu-item-head">
                                <div class="pu-item-title">{{ $correction->date->translatedFormat('d M Y') }}</div>
                                <div class="pu-item-status {{ $statusClass[$correction->status] ?? 'pu-muted' }}">{{ $statusLabel[$correction->status] ?? $correction->status }}</div>
                            </div>
                            <div class="pu-item-text">Masuk {{ $correction->clock_in_at?->format('H:i') ?? '—' }} · Keluar {{ $correction->clock_out_at?->format('H:i') ?? '—' }}</div>
                            <div class="pu-item-meta">{{ $correction->reason }}</div>
                            @if ($correction->status !== 'pending' && filled($correction->review_notes))
                                <div class="pu-item-meta">Catatan atasan: {{ $correction->review_notes }}</div>
                            @endif
                            @if ($correction->isPending())
                                <div class="pu-item-actions">{{ ($this->cancelCorrectionAction)(['id' => $correction->id]) }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
    @endif
</x-filament-panels::page>
