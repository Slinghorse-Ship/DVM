(() => {
  'use strict';
  const mounted = new WeakSet();
  const planning = form => {
    if (mounted.has(form)) return;
    mounted.add(form);
    let busy = false, uncertain = false;
    form.addEventListener('submit', async event => {
      event.preventDefault(); event.stopPropagation();
      if (busy || uncertain) return;
      const issue = form.querySelector('[data-planning-error]'), check = form.querySelector('[data-planning-check]');
      const body = new FormData(form, event.submitter), buttons = [...form.querySelectorAll('button')];
      const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 45000);
      busy = true; uncertain = true; form.setAttribute('aria-busy', 'true');
      buttons.forEach(button => button.disabled = true); issue.hidden = true; check.hidden = true;
      let destination = null, failure = form.dataset.planningUnconfirmed;
      try {
        const response = await fetch(form.getAttribute('action') || location.href, {method:'POST',body,credentials:'same-origin',redirect:'error',signal:controller.signal,headers:{Accept:'application/json'}});
        const data = await response.json();
        if (!response.ok) {if ([400,403,404,409,422].includes(response.status) && typeof data.error === 'string') failure = data.error; throw new Error('Unconfirmed planning');}
        const url = new URL(data.url, location.href), saved = new URL(check.href, location.href);
        if (data.planning_saved !== true || url.origin !== location.origin || url.pathname !== saved.pathname || url.searchParams.get('view') !== saved.searchParams.get('view')) throw new Error('Unconfirmed planning');
        destination = url.href;
      } catch (_) {
        if (form.isConnected) {issue.textContent = failure; issue.hidden = false; check.hidden = false;}
      } finally {
        clearTimeout(timer); busy = false; form.removeAttribute('aria-busy');
        if (!form.isConnected) return;
        window.KulturhusNavigation?.markClean(form);
        if (destination) location.assign(destination);
        else {issue.focus(); issue.scrollIntoView({block:'nearest'});}
      }
    });
  };
  const mount = root => {
    root.querySelectorAll('[data-planning-action]').forEach(planning);
    const target = root.querySelector('[data-publication-focus]');
    if (!target) return;
    target.focus({ preventScroll: true });
    target.scrollIntoView({ behavior: 'instant', block: 'start' });
  };
  window.KulturhusPublications = Object.freeze({ mount });
  if (window.KulturhusPage) window.KulturhusPage.register('native-publications', context => context.frame(() => mount(document)));
})();
