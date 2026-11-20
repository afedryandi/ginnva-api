<x-filament-panels::page>
    @include('filament.partials.plain-ui')

    @once
        <style>
            .pu-cam { width: 100%; max-width: 320px; aspect-ratio: 3 / 4; object-fit: cover; border-radius: 10px; border: 1px solid var(--pu-line); background: #000; }
        </style>
    @endonce

    @php
        $duty = $this->pending();
        $isEnd = $duty && filled($duty->location_photo);
    @endphp

    {{-- Kamera dibuka langsung di halaman (getUserMedia, kamera belakang diutamakan) dan fotonya diambil dari video, jadi tidak ada pilihan galeri.
         Magic Alpine ($wire) dipanggil lewat this.$wire karena berada di dalam method x-data. --}}
    <div class="pu" x-data="{
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
                this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
                this.camOn = true;
                await this.$nextTick();
                this.$refs.video.srcObject = this.stream;
                await this.$refs.video.play();
            } catch (e) {
                this.closeCam();
                if (e && e.name === 'NotAllowedError') {
                    this.camError = 'Izin kamera ditolak. Buka pengaturan izin situs di browser ini, ubah Kamera menjadi Izinkan, lalu muat ulang halaman.';
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
                    new File([blob], 'lokasi.jpg', { type: 'image/jpeg' }),
                    () => { this.uploading = false; },
                    () => { this.uploading = false; this.previewUrl = null; this.camError = 'Foto gagal diunggah, coba lagi.'; }
                );
            }, 'image/jpeg', 0.9);
        }
    }" x-on:beforeunload.window="closeCam()">
        <div class="pu-stack">
            @if (! $duty)
                <div class="pu-bar">Tidak ada dinas luar yang butuh foto hari ini. Foto hanya bisa dikirim pada hari dinas yang sudah disetujui.</div>
            @else
                <div class="pu-bar">
                    Dinas luar hari ini: <span class="pu-strong">{{ $duty->description ?: 'Dinas luar' }}</span>.
                    @if ($isEnd)
                        Foto awal sudah terkirim. Ambil <span class="pu-strong">foto akhir</span> sekarang, saat dinas selesai.
                    @else
                        Ambil <span class="pu-strong">foto awal</span> di lokasi sekarang; foto akhir dikirim nanti saat dinas selesai.
                    @endif
                    Tanpa kedua foto, dinas luar sehari penuh tidak dihitung hadir dan hari ini menjadi Alpha.
                </div>

                <div class="pu-stack">
                    <div x-show="camOn" x-cloak class="pu-stack">
                        <video x-ref="video" class="pu-cam" playsinline muted autoplay></video>
                        <div class="pu-row">
                            <x-filament::button x-on:click="snap()">Ambil Foto</x-filament::button>
                            <x-filament::button color="gray" x-on:click="closeCam()">Batal</x-filament::button>
                        </div>
                    </div>

                    <div x-show="! camOn" class="pu-stack">
                        <template x-if="previewUrl && $wire.photo">
                            <img x-bind:src="previewUrl" alt="Foto dinas luar" class="pu-cam">
                        </template>
                        <div class="pu-row">
                            <x-filament::button color="gray" x-on:click="openCam()">
                                <span x-text="previewUrl && $wire.photo ? 'Foto Ulang' : 'Buka Kamera'"></span>
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

                <div class="pu-row">
                    <x-filament::button wire:click="submitPhoto" x-bind:disabled="uploading || ! $wire.photo">{{ $isEnd ? 'Kirim Foto Akhir' : 'Kirim Foto Awal' }}</x-filament::button>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
