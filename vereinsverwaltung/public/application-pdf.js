import {enablePreviewPan} from './preview-pan.js?v=3';
import {getDocument, GlobalWorkerOptions} from './vendor/pdfjs/build/pdf.min.mjs';
GlobalWorkerOptions.workerSrc = new URL('./vendor/pdfjs/build/pdf.worker.min.mjs', import.meta.url).href;
window.KulturhusPage.register('application-pdf', pageContext => {
 const {fetch, timeout:setTimeout} = pageContext;
 const text=source=>window.KulturhusInterface?.text(source)??source;
 const format=(source,values)=>text(source).replace(/\{([a-z_]+)\}/g,(token,key)=>Object.hasOwn(values,key)?String(values[key]):token);
for (const viewer of document.querySelectorAll('[data-pdf-preview]')) {
  const canvas = viewer.querySelector('canvas');
  const wrap = viewer.querySelector('.pdf-canvas-wrap');

  const status = viewer.querySelector('[data-pdf-status]');
  const label = viewer.querySelector('[data-pdf-page]');
  const prev = viewer.querySelector('[data-pdf-prev]');
  const next = viewer.querySelector('[data-pdf-next]');
  const zoom = viewer.querySelector('[data-pdf-zoom]');
  for(const enhanced of viewer.querySelectorAll('[data-pdf-enhanced]'))enhanced.hidden=false;
  label.textContent=text('PDF wird geladen …');
  viewer.dataset.pdfReady='1';
  let loadingTask, pdf, pageNumber = 1, renderTask, sequence = 0, lastScale = 1, pinchScale = null;
  const updatePan=enablePreviewPan(wrap,canvas,{min:.25,max:2,getScale:()=>lastScale,setScale:async value=>{pinchScale=value;let option=zoom.querySelector('[data-pinch-scale]');if(!option){option=new Option(text('Fingerzoom'),'pinch');option.dataset.pinchScale='1';zoom.add(option);}option.value=String(value);option.textContent=format('{percent} % (Fingerzoom)',{percent:Math.round(value*100)});zoom.value=String(value);try{await render();}catch(error){status.textContent=text('Die Vorschau konnte nicht dargestellt werden. Bitte das Original herunterladen.');throw error;}}});
  pageContext.cleanup(()=>{++sequence;renderTask?.cancel();updatePan.dispose?.();void loadingTask?.destroy().catch(()=>{});});
  async function render() {
    if (!pdf || pageContext.signal.aborted) return;
    const current = ++sequence;
    if (renderTask) { renderTask.cancel(); try { await renderTask.promise; } catch {} }
    const page = await pdf.getPage(pageNumber);
    if (current !== sequence || pageContext.signal.aborted) return;
    const original = page.getViewport({scale: 1});
    const scale = pinchScale ?? (zoom.value === 'fit' ? Math.max(.25, (wrap.clientWidth - 24) / original.width) : Number(zoom.value));
    lastScale=scale;const scaleLabel=viewer.querySelector('[data-pdf-scale]');if(scaleLabel)scaleLabel.textContent=Math.round(scale*100)+' %';
    const viewport = page.getViewport({scale});
    // Bound raster memory even for unusually large PDF page dimensions.
    const resolution = Math.min(window.devicePixelRatio || 1, 2, Math.sqrt(16000000 / (viewport.width * viewport.height)), 16384 / Math.max(viewport.width, viewport.height));
    canvas.width = Math.ceil(viewport.width * resolution);
    canvas.height = Math.ceil(viewport.height * resolution);
    canvas.style.width = `${Math.ceil(viewport.width)}px`;
    canvas.style.height = `${Math.ceil(viewport.height)}px`;
    label.textContent = format('PDF-Seite {page} von {pages}',{page:pageNumber,pages:pdf.numPages});
    canvas.setAttribute('aria-label', format('PDF-Dokument, Seite {page} von {pages}',{page:pageNumber,pages:pdf.numPages}));
    prev.disabled = pageNumber === 1; next.disabled = pageNumber === pdf.numPages;
    renderTask = page.render({canvasContext: canvas.getContext('2d'), viewport, transform: resolution === 1 ? null : [resolution,0,0,resolution,0,0]});
    try { await renderTask.promise; updatePan(); } catch (error) { if (error.name !== 'RenderingCancelledException') throw error; }
  }
  function safeRender() { render().catch(() => {status.textContent = text('Die Vorschau konnte nicht dargestellt werden. Du kannst das Original herunterladen.');}); }
  prev.addEventListener('click', () => {if (pageNumber > 1) {--pageNumber; safeRender();}});
  next.addEventListener('click', () => {if (pdf && pageNumber < pdf.numPages) {++pageNumber; safeRender();}});
  zoom.addEventListener('change',()=>{pinchScale=null;safeRender();});
  for(const [attribute,step] of [['data-pdf-smaller',-.25],['data-pdf-larger',.25]])viewer.querySelector('['+attribute+']')?.addEventListener('click',()=>{pinchScale=null;zoom.value=String(Math.max(.25,Math.min(2,Math.round((lastScale+step)*4)/4)));safeRender();});
  viewer.querySelector('[data-pdf-fit]')?.addEventListener('click',()=>{pinchScale=null;zoom.value='fit';safeRender();});
  let resizeTimer;
  pageContext.on(window, 'resize', () => {clearTimeout(resizeTimer); resizeTimer = setTimeout(safeRender, 150);});
  async function load() { try {
    const source = new URL(viewer.dataset.pdfUrl, location.href);
    if (source.origin !== location.origin) throw new Error('Invalid origin');
    const response = await fetch(source, {credentials:'same-origin', cache:'no-store', redirect:'error'});
    if (!response.ok || !response.headers.get('Content-Type')?.startsWith('application/pdf')) throw new Error('PDF unavailable');
    const bytes = new Uint8Array(await response.arrayBuffer());if(pageContext.signal.aborted)return;
    loadingTask = getDocument({data:bytes, isEvalSupported:false, enableXfa:false,
      cMapUrl:new URL('./vendor/pdfjs/cmaps/', import.meta.url).href, cMapPacked:true,
      standardFontDataUrl:new URL('./vendor/pdfjs/standard_fonts/', import.meta.url).href,
      wasmUrl:new URL('./vendor/pdfjs/wasm/', import.meta.url).href, useWasm:false});
    pdf = await loadingTask.promise;
    if(pageContext.signal.aborted)return;
    await render();
  } catch {if(pageContext.signal.aborted)return;for(const enhanced of viewer.querySelectorAll('[data-pdf-enhanced]'))enhanced.hidden=true;label.textContent = text('Vorschau nicht verfügbar');status.textContent = text('Bitte neu laden oder das Original herunterladen.');}
  }
  void load();
}
});
