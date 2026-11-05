'use strict';
// This worker has no cache or fetch handler; an update only replaces notification routing.
self.addEventListener('install',event=>event.waitUntil(self.skipWaiting()));
self.addEventListener('activate',event=>event.waitUntil(clients.claim()));
const notifications={
  chat:{body:'Neue Nachricht im Vereinschat.',url:'/vereinsverwaltung/?view=chat'},
  invoices:{body:'Neue Rechnung im Rechnungseingang.',url:'/vereinsverwaltung/?view=finance&tab=inbox'},
  payments:{body:'Neuer Zahlungseingang wurde erfasst.',url:'/vereinsverwaltung/?view=finance&tab=bank'},
  events:{body:'Eine neue Veranstaltung wurde veröffentlicht.',url:'/veranstaltungen/'},
  documents:{body:'Ein neues Dokument liegt in deinem Account bereit.',url:'/vereinsverwaltung/?view=portal&tab=documents'},
  contributions:{body:'Dein Mitgliedsbeitrag ist vollständig bezahlt.',url:'/vereinsverwaltung/?view=portal&tab=documents'},
  minutes:{body:'Ein neues Protokoll wurde für dich freigegeben.',url:'/vereinsverwaltung/?view=minutes'},
  meetings:{body:'Eine neue Einladung zu einer Versammlung liegt für dich vor.',url:'/vereinsverwaltung/?view=calendar'}
};
self.addEventListener('push',event=>{
  let type='chat';
  try{const payload=event.data?.json();if(Object.hasOwn(notifications,payload?.type))type=payload.type;}catch{/* Legacy chat payloads remain supported. */}
  const hint=notifications[type];
  event.waitUntil(self.registration.showNotification('DVM – DeinVereinsManager',{
    body:hint.body,tag:'kulturhus-'+type,
    icon:new URL('push-icon-192.png',self.registration.scope).href,
    data:{type}
  }));
});
self.addEventListener('notificationclick',event=>{
  event.notification.close();
  const type=event.notification.data?.type;
  event.waitUntil(clients.openWindow(Object.hasOwn(notifications,type)?notifications[type].url:notifications.chat.url));
});
