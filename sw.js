const CACHE_NAME = 'mi-negocio-pos-v1';

// Instalación del Service Worker
self.addEventListener('install', event => {
    self.skipWaiting();
});

// Activación
self.addEventListener('activate', event => {
    event.waitUntil(clients.claim());
});

// Interceptor de peticiones (Obligatorio para que Chrome permita la instalación)
// Se configura en modo "Network First" (primero internet/localhost, luego caché)
self.addEventListener('fetch', event => {
    event.respondWith(
        fetch(event.request).catch(() => {
            return new Response('Estás sin conexión a la red local.');
        })
    );
});