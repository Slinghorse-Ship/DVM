/* Own reel controls. The separately licensed local Mediabunny library encodes/muxes actual media. */
(() => {
  "use strict";
  const mounted = new WeakSet(),
    editors = new Map();
  const libraryUrl = new URL(
    "native-mediabunny.mjs",
    document.currentScript.src,
  ).href;
  const library = () => import(libraryUrl);
  let audioExtension = null;
  const audioConfig = {
    sampleRate: 48000,
    numberOfChannels: 2,
    bitrate: 128000,
  };
  async function audioSupport(media) {
    try {
      if (await media.canEncodeAudio("aac", audioConfig)) return true;
      audioExtension ??= import(
        new URL("native-aac-encoder.mjs", libraryUrl).href
      ).then((extension) => extension.registerAacEncoder());
      await audioExtension;
      return await media.canEncodeAudio("aac", audioConfig);
    } catch {
      return false;
    }
  }
  function mount(root = document, context = null) {
    root
      .querySelectorAll("[data-video-studio]")
      .forEach((editor) => initialize(editor, context));
  }
  async function initialize(root, context) {
    if (mounted.has(root)) return;
    mounted.add(root);
    let interfaceMessages = {};
    try { interfaceMessages = JSON.parse(root.dataset.videoInterface || '{}'); } catch (_) {}
    const text = (source, values = {}) => (typeof interfaceMessages[source] === 'string' ? interfaceMessages[source] : source).replace(/\{([a-z_]+)\}/g, (token, key) => Object.hasOwn(values, key) ? String(values[key]) : token);
    const reportError = error => report(Object.hasOwn(interfaceMessages, error.message) ? error.message : 'Video konnte nicht verarbeitet werden. Originaldatei und Bearbeitung bleiben erhalten.');
    const field = (k) => root.querySelector("[data-video-" + k + "]"),
      video = root.querySelector("video"),
      canvas = root.querySelector("canvas"),
      g = (() => { try { return canvas.getContext("2d"); } catch (_) { return null; } })(),
      status = field("status"),
      button = field("export"),
      cancel = field("cancel"),
      progress = field("progress"),
      save = field("save"),
      saveForm = root.querySelector("[data-native-video-form]");
    let media,
      input = null,
      track = null,
      audio = null,
      sink = null,
      sourceUrl = "",
      duration = 0,
      available = false,
      aacAvailable = false,
      audioDecodable = false,
      busy = false,
      stop = false,
      output = null;
    const controller = new AbortController(),
      urls = new Set();
    let disposed = false,
      sourceEpoch = 0;
    const on = (node, type, listener) =>
      node.addEventListener(type, listener, { signal: controller.signal });
    const active = () => {
      if (disposed || !root.isConnected)
        throw new DOMException("Editor geschlossen.", "AbortError");
    };
    const report = (t) => {
      if (!disposed) status.textContent = text(t);
    };
    status.hidden = false;
    const dispose = () => {
      if (disposed) return;
      disposed = true;
      sourceEpoch++;
      stop = true;
      controller.abort();
      video.pause();
      video.removeAttribute("src");
      video.load();
      input?.dispose();
      input = null;
      track = audio = sink = null;
      void output?.cancel().catch(() => {});
      urls.forEach((url) => URL.revokeObjectURL(url));
      urls.clear();
      sourceUrl = "";
      canvas.width = canvas.height = 0;
      mounted.delete(root);
      editors.delete(root);
      root.dataset.videoReady = "0";
    };
    editors.set(root, { dispose, guard: () => !disposed && (busy || !!input) });
    context?.cleanup(dispose);
    context?.guard(() => !disposed && (busy || !!input));
    if (!context)
      window.addEventListener("pagehide", dispose, {
        once: true,
        signal: controller.signal,
      });
    root.reelStudio = Object.freeze({
      state: () => ({
        disposed,
        busy,
        hasInput: !!input,
        objectUrls: urls.size,
      }),
    });
    const options = () => ({
      start: Number(field("start").value),
      end: Number(field("end").value),
      fit: field("fit").value,
      focal: Number(field("focal").value),
      filter: field("filter").value,
      brightness: Number(field("brightness").value),
      contrast: Number(field("contrast").value),
      text: field("text").value,
      color: field("color").value,
      sound: field("audio").checked,
    });
    const speakerLayout = () =>
      !audio || [1, 2, 4, 6].includes(audio.numberOfChannels);
    const toneReady = () =>
      !audio ||
      !field("audio").checked ||
      (audioDecodable && speakerLayout() && aacAvailable);
    const audioNotice = () => {
      if (!audio) return "Video geladen.";
      if (!field("audio").checked) return "Video geladen. Ohne Ton.";
      if (!audioDecodable || !speakerLayout())
        return "Video geladen. Dieser Ton kann hier nicht als Stereo verarbeitet werden. Ton abwählen oder Stereoquelle wählen.";
      if (!aacAvailable)
        return "Video geladen. Tonexport auf diesem Gerät nicht verfügbar. Ton abwählen oder ein anderes Gerät verwenden.";
      return audio.numberOfChannels > 2
        ? "Video geladen. Mehrkanalton wird als Stereo übernommen."
        : "Video geladen.";
    };
    function draw(frame, o) {
      if (
        !("filter" in g) &&
        (o.brightness !== 100 || o.contrast !== 100 || o.filter !== "none")
      )
        throw Error("Videofilter sind in diesem Browser nicht verfügbar.");
      g.setTransform(1, 0, 0, 1, 0, 0);
      g.filter = "none";
      g.fillStyle = "#163f33";
      g.fillRect(0, 0, 1080, 1920);
      const w = frame.videoWidth || frame.width,
        h = frame.videoHeight || frame.height;
      if (!w || !h) return;
      const scale = (o.fit === "contain" ? Math.min : Math.max)(
          1080 / w,
          1920 / h,
        ),
        dw = w * scale,
        dh = h * scale;
      g.filter = `brightness(${o.brightness}%) contrast(${o.contrast}%) ${o.filter}`;
      g.drawImage(
        frame,
        ((1080 - dw) * o.focal) / 100,
        (1920 - dh) / 2,
        dw,
        dh,
      );
      g.filter = "none";
      if (o.text) {
        g.fillStyle = "#10271f";
        g.fillRect(40, 1430, 1000, 270);
        g.fillStyle = o.color;
        g.font = "60px Arial";
        g.textBaseline = "top";
        let line = "",
          lines = [];
        for (const word of o.text.split(/\s+/)) {
          if (g.measureText(word).width > 900)
            throw Error("Reeltext enthält ein zu langes Wort. Text kürzen.");
          const next = line ? line + " " + word : word;
          if (line && g.measureText(next).width > 900) {
            lines.push(line);
            line = word;
          } else line = next;
        }
        lines.push(line);
        if (lines.length > 3)
          throw Error(
            "Text passt nicht in das Reel. Auf höchstens drei Zeilen kürzen.",
          );
        lines.forEach((line, i) => g.fillText(line, 90, 1470 + i * 72));
      }
    }
    function preview() {
      if (disposed || busy || !video.videoWidth) return;
      try {
        draw(video, options());
      } catch (e) {
        reportError(e);
      }
    }
    try {
      if (!g) throw Error("Die Videovorschau ist in diesem Browser nicht verfügbar.");
      if (!isSecureContext || typeof VideoEncoder === "undefined")
        throw Error(
          "H.264-Export benötigt einen Browser mit WebCodecs über HTTPS.",
        );
      media = await library();
      active();
      available = await media.canEncodeVideo("avc", {
        width: 1080,
        height: 1920,
        frameRate: 30,
        bitrate: 6000000,
        fullCodecString: "avc1.420028",
      });
      active();
      if (!available)
        throw Error(
          "Dieser Browser bietet keinen H.264-Encoder für 1080 × 1920. Export hier nicht verfügbar.",
        );
      root.querySelector("[data-video-client]")?.removeAttribute("hidden");
      aacAvailable = await media.canEncodeAudio("aac", {
        sampleRate: 48000,
        numberOfChannels: 2,
        bitrate: 128000,
      });
      active();
      root.dataset.videoEncoder = "avc";
      root.dataset.videoAac = aacAvailable ? "1" : "0";
      report("Video wählen.");
    } catch (e) {
      reportError(e);
      return;
    }
    on(field("file"), "change", async () => {
      if (busy || disposed) return;
      const epoch = ++sourceEpoch;
      root.dataset.videoSource = "0";
      button.disabled = true;
      if (save) save.disabled = true;
      input?.dispose();
      input = null;
      track = audio = sink = null;
      video.pause();
      video.removeAttribute("src");
      video.load();
      if (sourceUrl) {
        URL.revokeObjectURL(sourceUrl);
        urls.delete(sourceUrl);
        sourceUrl = "";
      }
      const file = field("file").files[0];
      if (!file) return;
      const current = () => {
        active();
        if (epoch !== sourceEpoch)
          throw new DOMException("Videoauswahl geändert.", "AbortError");
      };
      try {
        if (file.size > 100 * 1024 * 1024)
          throw Error("Originalvideo überschreitet 100 MiB.");
        const candidate = new media.Input({
          source: new media.BlobSource(file),
          formats: [media.MP4, media.QTFF, media.WEBM],
        });
        input = candidate;
        const v = await candidate.getPrimaryVideoTrack();
        current();
        if (
          !v ||
          !(await v.canDecode()) ||
          v.displayWidth * v.displayHeight > 16000000
        )
          throw Error(
            "Video kann nicht sicher dekodiert werden oder überschreitet 16 Megapixel.",
          );
        current();
        const d = await candidate.computeDuration();
        current();
        if (!Number.isFinite(d) || d < 3 || d > 900)
          throw Error(
            "Originalvideo benötigt 3 Sekunden bis 15 Minuten Laufzeit.",
          );
        const a = await candidate.getPrimaryAudioTrack();
        current();
        const decodable = !a || (await a.canDecode());
        current();
        if (
          a &&
          decodable &&
          [1, 2, 4, 6].includes(a.numberOfChannels) &&
          field("audio").checked &&
          !aacAvailable
        ) {
          report("Ton wird vorbereitet …");
          aacAvailable = await audioSupport(media);
          current();
          root.dataset.videoAac = aacAvailable ? "1" : "0";
        }
        track = v;
        audio = a;
        audioDecodable = decodable;
        root.dataset.videoAudioChannels = a ? String(a.numberOfChannels) : "0";
        duration = d;
        sink = new media.CanvasSink(track, { poolSize: 2 });
        sourceUrl = URL.createObjectURL(file);
        urls.add(sourceUrl);
        video.src = sourceUrl;
        field("start").value = "0";
        field("end").value = String(Math.min(duration, 90));
        button.disabled = !available || !toneReady();
        if (save) save.disabled = button.disabled;
        root.dataset.videoSource = "1";
        report(audioNotice());
      } catch (e) {
        if (disposed || epoch !== sourceEpoch) return;
        input?.dispose();
        input = null;
        track = audio = sink = null;
        reportError(e);
      }
    });
    on(video, "loadeddata", preview);
    on(video, "seeked", preview);
    on(video, "timeupdate", preview);
    root.querySelectorAll("input,select,textarea").forEach((el) =>
      on(el, "input", async () => {
        if (
          !busy &&
          el === field("audio") &&
          el.checked &&
          audio &&
          audioDecodable &&
          speakerLayout() &&
          !aacAvailable
        ) {
          const epoch = sourceEpoch;
          report("Ton wird vorbereitet …");
          const supported = await audioSupport(media);
          if (disposed || sourceEpoch !== epoch) return;
          aacAvailable = supported;
          root.dataset.videoAac = supported ? "1" : "0";
          report(audioNotice());
        }
        if (!busy) {
              button.disabled = !available || !input || !track || !toneReady();
          if (save) save.disabled = button.disabled;
        }
        if (el === field("audio") && !busy) report(audioNotice());
        preview();
      }),
    );
    on(cancel, "click", () => {
      stop = true;
      report("Export wird abgebrochen …");
    });
    const exportVideo = async (destination = null) => {
      if (destination && !window.KulturhusVideoUploads?.writable(destination)) { report('Für diese Aktion fehlt die Berechtigung.'); return; }
      if (destination && window.KulturhusVideoUploads?.pending(destination)) { await window.KulturhusVideoUploads.retry(destination); return; }
      if (disposed || busy || !input || !track || !available) return;
      const o = options();
      if (o.sound && audio && !toneReady()) {
        report(audioNotice());
        return;
      }
      const length = Math.round((o.end - o.start) * 30) / 30;
      if (
        !Number.isFinite(o.start) ||
        !Number.isFinite(o.end) ||
        o.start < 0 ||
        o.end > duration + 0.001 ||
        length < 3 ||
        length > 90
      ) {
        report("Schnitt muss im Original liegen und 3–90 Sekunden lang sein.");
        return;
      }
      busy = true;
      stop = false;
      video.pause();
      button.disabled = true;
      if (save) save.disabled = true;
      let submit = null, generatedFile = null;
      cancel.disabled = false;
      progress.value = 0;
      const editing = [...root.querySelectorAll("input,select,textarea")];
      editing.forEach((el) => (el.disabled = true));
      try {
        await document.fonts.ready;
        active();
        const target = new media.BufferTarget();
        output = new media.Output({
          format: new media.Mp4OutputFormat({ fastStart: "in-memory" }),
          target,
        });
        const source = new media.CanvasSource(canvas, {
          codec: "avc",
          bitrate: 6000000,
          keyFrameInterval: 2,
          fullCodecString: "avc1.420028",
          alpha: "discard",
        });
        output.addVideoTrack(source, { frameRate: 30 });
        let soundSource = null;
        if (o.sound && audio) {
          if (
            !(await media.canEncodeAudio("aac", {
              sampleRate: 48000,
              numberOfChannels: 2,
              bitrate: 128000,
            }))
          )
            throw Error(
              "AAC-Tonexport ist in diesem Browser nicht verfügbar. Originalton ausdrücklich abwählen oder einen geeigneten Browser verwenden.",
            );
          soundSource = new media.AudioBufferSource({
            codec: "aac",
            bitrate: 128000,
          });
          output.addAudioTrack(soundSource);
        }
        await output.start();
        active();
        const frames = Math.round(length * 30);
        const times = Array.from(
          { length: frames },
          (_, i) => o.start + i / 30,
        );
        let i = 0;
        for await (const frame of sink.canvasesAtTimestamps(times)) {
          active();
          if (stop) throw Error("Export abgebrochen.");
          if (!frame)
            throw Error(
              "Ein Bild im Schnittbereich konnte nicht dekodiert werden.",
            );
          draw(frame.canvas, o);
          await source.add(i / 30, 1 / 30);
          i++;
          progress.value = (i / frames) * 90;
          report(text("MP4-Export: {percent} %", {percent:Math.round((i / frames) * 90)}));
          if (i % 10 === 0) await new Promise((ok) => setTimeout(ok, 0));
        }
        if (soundSource) {
          report("Ton wird verarbeitet …");
          const rate = audio.sampleRate,
            channels = audio.numberOfChannels,
            samples = Math.ceil(length * rate);
          if (rate > 96000 || channels > 8 || samples * channels > 18000000)
            throw Error(
              "Originalton überschreitet die sichere Speichergrenze.",
            );
          const segment = new AudioBuffer({
              numberOfChannels: channels,
              length: samples,
              sampleRate: rate,
            }),
            audioSink = new media.AudioBufferSink(audio);
          for await (const part of audioSink.buffers(
            o.start,
            o.start + length,
          )) {
            active();
            if (stop) throw Error("Export abgebrochen.");
            const from = Math.max(
                0,
                Math.round((o.start - part.timestamp) * rate),
              ),
              to = Math.min(
                part.buffer.length,
                Math.round((o.start + length - part.timestamp) * rate),
              ),
              dest = Math.max(0, Math.round((part.timestamp - o.start) * rate));
            if (to > from)
              for (let c = 0; c < segment.numberOfChannels; c++)
                segment
                  .getChannelData(c)
                  .set(part.buffer.getChannelData(c).subarray(from, to), dest);
          }
          const offline = new OfflineAudioContext(
              2,
              Math.ceil(length * 48000),
              48000,
            ),
            bufferSource = offline.createBufferSource();
          bufferSource.buffer = segment;
          bufferSource.channelInterpretation = "speakers";
          bufferSource.connect(offline.destination);
          bufferSource.start();
          await soundSource.add(await offline.startRendering());
        }
        active();
        if (stop) throw Error("Export abgebrochen.");
        await output.finalize();
        active();
        const bytes = target.buffer;
        if (!bytes || bytes.byteLength > 100 * 1024 * 1024)
          throw Error("MP4-Ausgabe fehlt oder überschreitet 100 MiB.");
        const blob = new Blob([bytes], { type: "video/mp4" }),
          verify = new media.Input({
            source: new media.BlobSource(blob),
            formats: [media.MP4],
          });
        try {
          const v = await verify.getPrimaryVideoTrack(),
            a = await verify.getPrimaryAudioTrack(),
            d = await verify.computeDuration();
          if (
            !v ||
            (await v.getCodec()) !== "avc" ||
            v.displayWidth !== 1080 ||
            v.displayHeight !== 1920 ||
            Math.abs(d - length) > 0.15 ||
            (soundSource && (!a || (await a.getCodec()) !== "aac"))
          )
            throw Error("Exportprüfung für Video-/Tonspuren fehlgeschlagen.");
        } finally {
          verify.dispose();
        }
        active();
        if (destination) {
          generatedFile = new File([blob], "vereins-reel.mp4", { type: "video/mp4" });
          submit = destination;
          progress.value = 100;
          report("Videofassung wird gespeichert …");
        } else {
          const url = URL.createObjectURL(blob),
            link = document.createElement("a");
          urls.add(url);
          link.href = url;
          link.download = "vereins-reel.mp4";
          link.click();
          (context ? context.timeout : setTimeout)(() => {
            URL.revokeObjectURL(url);
            urls.delete(url);
          }, 30000);
          progress.value = 100;
          report(soundSource ? "MP4 mit Ton geprüft und heruntergeladen." : "MP4 ohne Ton geprüft und heruntergeladen.");
        }
      } catch (e) {
        await output?.cancel().catch(() => {});
        reportError(e);
      } finally {
        busy = false;
        output = null;
        if (disposed) return;
        button.disabled = !available || !toneReady();
        cancel.disabled = true;
        editing.forEach((el) => (el.disabled = false));
        if (save) save.disabled = button.disabled;
        preview();
        if (submit) {
          submit.closest(".video-ready-upload")?.setAttribute("open", "");
          await window.KulturhusVideoUploads.saveGenerated(submit, generatedFile);
        }
      }
    };
    on(button, "click", () => exportVideo());
    if (save && saveForm) on(save, "click", () => void exportVideo(saveForm));
    if (window.KulturhusPage) root.dataset.videoReady = "1";
  }
  window.KulturhusVideoStudio = Object.freeze({
    mount,
    unmount: (scope) => {
      for (const [root, editor] of editors)
        if (scope.contains(root)) editor.dispose();
    },
    guarded: () => [...editors.values()].some((editor) => editor.guard()),
  });
  if (window.KulturhusPage)
    window.KulturhusPage.register("video-studio", (context) =>
      mount(context.root, context),
    );
  if (!window.KulturhusPage) {
    if (document.readyState === "loading")
      document.addEventListener("DOMContentLoaded", () => mount(), {
        once: true,
      });
    else mount();
  }
})();

