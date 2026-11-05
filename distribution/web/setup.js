(() => {
  const form = document.querySelector("[data-setup]");
  const status = document.querySelector("[data-status]");
  const error = document.querySelector("[data-error]");
  const progress = document.querySelector("progress");
  const login = document.querySelector("[data-login]");
  let busy = false;
  const fintsToggle = form.querySelector("[name=fints_enable]");
  function bankFields() {
    for (const field of form.querySelectorAll(
      ".setup-addon-fields input, [name=fints_confirm]",
    )) {
      field.disabled = !fintsToggle.checked;
      field.required = fintsToggle.checked;
    }
  }
  fintsToggle.addEventListener("change", bankFields);
  bankFields();
  async function install(action) {
    if (busy) return;
    busy = true;
    error.textContent = "";
    const body = new URLSearchParams(new FormData(form));
    const token = body.get("token");
    const csrf = body.get("csrf");
    const buttons = [...form.querySelectorAll("button")];
    buttons.forEach((b) => (b.disabled = true));
    progress.hidden = false;
    try {
      body.set("action", action);
      for (;;) {
        const response = await fetch("./", {
          method: "POST",
          body,
          credentials: "same-origin",
        });
        const result = await response.json();
        if (!response.ok || result.error)
          throw new Error(
            result.error || "Einrichtung unterbrochen. Bitte erneut versuchen.",
          );
        form.querySelector("[name=db_password]").value = "";
        form.querySelector("[name=admin_password]").value = "";
        status.textContent = result.message;
        progress.value = result.percent;
        if (result.phase === "installed") {
          const panel = document.querySelector('[data-scheduler]');
          const jobs = panel.querySelector('[data-scheduler-jobs]');
          if (!result.scheduler || !Array.isArray(result.scheduler.jobs) || typeof result.scheduler_text !== 'string')
            throw new Error('Hostinganleitung konnte nicht geladen werden. Die private CLI-Hilfe kann sie erneut erzeugen.');
          jobs.replaceChildren();
          panel.querySelector('[data-scheduler-message]').textContent = result.scheduler.message;
          for (const job of result.scheduler.jobs) {
            const item = document.createElement('details');
            const title = document.createElement('summary'); title.textContent = job.label;
            const requires = document.createElement('p'); requires.textContent = job.requires;
            const schedule = document.createElement('p'); schedule.textContent = 'Zeitmuster: ' + job.schedule;
            const label = document.createElement('label'); label.textContent = 'Aufruf kopieren';
            const command = document.createElement('textarea'); command.readOnly = true; command.rows = 3;
            command.dataset.schedulerCommand = job.id; command.value = job.cron_command;
            label.append(command); item.append(title, requires, schedule, label); jobs.append(item);
          }
          const download = panel.querySelector('[data-scheduler-download]');
          download.href = URL.createObjectURL(new Blob([result.scheduler_text], {type: 'text/plain;charset=utf-8'}));
          panel.hidden = false;
          form.hidden = true;
          document.querySelector('h1').textContent = 'DVM ist eingerichtet';
          document.querySelector('h1 + p').textContent = 'Die Einrichtung ist abgeschlossen. Richte die benötigten automatischen Aufgaben bei deinem Hoster ein.';
          document.querySelector('main > details').open = false;
          document.querySelector('main > p.hint').textContent = 'Der Assistent ist gesperrt. Entferne anschließend den Ordner setup/ vom Webspace.';
          login.hidden = false;
          break;
        }
        body.forEach((_, key) => {
          if (!["token", "csrf", "action"].includes(key)) body.delete(key);
        });
        // Build a fresh request to avoid retaining or resending any password.
        const next = new URLSearchParams({ token, csrf, action: "step" });
        for (const key of [...body.keys()]) body.delete(key);
        next.forEach((value, key) => body.set(key, value));
      }
    } catch (failure) {
      error.textContent = failure.message;
      document.querySelector("[data-addons]").hidden = false;
      form.querySelector("[name=db_password]").value = "";
      form.querySelector("[name=admin_password]").value = "";
    } finally {
      busy = false;
      buttons.forEach((b) => (b.disabled = false));
    }
  }
  form.addEventListener("submit", (event) => {
    event.preventDefault();
    install("begin");
  });
  document
    .querySelector("[data-resume]")
    .addEventListener("click", () => install("resume"));
  document
    .querySelector("[data-addons]")
    .addEventListener("click", () => install("addons"));
})();
