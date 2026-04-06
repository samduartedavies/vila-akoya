/**
 * Airbnb-style range calendar + guest steppers for Vila Campsite Booking.
 */
(function () {
  'use strict';

  function pad(n) {
    return String(n).padStart(2, '0');
  }

  function ymd(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }

  function parseYmd(s) {
    var p = String(s).split('-');
    if (p.length !== 3) return null;
    var y = parseInt(p[0], 10);
    var m = parseInt(p[1], 10) - 1;
    var day = parseInt(p[2], 10);
    if (isNaN(y) || isNaN(m) || isNaN(day)) return null;
    return new Date(y, m, day);
  }

  function monthStart(d) {
    return new Date(d.getFullYear(), d.getMonth(), 1);
  }

  function addMonths(d, n) {
    return new Date(d.getFullYear(), d.getMonth() + n, 1);
  }

  function daysInMonth(y, m0) {
    return new Date(y, m0 + 1, 0).getDate();
  }

  var state = {
    root: null,
    left: new Date(),
    selStart: null,
    selEnd: null,
    fullSet: {},
    popover: null,
    debugPanel: null,
    debugLastSubmit: '',
  };

  function debugEnabled() {
    return !!(window.vilaCampsiteBooking && window.vilaCampsiteBooking.debugUi);
  }

  function refreshDebugPanel(note) {
    if (!debugEnabled() || !state.root) {
      return;
    }
    if (!state.debugPanel) {
      return;
    }
    if (note) {
      state.debugLastSubmit = String(note);
    }
    var cin = state.root.querySelector('#vila_cb_check_in');
    var cout = state.root.querySelector('#vila_cb_check_out');
    var g = {};
    try {
      g = guestState();
    } catch (e) {
      g = { error: String(e.message || e) };
    }
    var ss = '';
    try {
      ss = window.sessionStorage.getItem('vila_campsite_booking_stay') || '';
    } catch (e) {
      ss = '(sessionStorage blocked: ' + String(e.message || e) + ')';
    }
    var form = state.root.querySelector('.vila-cb__form');
    var lines = [];
    lines.push('Time: ' + new Date().toISOString());
    lines.push('JS range — selStart: ' + (state.selStart ? ymd(state.selStart) : '(null)') + '  selEnd: ' + (state.selEnd ? ymd(state.selEnd) : '(null)'));
    lines.push('Hidden POST — vila_cb_check_in: "' + (cin && cin.value ? cin.value : '') + '"  vila_cb_check_out: "' + (cout && cout.value ? cout.value : '') + '"');
    if (g.error) {
      lines.push('Guests — ' + g.error);
    } else {
      lines.push('Guests — adults:' + g.adults + ' children:' + g.children + ' infants:' + g.infants + ' pets:' + g.pets);
    }
    lines.push('sessionStorage vila_campsite_booking_stay: ' + (ss || '(empty)'));
    lines.push('Form action: ' + (form && form.action ? form.action : '(no form)'));
    lines.push('Last submit note: ' + (state.debugLastSubmit || '(none)'));
    state.debugPanel.textContent = lines.join('\n');
  }

  function initDebugPanel() {
    if (!debugEnabled() || !state.root) {
      return;
    }
    var wrap = document.createElement('div');
    wrap.className = 'vila-cb-debug-panel-wrap';
    wrap.innerHTML =
      '<div class="vila-cb-debug-panel-title" id="vila-cb-debug-panel-heading">Booking form state (what POSTs)</div>' +
      '<pre class="vila-cb-debug-panel" id="vila-cb-debug-panel-pre" aria-labelledby="vila-cb-debug-panel-heading"></pre>';
    var formEl = state.root.querySelector('.vila-cb__form');
    if (formEl) {
      state.root.insertBefore(wrap, formEl);
    } else {
      state.root.appendChild(wrap);
    }
    state.debugPanel = wrap.querySelector('#vila-cb-debug-panel-pre');
    refreshDebugPanel();
    setInterval(function () {
      refreshDebugPanel();
    }, 2000);
  }

  function apiUrl(from, to) {
    var base =
      (window.vilaCampsiteBooking && window.vilaCampsiteBooking.restUrl) ||
      '/wp-json/vila-campsite-booking/v1/availability';
    var u = new URL(base, window.location.origin);
    u.searchParams.set('from', from);
    u.searchParams.set('to', to);
    if (state.selStart && state.selEnd) {
      u.searchParams.set('check_in', ymd(state.selStart));
      u.searchParams.set('check_out', ymd(state.selEnd));
    }
    return u.toString();
  }

  function fetchAvailability(fromYmd, toYmd) {
    return fetch(apiUrl(fromYmd, toYmd), {
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': (window.vilaCampsiteBooking && window.vilaCampsiteBooking.nonce) || '',
      },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        state.fullSet = {};
        (data.fully_booked_dates || []).forEach(function (d) {
          state.fullSet[d] = true;
        });
      })
      .catch(function () {
        state.fullSet = {};
      });
  }

  function buildGrid(container, anchor, offset) {
    var d = addMonths(monthStart(anchor), offset);
    var y = d.getFullYear();
    var m = d.getMonth();
    var label = d.toLocaleString(undefined, { month: 'long', year: 'numeric' });
    var firstDow = new Date(y, m, 1).getDay();
    var dim = daysInMonth(y, m);

    var wrap = document.createElement('div');
    wrap.className = 'vila-cb-cal__month';
    wrap.innerHTML =
      '<div class="vila-cb-cal__title">' +
      label +
      '</div><div class="vila-cb-cal__dow">' +
      ['S', 'M', 'T', 'W', 'T', 'F', 'S']
        .map(function (x) {
          return '<span>' + x + '</span>';
        })
        .join('') +
      '</div><div class="vila-cb-cal__cells"></div>';
    var cells = wrap.querySelector('.vila-cb-cal__cells');

    for (var i = 0; i < firstDow; i++) {
      var ph = document.createElement('span');
      ph.className = 'vila-cb-cal__ph';
      cells.appendChild(ph);
    }
    for (var day = 1; day <= dim; day++) {
      var dt = new Date(y, m, day);
      var key = ymd(dt);
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'vila-cb-cal__day';
      btn.textContent = String(day);
      btn.dataset.ymd = key;
      if (state.fullSet[key]) {
        btn.classList.add('is-disabled');
        btn.disabled = true;
      }
      btn.addEventListener('click', onDayClick);
      cells.appendChild(btn);
    }
    container.appendChild(wrap);
    highlightRange(container);
  }

  function highlightRange(container) {
    var buttons = container.querySelectorAll('.vila-cb-cal__day');
    var start = state.selStart ? ymd(state.selStart) : null;
    var end = state.selEnd ? ymd(state.selEnd) : null;
    buttons.forEach(function (b) {
      b.classList.remove('is-start', 'is-end', 'is-inrange');
      if (!start || !end) return;
      var k = b.dataset.ymd;
      if (k === start) b.classList.add('is-start');
      if (k === end) b.classList.add('is-end');
      if (k > start && k < end) b.classList.add('is-inrange');
    });
  }

  function rangeHasBlockedNight(a, b) {
    var x = parseYmd(ymd(a));
    var stop = parseYmd(ymd(b));
    if (!x || !stop) return true;
    while (x < stop) {
      var k = ymd(x);
      if (state.fullSet[k]) return true;
      x = new Date(x.getFullYear(), x.getMonth(), x.getDate() + 1);
    }
    return false;
  }

  function onDayClick(ev) {
    var btn = ev.currentTarget;
    if (btn.disabled) return;
    var d = parseYmd(btn.dataset.ymd);
    if (!d) return;

    if (!state.selStart || (state.selStart && state.selEnd)) {
      state.selStart = d;
      state.selEnd = null;
    } else if (!state.selEnd) {
      var a = state.selStart;
      var b = d;
      if (b < a) {
        var t = a;
        a = b;
        b = t;
      }
      if (rangeHasBlockedNight(a, b)) {
        state.selEnd = null;
        state.selStart = d;
      } else {
        state.selStart = a;
        state.selEnd = b;
      }
    }

    syncForm();
    refreshAvailability();
    refreshDebugPanel('calendar day click');
  }

  function persistStayForCheckoutBlocks() {
    var cin = state.root.querySelector('#vila_cb_check_in');
    var cout = state.root.querySelector('#vila_cb_check_out');
    var cfg = window.vilaCampsiteBooking || {};
    var pid = cfg.productId;
    if (!cin || !cout || !pid) {
      return;
    }
    if (!cin.value || !cout.value) {
      try {
        window.sessionStorage.removeItem('vila_campsite_booking_stay');
      } catch (e) {}
      return;
    }
    var g = guestState();
    try {
      window.sessionStorage.setItem(
        'vila_campsite_booking_stay',
        JSON.stringify({
          product_id: pid,
          vila_cb_check_in: cin.value,
          vila_cb_check_out: cout.value,
          vila_cb_guests: {
            adults: g.adults,
            children: g.children,
            infants: g.infants,
            pets: g.pets,
          },
        })
      );
    } catch (e) {}
  }

  function syncForm() {
    var cin = state.root.querySelector('#vila_cb_check_in');
    var cout = state.root.querySelector('#vila_cb_check_out');
    var when = state.root.querySelector('[data-vila-cb-when-label]');
    if (state.selStart && state.selEnd) {
      cin.value = ymd(state.selStart);
      cout.value = ymd(state.selEnd);
      var fmt = function (dt) {
        return dt.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
      };
      when.textContent = fmt(state.selStart) + ' – ' + fmt(state.selEnd);
    } else {
      cin.value = '';
      cout.value = '';
      when.textContent =
        (window.vilaCampsiteBooking &&
          window.vilaCampsiteBooking.strings &&
          window.vilaCampsiteBooking.strings.addDates) || 'Add dates';
    }
    persistStayForCheckoutBlocks();
    refreshDebugPanel();
  }

  function renderCalendar(container) {
    container.innerHTML = '';
    var nav = document.createElement('div');
    nav.className = 'vila-cb-cal__nav';
    nav.innerHTML =
      '<button type="button" class="button" data-vila-nav="-1" aria-label="Previous month">←</button>' +
      '<button type="button" class="button" data-vila-nav="1" aria-label="Next month">→</button>';
    nav.addEventListener('click', function (e) {
      var t = e.target.closest('[data-vila-nav]');
      if (!t) return;
      var delta = parseInt(t.getAttribute('data-vila-nav'), 10);
      state.left = addMonths(monthStart(state.left), delta);
      refreshAvailability();
    });
    container.appendChild(nav);
    var grid = document.createElement('div');
    grid.className = 'vila-cb-cal__grid-inner';
    buildGrid(grid, state.left, 0);
    buildGrid(grid, state.left, 1);
    container.appendChild(grid);
  }

  function refreshAvailability() {
    var from = ymd(monthStart(state.left));
    var toD = addMonths(monthStart(state.left), 2);
    var to = ymd(new Date(toD.getFullYear(), toD.getMonth(), 0));
    return fetchAvailability(from, to).then(function () {
      var cal = state.root.querySelector('[data-vila-cb-calendar]');
      cal.innerHTML = '';
      renderCalendar(cal);
    });
  }

  function guestState() {
    var L = window.vilaCampsiteBooking && window.vilaCampsiteBooking.limits ? window.vilaCampsiteBooking.limits : {};
    return {
      adults: parseInt(state.root.querySelector('#vila_cb_adults').value, 10) || L.minAdults || 1,
      children: parseInt(state.root.querySelector('#vila_cb_children').value, 10) || 0,
      infants: parseInt(state.root.querySelector('#vila_cb_infants').value, 10) || 0,
      pets: parseInt(state.root.querySelector('#vila_cb_pets').value, 10) || 0,
      minAdults: L.minAdults || 1,
      maxPeople: L.maxPeople || 8,
      maxPets: L.maxPets || 5,
    };
  }

  function setGuestField(slug, val) {
    state.root.querySelector('#vila_cb_' + slug).value = String(val);
    var el = state.root.querySelector('[data-vila-val="' + slug + '"]');
    if (el) el.textContent = String(val);
  }

  function updateGuestUi() {
    var g = guestState();
    var totalPeople = g.adults + g.children + g.infants;
    state.root.querySelectorAll('[data-vila-step]').forEach(function (btn) {
      var slug = btn.getAttribute('data-vila-step');
      var delta = parseInt(btn.getAttribute('data-delta'), 10);
      var cur = g[slug];
      if (slug === 'adults') {
        var next = cur + delta;
        btn.disabled =
          (delta < 0 && next < g.minAdults) ||
          (delta > 0 && totalPeople >= g.maxPeople) ||
          (delta > 0 && next > g.maxPeople - g.children - g.infants);
      } else if (slug === 'children' || slug === 'infants') {
        var n = cur + delta;
        btn.disabled = (delta < 0 && n < 0) || (delta > 0 && totalPeople >= g.maxPeople);
      } else if (slug === 'pets') {
        var np = cur + delta;
        btn.disabled = (delta < 0 && np < 0) || (delta > 0 && np > g.maxPets);
      }
    });
    var who = state.root.querySelector('[data-vila-cb-who-label]');
    var people = g.adults + g.children + g.infants;
    var parts = [];
    parts.push(people === 1 ? '1 guest' : people + ' guests');
    if (g.pets > 0) parts.push(g.pets === 1 ? '1 pet' : g.pets + ' pets');
    who.textContent = parts.join(' · ');
    persistStayForCheckoutBlocks();
    refreshDebugPanel('guests updated');
  }

  function initGuests() {
    var L = window.vilaCampsiteBooking && window.vilaCampsiteBooking.limits ? window.vilaCampsiteBooking.limits : {};
    setGuestField('adults', L.defaultAdults || L.minAdults || 1);
    setGuestField('children', 0);
    setGuestField('infants', 0);
    setGuestField('pets', 0);
    updateGuestUi();
    state.root.querySelectorAll('[data-vila-step]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var slug = btn.getAttribute('data-vila-step');
        var delta = parseInt(btn.getAttribute('data-delta'), 10);
        var g = guestState();
        var next = g[slug] + delta;
        if (slug === 'adults') {
          next = Math.max(g.minAdults, next);
          if (g.adults + g.children + g.infants + delta > g.maxPeople) return;
        }
        if (slug === 'children' || slug === 'infants') {
          next = Math.max(0, next);
          if (g.adults + g.children + g.infants + delta > g.maxPeople) return;
        }
        if (slug === 'pets') {
          next = Math.max(0, Math.min(g.maxPets, next));
        }
        setGuestField(slug, next);
        updateGuestUi();
      });
    });
  }

  function initTabs() {
    var tabs = state.root.querySelectorAll('[data-vila-cb-tab]');
    var pops = state.root.querySelectorAll('[data-vila-cb-popover]');
    tabs.forEach(function (t) {
      t.addEventListener('click', function (e) {
        e.preventDefault();
        var name = t.getAttribute('data-vila-cb-tab');
        pops.forEach(function (p) {
          p.hidden = p.getAttribute('data-vila-cb-popover') !== name;
        });
        tabs.forEach(function (x) {
          x.classList.toggle('is-active', x === t);
        });
      });
    });
    document.addEventListener('click', function (e) {
      if (!state.root.contains(e.target)) {
        pops.forEach(function (p) {
          p.hidden = true;
        });
      }
    });
  }

  function boot() {
    state.root = document.querySelector('[data-vila-cb-root]');
    if (!state.root) return;
    state.left = monthStart(new Date());
    initDebugPanel();
    initTabs();
    initGuests();
    var form = state.root.querySelector('.vila-cb__form');
    if (form) {
      form.addEventListener('submit', function (e) {
        refreshDebugPanel('submit: before syncForm()');
        // Source of truth is the hidden inputs (what gets POSTed), not in-memory range state — avoids false blocks if state/calendar desync.
        syncForm();
        refreshDebugPanel('submit: after syncForm() — hidden fields match line below');
        var cinEl = state.root.querySelector('#vila_cb_check_in');
        var coutEl = state.root.querySelector('#vila_cb_check_out');
        var vIn = cinEl && cinEl.value ? String(cinEl.value).trim() : '';
        var vOut = coutEl && coutEl.value ? String(coutEl.value).trim() : '';
        if (!vIn || !vOut) {
          e.preventDefault();
          refreshDebugPanel('submit BLOCKED: missing dates in hidden fields');
          var msg =
            (window.vilaCampsiteBooking &&
              window.vilaCampsiteBooking.strings &&
              window.vilaCampsiteBooking.strings.selectStayDates) ||
            'Please select check-in and check-out dates.';
          window.alert(msg);
          var pop = state.root.querySelector('[data-vila-cb-popover="when"]');
          if (pop) {
            pop.hidden = false;
          }
          var whenTab = state.root.querySelector('[data-vila-cb-tab="when"]');
          if (whenTab) {
            whenTab.click();
          }
          return false;
        }
        refreshDebugPanel('submit OK: browser will POST form to product URL');
      });
    }
    var cal = state.root.querySelector('[data-vila-cb-calendar]');
    refreshAvailability().then(function () {
      renderCalendar(cal);
      refreshDebugPanel('calendar rendered');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
