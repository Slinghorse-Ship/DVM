/* Shared Bootstrap 6 controls for UI nodes created after the server response. */
(() => {
  "use strict";
  if (window.KulturhusControls) {
    window.KulturhusControls.enhance(document);
    return;
  }
  const selector = "button,input,select,textarea,a.button";
  // Keep a touch reply target in place while its editable text still has focus.
  document.addEventListener("mousedown", event => {
    if (event.target.closest("[data-human-send]")) event.preventDefault();
  });
  const content = "[contenteditable],.kh-richtext,[data-public-preview]";
  function decorate(node) {
    if (!node.isConnected || node.closest(content)) return;
    const type = node.type?.toLowerCase();
    if (node.tagName === "INPUT" && type === "hidden") return;
    if (node.matches("button,a.button,input[type=button],input[type=submit],input[type=reset]")) {
      if (node.classList.contains("btn")) return;
      const danger = /danger/.test(node.className);
      const secondary = /secondary|text-button|icon-button|result|picker|jump/.test(node.className) || node.getAttribute("role") === "option";
      node.classList.add("btn", danger || secondary ? "btn-outline" : "btn-solid", danger ? "theme-danger" : secondary ? "theme-secondary" : "theme-primary");
    } else {
      node.classList.add(type === "checkbox" || type === "radio" ? "check" : type === "range" ? "form-range" : "form-control");
    }
  }
  function enhance(root) {
    if (document.body?.classList.contains("public-site")) return;
    if (root.matches?.(selector)) decorate(root);
    root.querySelectorAll?.(selector).forEach(decorate);
  }
  window.KulturhusControls = Object.freeze({ enhance });
  enhance(document);
  const observer = new MutationObserver(records => {
    for (const record of records) for (const node of record.addedNodes) {
      if (node.nodeType === Node.ELEMENT_NODE && node.isConnected) enhance(node);
    }
  });
  if (document.body) observer.observe(document.body, { childList: true, subtree: true });
})();
