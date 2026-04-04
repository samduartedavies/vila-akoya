(function () {
  if (!document.getElementById('site-nav-tap-style')) {
    var st = document.createElement('style');
    st.id = 'site-nav-tap-style';
    st.textContent =
      '[data-nav]{-webkit-tap-highlight-color:transparent;touch-action:manipulation;}';
    document.head.appendChild(st);
  }

  var btn = document.getElementById('site-nav-toggle');
  var panel = document.getElementById('site-mobile-nav');
  if (!btn || !panel) return;

  var icon = btn.querySelector('.material-symbols-outlined');

  function pathToKey() {
    var p = window.location.pathname.replace(/\/$/, '') || '/';
    if (p === '/' || p === '/home') return null;
    if (p === '/village') return 'village';
    if (p === '/campsite') return 'campsite';
    if (p === '/booking') return 'booking';
    if (p === '/campus') return 'campus';
    if (p === '/the-kids') return 'the-kids';
    if (p === '/about') return 'about';
    if (p === '/contact') return 'contact';
    return null;
  }

  var inactiveDesktop =
    'text-stone-600 dark:text-stone-400 transition-colors hover:text-[#9a4021] hover:bg-[#9a4021]/5 dark:hover:bg-[#ba5737]/10 active:text-[#9a4021] active:bg-[#9a4021]/5 dark:active:bg-[#ba5737]/10 px-2 py-1 rounded-md';
  var activeDesktop =
    'text-[#9a4021] dark:text-[#ba5737] bg-[#9a4021]/5 dark:bg-[#ba5737]/10 px-2 py-1 rounded-md transition-colors hover:text-[#9a4021] hover:bg-[#9a4021]/5 dark:hover:bg-[#ba5737]/10 active:text-[#9a4021] active:bg-[#9a4021]/5 dark:active:bg-[#ba5737]/10';
  var inactiveMobile =
    'block rounded-lg px-2 py-3 text-stone-600 dark:text-stone-400 transition-colors hover:bg-[#9a4021]/5 dark:hover:bg-[#ba5737]/10 active:bg-[#9a4021]/5 dark:active:bg-[#ba5737]/10';
  var activeMobile =
    'block rounded-lg px-2 py-3 text-stone-600 dark:text-stone-400 bg-[#9a4021]/5 dark:bg-[#ba5737]/10 transition-colors hover:bg-[#9a4021]/5 dark:hover:bg-[#ba5737]/10 active:bg-[#9a4021]/5 dark:active:bg-[#ba5737]/10';

  var key = pathToKey();
  document.querySelectorAll('[data-nav]').forEach(function (el) {
    var navKey = el.getAttribute('data-nav');
    var isActive = key && navKey === key;
    var isDesktop = el.closest('#site-nav-desktop-links') !== null;
    el.className = isDesktop
      ? isActive
        ? activeDesktop
        : inactiveDesktop
      : isActive
        ? activeMobile
        : inactiveMobile;
    if (isActive) el.setAttribute('aria-current', 'page');
    else el.removeAttribute('aria-current');
  });

  function setOpen(open) {
    if (open) {
      panel.classList.remove('hidden');
      btn.setAttribute('aria-expanded', 'true');
      btn.setAttribute('aria-label', 'Close menu');
      if (icon) icon.textContent = 'close';
    } else {
      panel.classList.add('hidden');
      btn.setAttribute('aria-expanded', 'false');
      btn.setAttribute('aria-label', 'Open menu');
      if (icon) icon.textContent = 'menu';
    }
  }

  btn.addEventListener('click', function () {
    setOpen(panel.classList.contains('hidden'));
  });

  panel.querySelectorAll('a').forEach(function (a) {
    a.addEventListener('click', function () {
      setOpen(false);
    });
  });

  window.addEventListener('resize', function () {
    if (window.matchMedia('(min-width: 768px)').matches) {
      setOpen(false);
    }
  });
})();
