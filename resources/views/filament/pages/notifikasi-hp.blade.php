<x-filament-panels::page>
    {{-- Panel memakai CSS Filament terkompilasi: gaya polos berawalan .nh-, tanpa bergantung pada Tailwind. --}}
    @once
        <style>
            .nh-stack { display: flex; flex-direction: column; gap: 16px; max-width: 640px; }
            .nh-card { padding: 16px 18px; border: 1px solid rgba(107, 114, 128, .3); border-radius: 14px; display: flex; flex-direction: column; gap: 10px; }
            .nh-title { font-size: 15px; font-weight: 700; }
            .nh-text { font-size: 14px; line-height: 1.55; color: rgba(107, 114, 128, 1); }
            .nh-steps { margin: 0; padding-left: 20px; font-size: 14px; line-height: 1.7; }
            .nh-row { display: flex; flex-wrap: wrap; gap: 10px; }
            .nh-btn { min-height: 46px; padding: 0 18px; border-radius: 12px; border: 0; font-size: 15px; font-weight: 700; cursor: pointer; background: #ed1651; color: #fff; }
            .nh-btn.is-ghost { background: transparent; color: inherit; border: 1.5px solid rgba(107, 114, 128, .5); }
            .nh-btn[disabled] { opacity: .45; cursor: default; }
            .nh-pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
            .nh-ok { background: rgba(34, 197, 94, .15); color: #15803d; } .nh-warn { background: rgba(245, 158, 11, .18); color: #b45309; } .nh-off { background: rgba(107, 114, 128, .15); }
            .dark .nh-ok { color: #4ade80; } .dark .nh-warn { color: #fbbf24; }
            .nh-msg { font-size: 14px; font-weight: 600; }
        </style>
    @endonce

    @if (! $this->enabledOnServer())
        <div class="nh-card"><div class="nh-title">Belum aktif di server</div><div class="nh-text">Kunci push belum dipasang. Minta admin menjalankan <code>php artisan webpush:keys</code> di server.</div></div>
    @else
        <div class="nh-stack"
             x-data="webPushSetup({
                publicKey: @js($this->publicKey()),
                subscribeUrl: @js(route('web-push.subscribe')),
                unsubscribeUrl: @js(route('web-push.unsubscribe')),
                testUrl: @js(route('web-push.test')),
             })"
             x-init="init()">

            <div class="nh-card">
                <div class="nh-title">Status perangkat ini</div>
                <div>
                    <span class="nh-pill nh-ok" x-show="state === 'on'" x-cloak>Aktif</span>
                    <span class="nh-pill nh-warn" x-show="state === 'needs-install'" x-cloak>Pasang ke Layar Utama dulu</span>
                    <span class="nh-pill nh-warn" x-show="state === 'denied'" x-cloak>Izin ditolak</span>
                    <span class="nh-pill nh-off" x-show="state === 'off'" x-cloak>Belum aktif</span>
                    <span class="nh-pill nh-off" x-show="state === 'unsupported'" x-cloak>Tidak didukung browser ini</span>
                </div>

                <template x-if="state === 'needs-install'">
                    <div class="nh-text">
                        Di iPhone, notifikasi hanya bisa aktif setelah panel dipasang ke Layar Utama:
                        <ol class="nh-steps">
                            <li>Buka panel ini di <strong>Safari</strong>.</li>
                            <li>Ketuk tombol <strong>Bagikan</strong> (kotak dengan panah ke atas).</li>
                            <li>Pilih <strong>Tambahkan ke Layar Utama</strong>, lalu <strong>Tambah</strong>.</li>
                            <li>Buka panel dari ikon <strong>Ginnva</strong> di Layar Utama, lalu kembali ke halaman ini.</li>
                        </ol>
                        Butuh iOS 16.4 atau lebih baru.
                    </div>
                </template>

                <template x-if="state === 'denied'">
                    <div class="nh-text">Izin notifikasi ditolak. Buka Pengaturan HP, cari <strong>Ginnva</strong> (atau Safari), lalu izinkan Notifikasi. Setelah itu muat ulang halaman ini.</div>
                </template>

                <div class="nh-row">
                    <button type="button" class="nh-btn" x-show="state === 'off'" x-cloak :disabled="busy" @click="enable()">Aktifkan notifikasi</button>
                    <button type="button" class="nh-btn" x-show="state === 'on'" x-cloak :disabled="busy" @click="test()">Kirim tes</button>
                    <button type="button" class="nh-btn is-ghost" x-show="state === 'on'" x-cloak :disabled="busy" @click="disable()">Matikan di perangkat ini</button>
                </div>
                <div class="nh-msg" x-text="message" x-show="message" x-cloak></div>
            </div>

            <div class="nh-card">
                <div class="nh-title">Perangkat yang terdaftar</div>
                @forelse ($this->devices() as $device)
                    <div class="nh-text">{{ $this->deviceLabel($device->user_agent) }} · terakhir dipakai {{ ($device->last_used_at ?? $device->created_at)?->diffForHumans() }}</div>
                @empty
                    <div class="nh-text">Belum ada perangkat. Aktifkan di perangkat yang Anda pakai sehari-hari.</div>
                @endforelse
            </div>

            <div class="nh-card">
                <div class="nh-title">Apa yang dikirim?</div>
                <div class="nh-text">Semua pemberitahuan yang juga muncul di lonceng panel, misalnya permohonan yang menunggu persetujuan, memo barang, dan pengajuan karyawan. Ketuk pemberitahuan untuk langsung membuka halaman terkait.</div>
            </div>
        </div>

    @endif
</x-filament-panels::page>
