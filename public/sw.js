/*
 * Service worker PWA panel admin Ginnva (2026-11-24). Hanya untuk push web: tidak menyimpan halaman atau berkas di cache, jadi panel selalu
 * memuat versi terbaru dari server. Kalau berkas ini diubah, perangkat memperbaruinya sendiri saat panel dibuka.
 */
const ICON = '/images/pwa/icon-192.png';
const BADGE = '/images/pwa/badge-96.png';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

// Pengendali fetch kosong: permintaan lewat jaringan seperti biasa (diperlukan sebagian browser agar panel bisa dipasang sebagai aplikasi).
self.addEventListener('fetch', () => {});

self.addEventListener('push', (event) => {
  let data = {};

  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = { title: 'Ginnva', body: event.data ? event.data.text() : '' };
  }

  const title = data.title || 'Ginnva';
  const options = {
    body: data.body || '',
    icon: ICON,
    badge: BADGE,
    data: { url: data.url || '/admin' },
    // Pemberitahuan dengan judul sama saling menggantikan, jadi layar kunci tidak penuh oleh pemberitahuan berulang.
    tag: data.tag || title,
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL((event.notification.data && event.notification.data.url) || '/admin', self.location.origin).href;

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      for (const client of windows) {
        if ('focus' in client) {
          if ('navigate' in client) {
            client.navigate(target);
          }

          return client.focus();
        }
      }

      return self.clients.openWindow(target);
    })
  );
});
