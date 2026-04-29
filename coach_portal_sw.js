self.addEventListener('install', (event) => {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const fallbackUrl = new URL(`coach_portal.php${self.location.search || ''}`, self.location.origin);
    const targetUrl = new URL(
        event.notification.data && event.notification.data.url ? event.notification.data.url : fallbackUrl.href,
        self.location.origin
    );
    if (!targetUrl.search && fallbackUrl.search) {
        targetUrl.search = fallbackUrl.search;
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientsList) => {
            for (const client of clientsList) {
                if (client.url === targetUrl.href && 'focus' in client) {
                    return client.focus();
                }
            }

            for (const client of clientsList) {
                if ('focus' in client) {
                    if ('navigate' in client) {
                        return client.navigate(targetUrl.href).catch(() => client.focus());
                    }

                    return client.focus();
                }
            }

            if (self.clients.openWindow) {
                return self.clients.openWindow(targetUrl.href);
            }

            return Promise.resolve();
        })
    );
});
