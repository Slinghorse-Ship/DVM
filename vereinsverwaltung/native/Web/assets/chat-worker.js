/* Optional receive adapter for a future configured transport. The demo never subscribes or sends. */
self.addEventListener('push', event => {
  event.waitUntil(self.registration.showNotification('Neue Chat-Nachricht', {
    body: 'Im Vereinschat gibt es eine neue Nachricht.', tag: 'kulturhus-chat', data: { url: '/?view=chat' }
  }));
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async windows => {
    const own = windows.find(window => new URL(window.url).origin === self.location.origin);
    if (own) { await own.focus(); return own.navigate('/?view=chat'); }
    return self.clients.openWindow('/?view=chat');
  }));
});
