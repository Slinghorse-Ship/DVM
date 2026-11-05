// Scoped to preview scrollports: never change page zoom or signature canvases.
export function enablePreviewPan(wrap, content, zoom = null) {
  if (!wrap || !content || wrap.dataset.panReady) return () => {};
  wrap.dataset.panReady = '1';
  wrap.classList.add('document-preview-pan');
  if (zoom) wrap.classList.add('can-pinch');
  wrap.tabIndex = 0;
  wrap.setAttribute('role', 'region');
  wrap.setAttribute('aria-label', window.KulturhusInterface?.text('Dokumentausschnitt. Mit zwei Fingern zoomen, mit einem Finger oder Pfeiltasten verschieben.')??'Dokumentausschnitt. Mit zwei Fingern zoomen, mit einem Finger oder Pfeiltasten verschieben.');
  const controller = new AbortController();
  const on = (target,type,handler) => target.addEventListener(type,handler,{signal:controller.signal});
  const points = new Map();
  let disposed = false;
  let drag = null, pinch = null, pending = null, rendering = false;
  const update = () => wrap.classList.toggle('can-pan', wrap.scrollWidth > wrap.clientWidth + 1 || wrap.scrollHeight > wrap.clientHeight + 1);
  function rebase() {
    pinch = null; drag = null;
    const p = [...points.values()];
    if (p.length === 1) drag = {...p[0], left:wrap.scrollLeft, top:wrap.scrollTop};
    if (p.length === 2 && zoom) {
      const rect = content.getBoundingClientRect(), x=(p[0].x+p[1].x)/2, y=(p[0].y+p[1].y)/2;
      pinch = {distance:Math.max(1,Math.hypot(p[0].x-p[1].x,p[0].y-p[1].y)), scale:zoom.getScale(), u:(x-rect.left)/rect.width, v:(y-rect.top)/rect.height};
    }
    wrap.classList.toggle('is-panning', points.size > 0);
  }
  async function flush() {
    if (rendering || disposed) return;
    rendering = true;
    try {
      while (pending && !disposed) {
        const next=pending; pending=null;
        await zoom.setScale(next.scale);
        if(disposed)return;
        const rect=content.getBoundingClientRect();
        wrap.scrollLeft += rect.left+next.u*rect.width-next.x;
        wrap.scrollTop += rect.top+next.v*rect.height-next.y;
        update();
        // Continuing with one finger starts at the new scroll position.
        if (points.size === 1) rebase();
      }
    } finally {rendering=false;}
  }
  on(wrap, 'pointerdown', event => {
    update();
    if (event.button !== 0 || event.target !== content || points.size >= 2) return;
    if (event.pointerType !== 'touch' && !wrap.classList.contains('can-pan')) return;
    if (event.pointerType === 'touch' && !zoom && !wrap.classList.contains('can-pan')) return;
    points.set(event.pointerId,{id:event.pointerId,x:event.clientX,y:event.clientY});
    wrap.setPointerCapture(event.pointerId); rebase();
    wrap.focus({preventScroll:true}); event.preventDefault();
  });
  on(wrap, 'pointermove', event => {
    if (!points.has(event.pointerId)) return;
    points.set(event.pointerId,{id:event.pointerId,x:event.clientX,y:event.clientY});
    if (pinch && points.size === 2) {
      const p=[...points.values()], distance=Math.hypot(p[0].x-p[1].x,p[0].y-p[1].y);
      pending={scale:Math.max(zoom.min,Math.min(zoom.max,pinch.scale*distance/pinch.distance)),u:pinch.u,v:pinch.v,x:(p[0].x+p[1].x)/2,y:(p[0].y+p[1].y)/2};
      void flush().catch(()=>{}); // Renderer reports its own visible failure.
    } else if (drag && !rendering) {
      wrap.scrollLeft=drag.left+drag.x-event.clientX;
      wrap.scrollTop=drag.top+drag.y-event.clientY;
    }
    event.preventDefault();
  });
  function stop(event) {
    if (!points.delete(event.pointerId)) return;
    if (event.type === 'pointercancel') pending=null;
    if (wrap.hasPointerCapture(event.pointerId)) wrap.releasePointerCapture(event.pointerId);
    rebase();
  }
  for (const name of ['pointerup','pointercancel','lostpointercapture']) on(wrap, name,stop);
  on(content, 'dragstart', event => event.preventDefault());
  const observer = new ResizeObserver(update);observer.observe(wrap);observer.observe(content);
  update.dispose = () => {
    disposed=true;pending=null;controller.abort();observer.disconnect();
    for(const id of points.keys())if(wrap.hasPointerCapture(id))wrap.releasePointerCapture(id);
    points.clear();wrap.classList.remove('is-panning');delete wrap.dataset.panReady;
  };
  update(); return update;
}
