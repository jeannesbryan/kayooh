const CACHE_NAME = 'kayooh-v3-bunker';

// Aset yang WAJIB ada di cache agar UI tidak hancur saat offline
const STATIC_ASSETS = [
    './',
    './dashboard.php',
    './assets/style.css',
    './assets/kayooh.png',
    './assets/favicon-32x32.png',
    './assets/favicon-16x16.png',
    './assets/site.webmanifest', // Wajib di-cache untuk PWA
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'
];

self.addEventListener('install', event => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(STATIC_ASSETS);
        })
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => {
            return Promise.all(
                keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))
            );
        })
    );
    return self.clients.claim(); // Memaksa SW langsung aktif
});

self.addEventListener('fetch', event => {
    const req = event.request;

    // Strategi 1: Untuk Aset Statis & Leaflet (Cache First, Network Fallback)
    if (req.url.match(/\.(css|js|png|jpg|jpeg|svg|woff2|webmanifest)$/) || req.url.includes('unpkg.com')) {
        event.respondWith(
            caches.match(req).then(cachedRes => {
                return cachedRes || fetch(req).then(fetchRes => {
                    return caches.open(CACHE_NAME).then(cache => {
                        cache.put(req, fetchRes.clone());
                        return fetchRes;
                    });
                });
            })
        );
        return;
    }

    // Strategi 2: Untuk File PHP & Halaman Web (Network First, Cache Fallback + Offline Page)
    event.respondWith(
        fetch(req).then(fetchRes => {
            // Jika berhasil fetch dari internet, simpan diam-diam ke cache
            return caches.open(CACHE_NAME).then(cache => {
                cache.put(req, fetchRes.clone());
                return fetchRes;
            });
        }).catch(async () => {
            // Jika sinyal putus, coba cari halamannya di cache
            const cachedResponse = await caches.match(req);
            if (cachedResponse) {
                return cachedResponse;
            }

            // Jika file tidak ada di cache (URL baru), dan yang diakses adalah halaman HTML (navigasi)
            // Tampilkan "Bunker Offline" ala Kayooh!
            if (req.headers.get('accept').includes('text/html')) {
                return new Response(
                    `<!DOCTYPE html>
                    <html lang="id">
                    <head>
                        <meta charset="UTF-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                        <title>Offline - Kayooh</title>
                        <style>
                            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background-color: #F4F4F9; color: #2C3E50; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; text-align: center; }
                            .box { background: white; padding: 40px 30px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); max-width: 400px; width: 100%; }
                            h2 { color: #FF6600; margin-bottom: 10px; font-weight: 900; letter-spacing: -0.5px; }
                            p { font-size: 14px; color: #7f8c8d; line-height: 1.6; margin-bottom: 25px; }
                            .btn { background: #FF6600; color: white; border: none; padding: 14px 20px; border-radius: 10px; font-weight: bold; width: 100%; cursor: pointer; text-transform: uppercase; font-size: 13px; }
                        </style>
                    </head>
                    <body>
                        <div class="box">
                            <h2 style="font-size: 40px; margin: 0;">📡</h2>
                            <h2>KONEKSI TERPUTUS</h2>
                            <p>Sinyal internet Anda hilang atau sedang berada di <i>blank spot</i>, wak. Silakan periksa koneksi lalu coba muat ulang halaman.</p>
                            <button class="btn" onclick="location.reload()">COBA LAGI</button>
                            <p style="margin-top: 15px; font-size: 11px; opacity: 0.6;">(Aktivitas Record GPS yang sedang berjalan tidak akan terpengaruh)</p>
                        </div>
                    </body>
                    </html>`,
                    { headers: { 'Content-Type': 'text/html' } }
                );
            }
        })
    );
});