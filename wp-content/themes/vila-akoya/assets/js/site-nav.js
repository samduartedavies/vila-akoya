(function () {
  var btn = document.getElementById("site-nav-toggle");
  var panel = document.getElementById("site-mobile-nav");
  if (!btn || !panel) return;

  function setOpen(open) {
    btn.setAttribute("aria-expanded", open ? "true" : "false");
    panel.setAttribute("data-open", open ? "true" : "false");
    panel.setAttribute("aria-hidden", open ? "false" : "true");
  }

  btn.addEventListener("click", function () {
    setOpen(btn.getAttribute("aria-expanded") !== "true");
  });

  panel.addEventListener("click", function (event) {
    if (event.target && event.target.closest("a")) {
      setOpen(false);
    }
  });

  window.addEventListener("resize", function () {
    if (window.matchMedia("(min-width: 1020px)").matches) {
      setOpen(false);
    }
  });
})();
