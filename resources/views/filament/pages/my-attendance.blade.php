<x-filament-panels::page>
    @include('filament.partials.plain-ui')

    @once
        <style>
            .pu-cam { width: 100%; max-width: 320px; aspect-ratio: 3 / 4; object-fit: cover; border-radius: 10px; border: 1px solid var(--pu-line); background: #000; }
            .pu-cam.is-mirror { transform: scaleX(-1); }
        </style>
    @endonce

    @php
        $today = $this->today();
        $store = $this->store();
    @endphp

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
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);
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
            }, 'image/jpeg', 0.9);
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
                    Kalau absen ditolak karena lokasi, pastikan lokasi perangkat aktif, browser diizinkan memakai lokasi dan kamera, dan Anda
                    berada di toko. Kalau lupa absen, ajukan koreksi lewat aplikasi staff atau hubungi atasan.
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
