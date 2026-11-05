window.KulturhusPage.register('xml-text-preview', pageContext => {
'use strict';
for (const section of pageContext.root?.querySelectorAll?.('[data-xml-text-preview]') || document.querySelectorAll('[data-xml-text-preview]')) {
    if (section.dataset.xmlZoomReady) continue;
    section.dataset.xmlZoomReady = '1';
    let scale = 1;
    const nodes = [...section.querySelectorAll('p,dt,dd,h3,h4,summary')];
    const rootSize = parseFloat(getComputedStyle(document.documentElement).fontSize);
    const sizes = nodes.map(node => parseFloat(getComputedStyle(node).fontSize) / rootSize);
    const update = () => {
        nodes.forEach((node, index) => node.style.fontSize = (sizes[index] * scale) + 'rem');
        section.querySelector('[data-xml-scale]').textContent = Math.round(scale * 100) + ' %';
    };
    section.querySelector('[data-xml-smaller]').onclick = () => { scale = Math.max(.75, scale - .25); update(); };
    section.querySelector('[data-xml-larger]').onclick = () => { scale = Math.min(2, scale + .25); update(); };
    section.querySelector('[data-xml-reset]').onclick = () => { scale = 1; update(); };
    section.querySelector('.pdf-controls').hidden = false;
}
});
