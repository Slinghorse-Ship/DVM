(() => {
 'use strict';
 const list = document.querySelector('[data-comment-messages][data-comment-newest]');
 if (list && !location.hash) list.scrollTop = list.scrollHeight;
 for (const input of document.querySelectorAll('[data-comment-body]')) {
  const resize = () => {
   input.style.height = 'auto';
   input.style.height = Math.min(Math.max(input.scrollHeight,100),Math.max(180,innerHeight*.4))+'px';
  };
  input.addEventListener('input',resize);
  resize();
 }
})();
