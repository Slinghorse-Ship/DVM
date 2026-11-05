(() => {
  'use strict';
  if (window.KulturhusMemberCardPrint) return;
  window.KulturhusMemberCardPrint = true;
  document.addEventListener('click', event => {
    if (event.target.closest('[data-member-card-print]')) window.print();
  });
})();
