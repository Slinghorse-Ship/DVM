(() => {
  'use strict';
  const menu = document.querySelector('.site-header .site-menu-toggle');
  const nav = document.getElementById('nav');
  if (menu && nav) document.documentElement.classList.add('site-menu-ready');
  const setExpanded = (expanded) => {
    menu?.setAttribute('aria-expanded', String(expanded));
    nav?.classList.toggle('open', expanded);
  };
  menu?.addEventListener('click', () => {
    const expanded = menu.getAttribute('aria-expanded') !== 'true';
    setExpanded(expanded);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && menu?.getAttribute('aria-expanded') === 'true') {
      setExpanded(false);
      menu.focus();
    }
  });
  const mobile = window.matchMedia('(max-width: 600px)');
  mobile.addEventListener('change', (event) => {
    if (!event.matches) setExpanded(false);
  });
})();
