(() => {
  'use strict';
  const contentText = source => {
    const read=window.KulturhusInterface?.text;if(!read)return source;
    for(const prefix of ['content:','media:']){const key=prefix+source,value=read(key);if(value!==key)return value;}
    return source;
  };
  const contentFormat=(source,values)=>contentText(source).replace(/\{([a-z_]+)\}/g,(token,key)=>Object.hasOwn(values,key)?String(values[key]):token);
  if (window.KulturhusNativeChatMounted) return;
  window.KulturhusNativeChatMounted = true;
  const scriptOptions = document.currentScript?.dataset || {};
  const defaultStatusUrl = scriptOptions.statusUrl ? scriptOptions.statusUrl + '&item=' : '/chat-status?item=';
  const defaultViewUrl = scriptOptions.viewUrl ? scriptOptions.viewUrl + '&item=' : '/?view=chat&item=';
  const defaultRoomUrl = scriptOptions.roomUrl || '/?view=chat&item=';
  const mounted = new WeakSet();
  const memory = new Map();
  const get = key => { try { return localStorage.getItem(key); } catch { return memory.get(key); } };
  const put = (key, value) => { memory.set(key, value); try { localStorage.setItem(key, value); } catch { /* private browser storage may be unavailable */ } };
  const notificationStates = new Map();
  const originalTitle = document.title;
  let current = null, busy = false, blocked = false;
  function mount() {
    const root = document.querySelector('[data-native-chat]');
    if (!root || mounted.has(root)) return;
    mounted.add(root);
    blocked = false;
    const storage = 'kh-native-chat-' + root.dataset.actor;
    let key = get(storage + '-device');
    if (!key) { key = [...crypto.getRandomValues(new Uint8Array(16))].map(b => b.toString(16).padStart(2, '0')).join(''); put(storage + '-device', key); }
    root.querySelectorAll('[name=device_key]').forEach(input => input.value = key);
    current = { root, storage, stamp: null,
      statusUrl: root.dataset.statusUrl ? root.dataset.statusUrl + '&item=' : defaultStatusUrl,
      viewUrl: root.dataset.viewUrl ? root.dataset.viewUrl + '&item=' : defaultViewUrl,
      roomUrl: root.dataset.roomUrl || defaultRoomUrl,
      catalogue: '&' + new URLSearchParams({chat_query:root.dataset.chatQuery || '',chat_filter:root.dataset.chatFilter || 'all',chat_page:root.dataset.chatPage || '1'}).toString() };
    const scroll = root.querySelector('[data-chat-scroll]');
    const filePicker=root.querySelector('[data-chat-files] input[type=file]');
    if(filePicker){
      const update=()=>{
        const files=[...filePicker.files],list=root.querySelector('[data-chat-file-selection]'),count=root.querySelector('[data-chat-file-count]');
        filePicker.setCustomValidity(files.length>20?contentText("Höchstens zwanzig Anhänge auswählen."):'');
        count.textContent=files.length?'· '+files.length+' ausgewählt':'';
        list.replaceChildren(...files.map(file=>{const li=document.createElement('li');li.textContent=file.name;return li;}));
      };
      filePicker.addEventListener('change',update);update();
    }
    if (scroll && root.dataset.history !== '1') scroll.scrollTop = scroll.scrollHeight;
    const permission = root.querySelector('[data-chat-permission-status]');
    if (permission) permission.textContent = !('Notification' in window) ? contentText("Dieser Browser unterstützt keine Systemhinweise.") : Notification.permission === 'granted' ? contentText("Browsererlaubnis vorhanden. Hinweise funktionieren nur bei geöffneter Anwendung.") : Notification.permission === 'denied' ? contentText("Browsererlaubnis blockiert. Du kannst sie in den Website-Einstellungen ändern.") : contentText("Systemhinweise werden erst nach deinem Klick und deiner Erlaubnis aktiviert.");
    root.addEventListener('input', event => {
      const message = event.target.closest('[data-chat-message]');
      if (message) message.dataset.localEdit = '1';
    });
    root.addEventListener('change', event => {
      const message = event.target.closest('[data-chat-message]');
      if (message) message.dataset.localEdit = '1';
    });
    root.addEventListener('click', async event => {
      const reply = event.target.closest('[data-chat-reply]');
      const composer = root.querySelector('[data-chat-compose]');
      if (reply && composer) {
        event.preventDefault();
        composer.elements.reply_to.value = reply.dataset.chatReply;
        composer.querySelector('[data-chat-reply-label]').textContent = 'Antwort: ' + reply.dataset.replyPreview;
        composer.querySelector('[data-chat-reply-box]').hidden = false;
        composer.elements.body.dispatchEvent(new Event('input', { bubbles: true }));
        composer.elements.body.focus();
      }
      if (event.target.closest('[data-chat-reply-cancel]') && composer) {
        event.preventDefault();
        composer.elements.reply_to.value = ''; composer.querySelector('[data-chat-reply-box]').hidden = true;
      }
      if (event.target.closest('[data-chat-device-disable],[data-chat-devices-disable]')) put(storage + '-enabled', '0');
      if (event.target.closest('[data-chat-permission]')) {
        if (!('Notification' in window) || !window.isSecureContext) { permission.textContent = contentText("Systemhinweise benötigen einen unterstützten Browser und einen sicheren Ursprung."); return; }
        try {
          const result = await Notification.requestPermission(); // Only this explicit user gesture asks.
          if (result !== 'granted') { permission.textContent = contentText("Keine Browsererlaubnis. Die Unterhaltung bleibt hier erreichbar."); return; }
          put(storage + '-enabled', '1');
          const form = root.querySelector('[data-chat-alert-form]'); form.elements.enabled.checked = true; form.requestSubmit();
        } catch { permission.textContent = contentText("Die Browsererlaubnis konnte nicht geöffnet werden."); }
      }
    });
    refresh();
  }
  async function refresh() {
    mount(); const state = current?.root.isConnected ? current : null;
    if (busy || blocked) return;
    busy = true;
    const root = state?.root, id = root?.dataset.thread || '';
    try {
      const response = await fetch((current?.statusUrl || defaultStatusUrl) + encodeURIComponent(id) + (state?.catalogue || ''), { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
      if (!response.ok) { if ([403, 422].includes(response.status)) blocked = true; throw new Error(contentText("Der Chat konnte gerade nicht aktualisiert werden.")); }
      const data = await response.json();
      if (root && (!root.isConnected || current !== state)) return;
      if (!notificationStates.has(data.actor_id)) notificationStates.set(data.actor_id, { storage: 'kh-native-chat-' + data.actor_id, seen: new Set(), initialized: false });
      const alerts = notificationStates.get(data.actor_id);
      const unread = data.unread_total ?? (data.threads || []).reduce((sum, room) => sum + room.unread, 0);
      document.title = (unread ? '(' + unread + ') ' : '') + originalTitle;
      for (const room of data.threads || []) {
        const badge = root?.querySelector('[data-room-id="' + room.id + '"]');
        if (badge) { badge.textContent = String(room.unread); badge.hidden = room.unread === 0; }
      }
      const events = data.events || [];
      const deviceKey = get(alerts.storage + '-device');
      const deviceHash = deviceKey && crypto.subtle ? [...new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(deviceKey)))].map(b => b.toString(16).padStart(2, '0')).join('') : '';
      const deviceEnabled = data.notifications?.devices.some(d => d.device_key === deviceHash && Number(d.enabled) === 1);
      const accountChatEnabled = !data.notifications?.account_devices?.linked || data.notifications.account_devices.enabled;
      if (alerts.initialized && data.notifications?.enabled && accountChatEnabled && deviceEnabled && get(alerts.storage + '-enabled') === '1' && 'Notification' in window && Notification.permission === 'granted' && (document.hidden || !root)) {
        for (const item of events) {
          const token = item.thread_id + ':' + item.message_seq;
          if (alerts.seen.has(token)) continue;
          try {
            const hint = new Notification(contentText("Neue Chat-Nachricht"), { body: contentText("Im Vereinschat gibt es eine neue Nachricht."), tag: 'kh-chat-' + token });
            hint.onclick = () => { window.focus(); hint.close(); location.href = (current?.roomUrl || defaultRoomUrl) + encodeURIComponent(item.thread_id); };
          } catch { /* Some browsers require a service worker; local UI remains available. */ }
        }
      }
      events.forEach(item => alerts.seen.add(item.thread_id + ':' + item.message_seq)); alerts.initialized = true;
      if (!root) return;
      const changed = state.stamp !== null && state.stamp !== data.stamp;
      if (changed && id && root.dataset.history !== '1') {
        const messages = root.querySelector('[data-native-chat-messages]'), live = root.querySelector('[data-chat-live]');
        if (messages.querySelector('[data-local-edit]') || messages.contains(document.activeElement)) {
          live.textContent = contentText("Neue Inhalte verfügbar. Deine Auswahl bleibt erhalten; speichere sie oder aktualisiere anschließend.");
          return;
        }
        const reply = await fetch(state.viewUrl + encodeURIComponent(id), { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
        if (!reply.ok) throw new Error(contentText("Neue Nachrichten konnten nicht geladen werden."));
        const html = reply.headers.get('Content-Type')?.includes('application/json') ? (await reply.json()).html : await reply.text(); if (!root.isConnected || current !== state) return;
        // Input may have begun while this second request was in flight.
        if (messages.querySelector('[data-local-edit]') || messages.contains(document.activeElement)) {
          live.textContent = contentText("Neue Inhalte verfügbar. Deine aktuelle Eingabe bleibt erhalten.");
          return;
        }
        const documentCopy = new DOMParser().parseFromString(html, 'text/html');
        const incoming = documentCopy.querySelector('[data-native-chat-messages]');
        if (!incoming) throw new Error(contentText("Chatantwort unvollständig."));
        const scroll = root.querySelector('[data-chat-scroll]');
        const nearBottom = scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 100;
        messages.replaceChildren(...incoming.childNodes);
        if (nearBottom) scroll.scrollTop = scroll.scrollHeight;
        const read = root.querySelector('input[name=through]'), newRead = documentCopy.querySelector('input[name=through]');
        if (read && newRead) read.value = newRead.value;
        live.textContent = nearBottom ? contentText("Nachrichten und Umfragen aktualisiert.") : contentText("Neue Inhalte unten im Verlauf. Deine Schreibeingabe bleibt erhalten.");
      }
      state.stamp = data.stamp;
    } catch (error) {
      if (root?.isConnected) { const live = root.querySelector('[data-chat-live]'); if (live) live.textContent = error instanceof TypeError ? contentText('Der Chat konnte gerade nicht aktualisiert werden.') : contentText(error.message); }
    } finally { busy = false; }
  }
  window.KulturhusNativeChat = Object.freeze({ mount });
  new MutationObserver(mount).observe(document.documentElement, { subtree: true, childList: true });
  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-chat-open-create],[data-chat-open-people]');
    if (!trigger) return;
    const panel = document.getElementById(trigger.hasAttribute('data-chat-open-create') ? 'chat-create' : 'chat-people');
    if (!panel) return;
    event.preventDefault(); panel.open = true; panel.scrollIntoView({ block: 'nearest' });
    panel.querySelector('input,summary')?.focus();
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, { once: true }); else mount();
  setInterval(refresh, 10000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
})();
