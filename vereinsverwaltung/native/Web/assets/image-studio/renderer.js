/* Canvas artwork rendering; editor controls and history stay outside. */
(() => {
  "use strict";
  function create({ canvas, ctx, image, photo, active, credits = new Map(), attribution = {line_height:44,max_lines:8} }) {
    function textLines(layer) {
      ctx.font = `${layer.font_size}px ${layer.font}`;
      const lines = [];
      for (const para of layer.text.split("\n")) {
        let line = "";
        for (const word of para.split(/\s+/)) {
          if (ctx.measureText(word).width > layer.width)
            throw Error(
              "Ein Wort ist breiter als die Textebene. Schrift verkleinern oder Ebene verbreitern.",
            );
          const next = line ? line + " " + word : word;
          if (line && ctx.measureText(next).width > layer.width) {
            lines.push(line);
            line = word;
          } else line = next;
        }
        lines.push(line);
      }
      if (lines.length * layer.font_size * 1.2 > layer.height)
        throw Error(
          "Text passt nicht in seine Ebene. Schrift verkleinern oder Höhe erhöhen.",
        );
      return lines;
    }
    async function draw(doc, { guides, selected, pen, safeArea, attribution: showCredits = true, hideReminder = false }) {
      let failed = "";
      active();
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.globalAlpha = 1;
      ctx.filter = "none";
      ctx.fillStyle = doc.background;
      ctx.fillRect(0, 0, doc.width, doc.height);
      failed = "";
      for (const l of doc.layers) {
        if (hideReminder && ["reminder-background", "reminder-label"].includes(l.id)) continue;
        ctx.save();
        try {
          ctx.globalAlpha = l.opacity;
          ctx.translate(l.x + l.width / 2, l.y + l.height / 2);
          ctx.rotate((l.rotation * Math.PI) / 180);
          ctx.translate(-l.width / 2, -l.height / 2);
          if (l.type === "text") {
            ctx.fillStyle = l.color;
            ctx.textBaseline = "top";
            for (const [i, line] of textLines(l).entries())
              ctx.fillText(line, 0, i * l.font_size * 1.2);
          } else if (["line", "arrow", "path", "frame"].includes(l.type)) {
            ctx.strokeStyle = l.color;
            ctx.lineWidth = l.stroke_width;
            ctx.lineCap = "round";
            ctx.lineJoin = "round";
            if (l.type === "frame")
              ctx.strokeRect(
                l.stroke_width / 2,
                l.stroke_width / 2,
                l.width - l.stroke_width,
                l.height - l.stroke_width,
              );
            else {
              ctx.beginPath();
              const raw = l.points || [],
                maxX = Math.max(1, ...raw.map((p) => p[0])),
                maxY = Math.max(1, ...raw.map((p) => p[1])),
                points =
                  l.type === "path"
                    ? pen
                      ? raw
                      : raw.map((p) => [
                          (p[0] * l.width) / maxX,
                          (p[1] * l.height) / maxY,
                        ])
                    : [
                        [0, 0],
                        [l.width, l.height],
                      ];
              if (points.length) {
                ctx.moveTo(...points[0]);
                for (const point of points.slice(1)) ctx.lineTo(...point);
              }
              ctx.stroke();
              if (l.type === "arrow") {
                const angle = Math.atan2(l.height, l.width),
                  length = 3 * l.stroke_width;
                ctx.beginPath();
                ctx.moveTo(
                  l.width - length * Math.cos(angle - 0.5),
                  l.height - length * Math.sin(angle - 0.5),
                );
                ctx.lineTo(l.width, l.height);
                ctx.lineTo(
                  l.width - length * Math.cos(angle + 0.5),
                  l.height - length * Math.sin(angle + 0.5),
                );
                ctx.stroke();
              }
            }
          } else if (l.type === "redact") {
            if (l.effect === "cover") {
              ctx.fillStyle = l.color;
              ctx.fillRect(0, 0, l.width, l.height);
            } else {
              const sample = document.createElement("canvas");
              sample.width = Math.max(
                1,
                Math.round(l.width / (l.effect === "pixelate" ? 24 : 1)),
              );
              sample.height = Math.max(
                1,
                Math.round(l.height / (l.effect === "pixelate" ? 24 : 1)),
              );
              sample
                .getContext("2d")
                .drawImage(
                  canvas,
                  l.x,
                  l.y,
                  l.width,
                  l.height,
                  0,
                  0,
                  sample.width,
                  sample.height,
                );
              ctx.imageSmoothingEnabled = l.effect !== "pixelate";
              if (l.effect === "blur") ctx.filter = "blur(18px)";
              ctx.drawImage(sample, 0, 0, l.width, l.height);
            }
          } else if (l.type === "rect") {
            ctx.fillStyle = l.color;
            ctx.fillRect(0, 0, l.width, l.height);
          } else if (l.type === "ellipse") {
            ctx.fillStyle = l.color;
            ctx.beginPath();
            ctx.ellipse(
              l.width / 2,
              l.height / 2,
              l.width / 2,
              l.height / 2,
              0,
              0,
              2 * Math.PI,
            );
            ctx.fill();
          } else {
            const im = l.type === "qr" ? await image("qr") : await photo(l);
            if (l.type === "qr") {
              ctx.imageSmoothingEnabled = false;
              ctx.drawImage(im, 0, 0, l.width, l.height);
            } else {
              if (
                !("filter" in ctx) &&
                (l.brightness !== 100 ||
                  l.contrast !== 100 ||
                  l.saturation !== 100 ||
                  l.filter !== "original")
              )
                throw Error(
                  "Bildfilter sind in diesem Browser nicht verfügbar.",
                );
              const filters = {
                original: "",
                mono: "grayscale(1)",
                warm: "sepia(.3) saturate(1.2)",
                cool: "hue-rotate(18deg) saturate(.85)",
                sepia: "sepia(1)",
                vivid: "saturate(1.5) contrast(1.1)",
                soft: "contrast(.85) brightness(1.08)",
              };
              ctx.filter = `brightness(${l.brightness}%) contrast(${l.contrast}%) saturate(${l.saturation}%) ${filters[l.filter] || ""}`;
              ctx.beginPath();
              ctx.rect(0, 0, l.width, l.height);
              ctx.clip();
              ctx.imageSmoothingEnabled = true;
              if (l.fit === "contain") {
                const ratio = Math.min(
                    l.width / im.naturalWidth,
                    l.height / im.naturalHeight,
                  ),
                  w = im.naturalWidth * ratio,
                  h = im.naturalHeight * ratio;
                if (l.flip) {
                  ctx.translate(l.width, 0);
                  ctx.scale(-1, 1);
                }
                ctx.drawImage(im, (l.width - w) / 2, (l.height - h) / 2, w, h);
                continue;
              }
              const ratio =
                  Math.max(
                    l.width / im.naturalWidth,
                    l.height / im.naturalHeight,
                  ) * l.zoom,
                w = l.width / ratio,
                h = l.height / ratio,
                sx = ((im.naturalWidth - w) * l.focal_x) / 100,
                sy = ((im.naturalHeight - h) * l.focal_y) / 100;
              if (l.flip) {
                ctx.translate(l.width, 0);
                ctx.scale(-1, 1);
              }
              ctx.drawImage(im, sx, sy, w, h, 0, 0, l.width, l.height);
            }
          }
        } catch (e) {
          failed = e.message;
        } finally {
          ctx.restore();
        }
      }
      if (showCredits) {
        try {
          const refs = [...new Set(doc.layers.filter(l=>l.type==='image').map(l=>l.media_ref))].sort();
          const used = refs.map(id=>({id,credit:credits.get(id)||{lines:[]}}));
          if(used.some(s=>s.credit.error)) throw Error(used.find(s=>s.credit.error).credit.error);
          const count=used.reduce((n,s)=>n+s.credit.lines.length,0);
          if(count>attribution.max_lines) throw Error('Bildquellen passen nicht lesbar ins Bild. Weniger Fotos verwenden.');
          if(count){
            const height=count*attribution.line_height+32,y=doc.height-(doc.height===1920?260:52)-height;
            ctx.save();ctx.setTransform(1,0,0,1,0,0);ctx.globalAlpha=1;ctx.filter='none';ctx.fillStyle='#ffffff';ctx.fillRect(0,y,1080,height);
            try{let row=0;for(const {id,credit} of used)if(credit.lines.length){
              const raster=await image('credit-'+id);active();
              if(raster.naturalWidth!==936||raster.naturalHeight!==credit.lines.length*attribution.line_height)throw Error('Bildangaben geändert. Bildstudio neu öffnen.');
              ctx.drawImage(raster,72,y+16+row*attribution.line_height);row+=credit.lines.length;
            }}finally{ctx.restore();}
          }
        }catch(error){failed=error.message;}
      }
      if (guides) {
        const l = selected;
        if (l) {
          ctx.save();
          ctx.translate(l.x + l.width / 2, l.y + l.height / 2);
          ctx.rotate((l.rotation * Math.PI) / 180);
          ctx.strokeStyle = "#ffd257";
          ctx.lineWidth = 5;
          ctx.setLineDash([12, 8]);
          ctx.strokeRect(-l.width / 2, -l.height / 2, l.width, l.height);
          ctx.restore();
        }
        if (doc.height === 1920 && safeArea) {
          ctx.save();
          ctx.strokeStyle = "#ffffff";
          ctx.lineWidth = 2;
          ctx.setLineDash([12, 12]);
          for (const y of [150, 1660]) {
            ctx.beginPath();
            ctx.moveTo(0, y);
            ctx.lineTo(1080, y);
            ctx.stroke();
          }
          ctx.restore();
        }
      }
      return failed;
    }

    return Object.freeze({ draw });
  }
  window.KulturhusImageStudioRenderer = Object.freeze({ create });
})();
