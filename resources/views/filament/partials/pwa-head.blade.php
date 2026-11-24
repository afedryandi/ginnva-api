{{-- PWA panel admin (2026-11-24): manifest, ikon, dan pendaftaran service worker di semua halaman panel supaya panel bisa dipasang ke Layar Utama. --}}
<link rel="manifest" href="{{ asset('manifest.json') }}">
<meta name="theme-color" content="#ed1651">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Ginnva">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" href="{{ asset('images/pwa/apple-touch-icon.png') }}">
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {});
        });
    }
</script>
{{-- Pengaturan notifikasi (halaman Notifikasi di HP): didefinisikan di head supaya tetap tersedia setelah navigasi Livewire. --}}
<script>
    function webPushSetup(config) {
        return {
            state: 'off',
            busy: false,
            message: '',

            urlBase64ToUint8Array(value) {
                const padding = '='.repeat((4 - (value.length % 4)) % 4);
                const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
                const raw = atob(base64);
                return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
            },

            csrf() {
                const meta = document.querySelector('meta[name="csrf-token"]');
                return meta ? meta.content : '';
            },

            async post(url, body) {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    body: JSON.stringify(body || {}),
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) { throw new Error(data.message || 'Gagal (' + response.status + ').'); }
                return data;
            },

            async init() {
                const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
                const standalone = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;

                if (isIos && !standalone) { this.state = 'needs-install'; return; }
                if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) { this.state = 'unsupported'; return; }
                if (Notification.permission === 'denied') { this.state = 'denied'; return; }

                try {
                    const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
                    await navigator.serviceWorker.ready;
                    const existing = await registration.pushManager.getSubscription();
                    this.state = existing && Notification.permission === 'granted' ? 'on' : 'off';
                } catch (e) {
                    this.state = 'unsupported';
                }
            },

            async enable() {
                this.busy = true; this.message = '';
                try {
                    const permission = await Notification.requestPermission();
                    if (permission !== 'granted') { this.state = permission === 'denied' ? 'denied' : 'off'; this.message = 'Izin notifikasi tidak diberikan.'; return; }

                    const registration = await navigator.serviceWorker.ready;
                    let subscription = await registration.pushManager.getSubscription();
                    if (!subscription) {
                        subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: this.urlBase64ToUint8Array(config.publicKey) });
                    }

                    const json = subscription.toJSON();
                    const encodings = (window.PushManager && PushManager.supportedContentEncodings) || ['aes128gcm'];
                    const data = await this.post(config.subscribeUrl, { endpoint: json.endpoint, keys: json.keys, content_encoding: encodings[0] });
                    this.state = 'on'; this.message = data.message;
                } catch (e) {
                    this.message = e.message || 'Gagal mengaktifkan notifikasi.';
                } finally { this.busy = false; }
            },

            async disable() {
                this.busy = true; this.message = '';
                try {
                    const registration = await navigator.serviceWorker.ready;
                    const subscription = await registration.pushManager.getSubscription();
                    if (subscription) {
                        const endpoint = subscription.endpoint;
                        await subscription.unsubscribe();
                        await this.post(config.unsubscribeUrl, { endpoint });
                    }
                    this.state = 'off'; this.message = 'Notifikasi di perangkat ini dimatikan.';
                } catch (e) {
                    this.message = e.message || 'Gagal mematikan notifikasi.';
                } finally { this.busy = false; }
            },

            async test() {
                this.busy = true; this.message = '';
                try { this.message = (await this.post(config.testUrl)).message; }
                catch (e) { this.message = e.message; }
                finally { this.busy = false; }
            },
        };
    }
</script>
