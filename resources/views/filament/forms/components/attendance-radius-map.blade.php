{{--
    Pratinjau peta radius absen — audit Majoo vs Ginnva ("Radius Absensi
    diset via peta interaktif"), dibangun 2026-09-22. Leaflet + tile
    OpenStreetMap (gratis, tanpa API key) — bukan Google Maps JS API yang
    butuh billing/API key.

    Re-render setiap kali latitude/longitude/attendance_radius_meters
    berubah (field-field itu ->live()). wire:key acak MEMAKSA Livewire
    menganggap elemen ini baru tiap render (bukan di-patch di tempat) —
    tanpa ini, <script>/x-init yang disuntik lewat morphdom tidak
    reliable dieksekusi ulang.
--}}
@php
    $wireKey = 'radius-map-' . \Illuminate\Support\Str::random(8);
@endphp

<div>
    @if ($lat && $lng)
        <div
            wire:key="{{ $wireKey }}"
            x-data
            x-init="
                (function ensureLeaflet(cb) {
                    if (window.L) { cb(); return; }
                    if (!window.__leafletLoadingPromise) {
                        window.__leafletLoadingPromise = new Promise((resolve) => {
                            const link = document.createElement('link');
                            link.rel = 'stylesheet';
                            link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                            document.head.appendChild(link);
                            const script = document.createElement('script');
                            script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                            script.onload = () => resolve();
                            document.head.appendChild(script);
                        });
                    }
                    window.__leafletLoadingPromise.then(cb);
                })(() => {
                    const map = L.map($el).setView([{{ $lat }}, {{ $lng }}], 16);
                    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; OpenStreetMap contributors',
                        maxZoom: 19,
                    }).addTo(map);
                    L.marker([{{ $lat }}, {{ $lng }}]).addTo(map);
                    L.circle([{{ $lat }}, {{ $lng }}], {
                        radius: {{ (float) $radius }},
                        color: '#ed1651',
                        fillColor: '#ed1651',
                        fillOpacity: 0.15,
                    }).addTo(map);
                });
            "
            style="height: 320px; border-radius: 0.5rem; overflow: hidden;"
        ></div>
        <p style="font-size: 12px; color: rgb(107 114 128); margin-top: 6px;">
            Lingkaran menunjukkan radius absen ({{ number_format((float) $radius, 0, ',', '.') }} m) dari titik lokasi toko — staff cuma bisa absen dari dalam area ini.
        </p>
    @else
        <div style="padding: 24px; text-align: center; color: rgb(107 114 128); border: 1px dashed rgb(209 213 219); border-radius: 0.5rem; font-size: 13px;">
            Isi Latitude &amp; Longitude dulu (lewat "Tempel Link Google Maps" di atas, atau manual) untuk lihat pratinjau peta radius.
        </div>
    @endif
</div>
