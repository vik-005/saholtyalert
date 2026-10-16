self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        payload = { body: event.data ? event.data.text() : '' };
    }

    const title = payload.title || 'Notification GEI';
    const options = {
        body: payload.body || 'Une nouvelle notification est disponible.',
        icon: '/favicon.svg',
        badge: '/favicon.svg',
        tag: payload.tag || 'gei-notification',
        renotify: Boolean(payload.urgent),
        data: { url: payload.url || '/notifications' },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const requestedUrl = event.notification.data && event.notification.data.url;
    const targetUrl = new URL(
        typeof requestedUrl === 'string' && requestedUrl.startsWith('/') && !requestedUrl.startsWith('//')
            ? requestedUrl
            : '/notifications',
        self.location.origin,
    ).href;

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of windows) {
            if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
                await client.navigate(targetUrl);
                return client.focus();
            }
        }
        return self.clients.openWindow(targetUrl);
    })());
});