/* Keep the existing preset operation inside its editor without replacing unsaved text. */
(()=>{'use strict';
 if(window.KulturhusReelPresetEvents)return;window.KulturhusReelPresetEvents=true;
 document.addEventListener('submit',async event=>{
  const form=event.target;if(!(form instanceof HTMLFormElement)||!form.matches('[data-reel-preset-form][data-reel-inline]'))return;
  event.preventDefault();event.stopImmediatePropagation();if(form.dataset.reelBusy==='1')return;
  const panel=form.closest('[data-reel-item]'),status=panel?.querySelector('[data-reel-status]');if(!panel||!status)return;
  const body=new FormData(form,event.submitter),id=body.get('id'),key=body.get('reel_request_key'),version=body.get('version');
  const origin=new URL(form.action||location.href,location.href);if(origin.origin!==location.origin)return;
  form.dataset.reelBusy='1';form.setAttribute('aria-busy','true');const controls=[...form.querySelectorAll('input,select,button')].map(control=>[control,control.disabled]);for(const [control]of controls)control.disabled=true;
  status.textContent=status.dataset.busy;
  const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),120000);
  try{
   const response=await fetch(origin.href,{method:'POST',body,credentials:'same-origin',cache:'no-store',redirect:'error',headers:{Accept:'text/html'},signal:controller.signal});
   const parsed=new DOMParser().parseFromString(await response.text(),'text/html'),next=[...parsed.querySelectorAll('[data-reel-item]')].find(node=>node.dataset.reelItem===id);
   if((!response.ok&&response.status!==422)||!next||next.querySelector('[name=reel_request_key]')?.value!==key||next.querySelector('[name=version]')?.value!==version)throw Error('Unconfirmed preset result');
   const error=next.querySelector('[role=alert]');if(error||!next.querySelector('video')){status.textContent=(error||next.querySelector('[role=status]:not([data-reel-status])'))?.textContent||status.dataset.failure;status.setAttribute('role',error?'alert':'status');return;}
   const savedVersion=Number(next.dataset.reelSavedVersion),main=panel.closest('.compose-main')?.querySelector('#social-compose form'),parentVersion=main?.elements.namedItem('version');
   if(parentVersion?.value===version&&Number.isSafeInteger(savedVersion)&&savedVersion===Number(version)+1)parentVersion.value=String(savedVersion);
   window.KulturhusNativeForms?.clean(form);window.KulturhusNavigation?.markClean(form);
   const replacement=document.importNode(next,true);panel.replaceWith(replacement);replacement.querySelector('[data-reel-status]')?.focus?.({preventScroll:true});if(replacement.querySelector('video')&&!replacement.querySelector('[role=alert]'))replacement.dispatchEvent(new CustomEvent('kulturhus:social-save-state',{bubbles:true,detail:{videoSaved:true}}));
  }catch{status.textContent=status.dataset.failure;}
  finally{clearTimeout(timer);delete form.dataset.reelBusy;form.removeAttribute('aria-busy');for(const [control,disabled]of controls)control.disabled=disabled;}
 },true);
 document.addEventListener('change',event=>{
  const select=event.target;if(!(select instanceof HTMLSelectElement)||select.name!=='animation')return;
  const form=select.closest('[data-reel-preset-form][data-reel-inline]'),panel=form?.closest('[data-reel-item]');if(!form||form.dataset.reelBusy==='1'||!panel)return;
  const version=form.elements.namedItem('version'),key=form.elements.namedItem('reel_request_key'),savedVersion=Number(panel.dataset.reelSavedVersion);
  if(!version||!key||!Number.isSafeInteger(savedVersion)||savedVersion!==Number(version.value)+1)return;
  version.value=String(savedVersion);key.value=[...crypto.getRandomValues(new Uint8Array(16))].map(byte=>byte.toString(16).padStart(2,'0')).join('');delete panel.dataset.reelSavedVersion;
 },true);
})();

