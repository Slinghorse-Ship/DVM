(() => {
  "use strict";
  const contentText = source => {
    const read=window.KulturhusInterface?.text;if(!read)return source;
    for(const prefix of ['content:','media:']){const key=prefix+source,value=read(key);if(value!==key)return value;}
    return source;
  };
  const contentFormat=(source,values)=>contentText(source).replace(/\{([a-z_]+)\}/g,(token,key)=>Object.hasOwn(values,key)?String(values[key]):token);
  if (window.KulturhusGallery) return;
  const pending = new WeakMap();
  const active = new Set();
  const cancel = (picker) => {
    pending.get(picker)?.abort();
    pending.delete(picker);
    active.delete(picker);
  };
  const search = async (picker, action) => {
    cancel(picker);
    const select = picker.querySelector("[data-reference-select]");
    const input = picker.querySelector("[data-reference-query]");
    const status = picker.querySelector("[data-reference-status]");
    const controls = [...picker.querySelectorAll("[data-reference-action]")];
    const controller = new AbortController();
    pending.set(picker, controller);
    active.add(picker);
    const current = Number(picker.dataset.referencePage);
    const page =
      action === "next" ? current + 1 : action === "previous" ? current - 1 : 1;
    const url = new URL(
      picker.closest("[data-reference-url]")?.dataset.referenceUrl ||
        "/reference-options",
      location.origin,
    );
    if (url.origin !== location.origin) return;
    const query = input.value;
    for (const [key, value] of Object.entries({
      kind: picker.dataset.referenceKind,
      q: query,
      page: String(page),
      selected: select.value,
      exclude: picker.dataset.referenceExclude,
    }))
      url.searchParams.set(key, value);
    controls.forEach((button) => (button.disabled = true));
    picker.setAttribute("aria-busy", "true");
    status.textContent = contentText("Verknüpfungen werden gesucht …");
    try {
      const response = await fetch(url, {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
        signal: controller.signal,
      });
      const data = await response.json();
      if (!response.ok)
        throw new Error(data.error || "Verknüpfungen derzeit nicht verfügbar.");
      if (
        !picker.isConnected ||
        input.value !== query ||
        controller.signal.aborted
      )
        return;
      // A changed selection during a pending request must not be replaced by its older value.
      if (select.value !== url.searchParams.get("selected")) {
        status.textContent =
          contentText("Auswahl geändert. Suche bei Bedarf erneut ausführen.");
        return;
      }
      const value = select.value;
      const retained = [...select.options].find(
        (option) => option.value === value,
      );
      const empty = select.options[0].cloneNode(true);
      const options = [empty];
      for (const row of data.rows)
        options.push(new Option(row.label, row.id, false, row.id === value));
      if (value && !data.rows.some((row) => row.id === value))
        options.push(
          new Option(
            data.selected?.label ||
              retained?.textContent ||
              contentText("Auswahl nicht mehr verfügbar – bitte neu wählen"),
            value,
            false,
            true,
          ),
        );
      select.replaceChildren(...options);
      select.value = value;
      picker.dataset.referencePage = String(data.page);
      picker.dataset.referenceLast = String(data.last);
      status.textContent =
        contentFormat("{count} Treffer · Seite {page} von {pages}",{count:data.total,page:data.page,pages:data.last});
    } catch (error) {
      if (error.name !== "AbortError" && picker.isConnected)
        status.textContent = contentText(error.message);
    } finally {
      if (pending.get(picker) === controller) {
        pending.delete(picker);
        active.delete(picker);
        picker.removeAttribute("aria-busy");
        controls.forEach(
          (button) =>
            (button.disabled =
              button.dataset.referenceAction === "previous"
                ? Number(picker.dataset.referencePage) <= 1
                : button.dataset.referenceAction === "next"
                  ? Number(picker.dataset.referencePage) >=
                    Number(picker.dataset.referenceLast)
                  : false),
        );
      }
    }
  };
  document.addEventListener("click", (event) => {
    const button = event.target.closest("[data-reference-action]");
    if (button && !button.disabled)
      void search(
        button.closest("[data-reference-picker]"),
        button.dataset.referenceAction,
      );
  });
  document.addEventListener("keydown", (event) => {
    if (
      event.key === "Enter" &&
      event.target.matches("[data-reference-query]")
    ) {
      event.preventDefault();
      void search(event.target.closest("[data-reference-picker]"), "search");
    }
  });
  document.addEventListener("input", (event) => {
    if (!event.target.matches("[data-reference-query]")) return;
    const picker = event.target.closest("[data-reference-picker]");
    cancel(picker);
    picker.removeAttribute("aria-busy");
    picker
      .querySelectorAll("[data-reference-action]")
      .forEach(
        (button) =>
          (button.disabled = button.dataset.referenceAction !== "search"),
      );
    picker.querySelector("[data-reference-status]").textContent =
      contentText("Suchtext geändert. Mit Suchen oder Eingabetaste bestätigen.");
  });
  // Soft navigation removes private pending work together with the former workspace.
  new MutationObserver(() =>
    active.forEach((picker) => {
      if (!picker.isConnected) cancel(picker);
    }),
  ).observe(document.documentElement, { childList: true, subtree: true });
})();
