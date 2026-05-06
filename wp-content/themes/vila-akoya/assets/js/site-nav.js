(function () {
  if (!document.getElementById("site-nav-tap-style")) {
    var st = document.createElement("style");
    st.id = "site-nav-tap-style";
    st.textContent =
      "[data-nav]{-webkit-tap-highlight-color:transparent;touch-action:manipulation;}";
    document.head.appendChild(st);
  }

  var btn = document.getElementById("site-nav-toggle");
  var panel = document.getElementById("site-mobile-nav");
  if (!btn || !panel) return;

  var icon = btn.querySelector(".material-symbols-outlined");

  function pathToKey() {
    var p = window.location.pathname.replace(/\/$/, "") || "/";
    if (p === "/" || p === "/home") return null;
    var parts = p.split("/").filter(Boolean);
    var last = parts.length ? parts[parts.length - 1] : "";
    var map = {
      village: "village",
      campsite: "campsite",
      booking: "booking",
      campus: "campus",
      "the-kids": "the-kids",
      about: "about",
      contact: "contact",
    };
    return map[last] || null;
  }

  var inactiveDesktop =
    "text-stone-600 transition-colors hover:text-[#8f3a1d] hover:bg-[#9a4021]/10 active:text-[#8f3a1d] active:bg-[#9a4021]/10 px-3 py-1.5 rounded-full";
  var activeDesktop =
    "text-[#8f3a1d] bg-[#9a4021]/12 shadow-[inset_0_0_0_1px_rgba(154,64,33,0.18)] px-3 py-1.5 rounded-full transition-colors hover:text-[#8f3a1d] hover:bg-[#9a4021]/12 active:text-[#8f3a1d] active:bg-[#9a4021]/12";
  var inactiveMobile =
    "block rounded-lg px-2 py-3 text-stone-600 transition-colors hover:bg-[#9a4021]/5 active:bg-[#9a4021]/5";
  var activeMobile =
    "block rounded-lg px-2 py-3 text-stone-600 bg-[#9a4021]/5 transition-colors hover:bg-[#9a4021]/5 active:bg-[#9a4021]/5";

  var key = pathToKey();
  document.querySelectorAll("[data-nav]").forEach(function (el) {
    var navKey = el.getAttribute("data-nav");
    var isActive = key && navKey === key;
    var isDesktop = el.closest("#site-nav-desktop-links") !== null;
    el.className = isDesktop
      ? isActive
        ? activeDesktop
        : inactiveDesktop
      : isActive
        ? activeMobile
        : inactiveMobile;
    if (isActive) el.setAttribute("aria-current", "page");
    else el.removeAttribute("aria-current");
  });

  function setOpen(open) {
    if (open) {
      panel.classList.remove("hidden");
      btn.setAttribute("aria-expanded", "true");
      btn.setAttribute("aria-label", "Close menu");
      if (icon) icon.textContent = "close";
    } else {
      panel.classList.add("hidden");
      btn.setAttribute("aria-expanded", "false");
      btn.setAttribute("aria-label", "Open menu");
      if (icon) icon.textContent = "menu";
    }
  }

  btn.addEventListener("click", function () {
    setOpen(panel.classList.contains("hidden"));
  });

  panel.querySelectorAll("a").forEach(function (a) {
    a.addEventListener("click", function () {
      setOpen(false);
    });
  });

  window.addEventListener("resize", function () {
    if (window.matchMedia("(min-width: 768px)").matches) {
      setOpen(false);
    }
  });
})();