/* One save summary, while every editor retains its original guarded save boundary. */
(()=>{'use strict';
 const mounted=new WeakSet();
 function mount(scope=document,context=null){
  for(const status of scope.querySelectorAll('[data-social-save-state]')){
   if(mounted.has(status))continue;mounted.add(status);
   const workspace=status.closest('.social-editor-workflow')?.parentElement,form=workspace?.querySelector('#social-compose form');if(!workspace||!form)continue;
   const content=()=>JSON.stringify([...new FormData(form)].filter(([name,value])=>typeof value==='string'&&!['csrf','save_request_key','version','identity_version','id','operation','view'].includes(name)));
   const controller=new AbortController(),initial=content();let videoDirty=false,queued=false;
   const update=()=>{queued=false;if(!status.isConnected)return;const parts=[];
    const textChanged=content()!==initial;if(textChanged)parts.push(status.dataset.text);
    if(window.KulturhusImageStudio?.guarded(workspace))parts.push(status.dataset.image);
    if(videoDirty)parts.push(status.dataset.video);
    status.dataset.state=parts.length?'dirty':'clean';status.textContent=parts.length?status.dataset.pending.replace('{parts}',parts.join(', ')):status.dataset.clean;
    const reminder=workspace.querySelector('[data-social-design-reminder]');if(reminder)reminder.hidden=!textChanged;
   };
   const refresh=event=>{if(event?.detail?.videoSaved)videoDirty=false;else if(event?.target?.closest?.('[data-video-studio],[data-reel-item]'))videoDirty=true;if(!queued){queued=true;queueMicrotask(update);}};
   for(const type of ['input','change','kulturhus:social-save-state'])workspace.addEventListener(type,refresh,{signal:controller.signal});
   context?.cleanup(()=>controller.abort());update();
  }
 }
 if(window.KulturhusPage)window.KulturhusPage.register('social-editor-save-state',context=>mount(context.root,context));
 else if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>mount(),{once:true});else mount();
})();
