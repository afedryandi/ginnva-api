<x-filament-panels::page>
    @include('filament.partials.plain-ui')

    @php
        $today = $this->today();
        $store = $this->store();
    @endphp

    <div class="pu" x-data="{
        busy: false,
        error: null,
        go(kind) {
            this.error = null;
            if (! navigator.geolocation) {
                this.error = 'Browser ini tidak mendukung lokasi.';
                return;
            }
            this.busy = true;
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    $wire.submitClock(kind, pos.coords.latitude, pos.coords.longitude).finally(() => { this.busy = false; });
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
    }">
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
                    <label class="pu-strong" for="selfie">1. Ambil foto selfie</label>
                    <input id="selfie" type="file" accept="image/*" capture="user" wire:model="photo">
                    <div wire:loading wire:target="photo" class="pu-muted pu-small">Mengunggah foto...</div>
                    @if ($photo)
                        <div class="pu-ok pu-small">Foto siap dikirim.</div>
                    @endif
                    @error('photo') <div class="pu-danger pu-small">{{ $message }}</div> @enderror
                </div>

                <div class="pu-stack">
                    <div class="pu-strong">2. Tekan tombol absen</div>
                    <div class="pu-row">
                        @if (! $today?->clock_in_at)
                            <x-filament::button x-on:click="go('in')" x-bind:disabled="busy">Absen Masuk</x-filament::button>
                        @elseif (! $today?->clock_out_at)
                            <x-filament::button color="warning" x-on:click="go('out')" x-bind:disabled="busy">Absen Keluar</x-filament::button>
                        @else
                            <span class="pu-ok pu-strong">Absen hari ini sudah lengkap.</span>
                        @endif
                        <span x-show="busy" class="pu-muted pu-small">Mengambil lokasi...</span>
                    </div>
                    <div x-show="error" x-text="error" class="pu-danger pu-small"></div>
                </div>

                <div class="pu-muted pu-small">
                    Kalau absen ditolak karena lokasi, pastikan lokasi perangkat aktif, browser diizinkan memakai lokasi, dan Anda berada di toko. Kalau lupa absen, ajukan koreksi lewat
                    aplikasi staff atau hubungi atasan.
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
