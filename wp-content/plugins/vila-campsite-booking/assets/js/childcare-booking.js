/**
 * Guest-facing childcare booking form → WooCommerce checkout.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'vila_childcare_profile';
  var DEFAULT_CFG = {
    price: 15,
    capacity: 8,
    currency: { symbol: '€', decimalSep: ',' },
    strings: {},
  };

  function pad2(n) {
    return n < 10 ? '0' + n : String(n);
  }

  function ymdFromDate(d) {
    return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
  }

  function monthStart(d) {
    return new Date(d.getFullYear(), d.getMonth(), 1);
  }

  function addMonths(d, n) {
    return new Date(d.getFullYear(), d.getMonth() + n, 1);
  }

  function daysInMonth(y, m) {
    return new Date(y, m + 1, 0).getDate();
  }

  function parseJsonAttr(el, name, fallback) {
    var raw = el ? el.getAttribute(name) : '';
    if (!raw) return fallback;
    try {
      return JSON.parse(raw);
    } catch (e) {
      return fallback;
    }
  }

  function cfg(root) {
    var globalCfg = window.vilaChildcareBooking || DEFAULT_CFG;
    var pricing = parseJsonAttr(root, 'data-pricing', { child: globalCfg.price || 15 });
    return {
      availabilityUrl: root.getAttribute('data-availability-url') || globalCfg.availabilityUrl || '',
      price: Number(pricing.child || globalCfg.price || 15),
      currency: parseJsonAttr(root, 'data-currency', globalCfg.currency || DEFAULT_CFG.currency),
      strings: globalCfg.strings || {},
    };
  }

  function txt(root, key, fallback) {
    return (cfg(root).strings || {})[key] || fallback;
  }

  function qs(root, sel) {
    return root.querySelector(sel);
  }

  function qsa(root, sel) {
    return Array.prototype.slice.call(root.querySelectorAll(sel));
  }

  function formatMoney(root, amount) {
    var c = cfg(root).currency || {};
    var sym = c.symbol || '€';
    var sep = c.decimalSep || ',';
    var n = Math.round((Number(amount) || 0) * 100) / 100;
    if (Math.abs(n - Math.round(n)) < 0.00001) {
      return sym + String(Math.round(n));
    }
    return sym + n.toFixed(2).replace('.', sep);
  }

  function formatLabel(ymd) {
    var p = String(ymd).split('-');
    if (p.length !== 3) return ymd;
    var d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
    try {
      return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
    } catch (e) {
      return ymd;
    }
  }

  function childData(root) {
    return qsa(root, '[data-vila-childcare-child]').map(function (row) {
      return {
        name: (qs(row, '[data-vila-childcare-child-name]') || {}).value || '',
        age: (qs(row, '[data-vila-childcare-child-age]') || {}).value || '',
        allergies: (qs(row, '[data-vila-childcare-child-allergies]') || {}).value || '',
      };
    });
  }

  function namedChildren(root) {
    return childData(root).filter(function (child) {
      return String(child.name || '').trim();
    });
  }

  function availabilityMapFromDays(days) {
    var out = {};
    (days || []).forEach(function (day) {
      if (day && day.ymd) out[day.ymd] = day;
    });
    return out;
  }

  function init(root) {
    var form = qs(root, '[data-vila-childcare-form]');
    var childrenWrap = qs(root, '[data-vila-childcare-children]');
    var addChildBtn = qs(root, '[data-vila-childcare-add-child]');
    var calendarEl = qs(root, '[data-vila-childcare-calendar]');
    var chipsEl = qs(root, '[data-vila-childcare-dates]');
    var hiddenDates = qs(root, '[data-vila-childcare-hidden-dates]');
    var message = qs(root, '[data-vila-childcare-message]');
    var remember = qs(root, '[data-vila-childcare-remember]');
    var selected = {};
    var availability = {};
    var calendarLeft = monthStart(new Date());

    function setMessage(text, isError) {
      message.textContent = text || '';
      message.classList.toggle('is-error', !!isError);
    }

    function selectedDates() {
      return Object.keys(selected).sort();
    }

    function syncHiddenDates() {
      hiddenDates.innerHTML = '';
      selectedDates().forEach(function (ymd) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'vila_childcare_dates[]';
        input.value = ymd;
        hiddenDates.appendChild(input);
      });
    }

    function updateSummary() {
      var count = namedChildren(root).length;
      var days = selectedDates().length;
      var total = cfg(root).price * count * days;
      var breakdown = qs(root, '[data-vila-childcare-breakdown]');
      if (breakdown) {
        breakdown.innerHTML = '';
        if (count > 0 && days > 0) {
          var li = document.createElement('li');
          li.className = 'vila-cb-mb__breakdown-line';
          li.innerHTML =
            '<div class="vila-cb-mb__breakdown-main">' +
            '<span class="vila-cb-mb__breakdown-title"></span>' +
            '<span class="vila-cb-mb__breakdown-detail"></span>' +
            '</div><span class="vila-cb-mb__breakdown-amount"></span>';
          li.querySelector('.vila-cb-mb__breakdown-title').textContent =
            days + ' ' + txt(root, 'selectedDays', 'selected days');
          li.querySelector('.vila-cb-mb__breakdown-detail').textContent =
            txt(root, 'perDay', '%s / child / day').replace('%s', formatMoney(root, cfg(root).price)) +
            ' · ' +
            count +
            ' ' +
            txt(root, 'children', 'children');
          li.querySelector('.vila-cb-mb__breakdown-amount').textContent = formatMoney(root, total);
          breakdown.appendChild(li);
        } else {
          var empty = document.createElement('li');
          empty.className = 'vila-cb-mb__breakdown-empty';
          empty.textContent = txt(root, 'breakdownEmpty', 'Add children and select dates to see your estimate.');
          breakdown.appendChild(empty);
        }
      }
      var totalEl = qs(root, '[data-vila-childcare-total]');
      if (totalEl) totalEl.textContent = formatMoney(root, total);
      syncHiddenDates();
    }

    function renderChips() {
      chipsEl.innerHTML = '';
      selectedDates().forEach(function (ymd) {
        var label = document.createElement('label');
        label.className = 'vila-cb-mb__chip';
        label.innerHTML =
          '<input type="checkbox" name="vila_childcare_date_chip[]" value="' +
          ymd +
          '" checked /><span></span>';
        label.querySelector('span').textContent = formatLabel(ymd);
        label.querySelector('input').addEventListener('change', function () {
          delete selected[ymd];
          renderChips();
          renderCalendar();
        });
        chipsEl.appendChild(label);
      });
      updateSummary();
    }

    function availabilityReason(day) {
      if (!day) return '';
      if (!day.open) return txt(root, 'closed', 'Closed');
      if (day.cutoff_blocked) return txt(root, 'cutoff', 'Booking closed');
      if (day.full) return txt(root, 'full', 'Full');
      if (day.remaining === 1) return txt(root, 'remainingSingular', '1 place left');
      return txt(root, 'remainingPlural', '%d places left').replace('%d', day.remaining);
    }

    function renderMonth(grid, anchor, offset) {
      var d = addMonths(anchor, offset);
      var y = d.getFullYear();
      var m = d.getMonth();
      var firstDow = new Date(y, m, 1).getDay();
      var wrap = document.createElement('div');
      wrap.className = 'vila-cb-cal__month';
      wrap.innerHTML =
        '<div class="vila-cb-cal__title">' +
        d.toLocaleString(undefined, { month: 'long', year: 'numeric' }) +
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
      for (var day = 1; day <= daysInMonth(y, m); day++) {
        var dt = new Date(y, m, day);
        var key = ymdFromDate(dt);
        var info = availability[key];
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'vila-cb-cal__day';
        btn.textContent = String(day);
        btn.setAttribute('data-ymd', key);
        if (selected[key]) btn.classList.add('is-selected');
        if (!info || !info.bookable) {
          btn.classList.add('is-disabled');
          btn.disabled = true;
        }
        var reason = availabilityReason(info);
        if (reason) btn.setAttribute('title', reason);
        if (info && info.remaining >= 0) {
          var badge = document.createElement('small');
          badge.className = 'vila-cb-childcare__remaining';
          badge.textContent = info.bookable ? String(info.remaining) : reason;
          btn.appendChild(badge);
        }
        btn.addEventListener('click', function (ev) {
          var target = ev.currentTarget;
          if (target.disabled) return;
          var ymd = target.getAttribute('data-ymd');
          if (!ymd) return;
          if (selected[ymd]) delete selected[ymd];
          else selected[ymd] = true;
          renderChips();
          renderCalendar();
          setMessage('', false);
        });
        cells.appendChild(btn);
      }
      grid.appendChild(wrap);
    }

    function fetchAvailability() {
      var first = ymdFromDate(calendarLeft);
      var end = addMonths(calendarLeft, 2);
      end = new Date(end.getFullYear(), end.getMonth(), 0);
      var url = new URL(cfg(root).availabilityUrl || '/wp-json/vila-campsite-booking/v1/childcare/availability', window.location.origin);
      url.searchParams.set('from', first);
      url.searchParams.set('to', ymdFromDate(end));
      url.searchParams.set('children', Math.max(1, namedChildren(root).length));
      return fetch(url.toString(), { credentials: 'same-origin' })
        .then(function (res) {
          return res.json().then(function (json) {
            if (!res.ok) throw new Error((json && json.message) || res.statusText);
            return json;
          });
        })
        .then(function (json) {
          availability = availabilityMapFromDays(json.days || []);
          selectedDates().forEach(function (ymd) {
            if (availability[ymd] && !availability[ymd].bookable) delete selected[ymd];
          });
        })
        .catch(function () {
          availability = {};
        });
    }

    function renderCalendar() {
      calendarEl.innerHTML = '';
      var nav = document.createElement('div');
      nav.className = 'vila-cb-cal__nav';
      nav.innerHTML =
        '<button type="button" class="button" data-vila-nav="-1" aria-label="Previous month">←</button>' +
        '<button type="button" class="button" data-vila-nav="1" aria-label="Next month">→</button>';
      nav.addEventListener('click', function (e) {
        var t = e.target.closest('[data-vila-nav]');
        if (!t) return;
        calendarLeft = addMonths(calendarLeft, parseInt(t.getAttribute('data-vila-nav'), 10) || 0);
        fetchAvailability().then(function () {
          renderChips();
          renderCalendar();
        });
      });
      calendarEl.appendChild(nav);
      var grid = document.createElement('div');
      grid.className = 'vila-cb-cal__grid-inner';
      renderMonth(grid, calendarLeft, 0);
      renderMonth(grid, calendarLeft, 1);
      calendarEl.appendChild(grid);
    }

    function childRow(child) {
      var row = document.createElement('div');
      row.className = 'vila-cb-childcare__child';
      row.setAttribute('data-vila-childcare-child', '');
      row.innerHTML =
        '<div class="vila-cb-gr__grid">' +
        '<label><span>Child name</span><input type="text" name="vila_childcare_child_name[]" required data-vila-childcare-child-name /></label>' +
        '<label><span>Age</span><input type="number" min="0" max="17" step="1" name="vila_childcare_child_age[]" required data-vila-childcare-child-age /></label>' +
        '</div>' +
        '<label><span>Allergies or medical things</span><textarea name="vila_childcare_child_allergies[]" rows="3" data-vila-childcare-child-allergies></textarea></label>' +
        '<button type="button" class="vila-cb-gr__manual vila-cb-childcare__remove" data-vila-childcare-remove-child>Remove</button>';
      qs(row, '[data-vila-childcare-child-name]').value = child && child.name ? child.name : '';
      qs(row, '[data-vila-childcare-child-age]').value = child && child.age !== undefined ? child.age : '';
      qs(row, '[data-vila-childcare-child-allergies]').value = child && child.allergies ? child.allergies : '';
      qs(row, '[data-vila-childcare-remove-child]').textContent = txt(root, 'removeChild', 'Remove');
      qs(row, '[data-vila-childcare-remove-child]').addEventListener('click', function () {
        if (qsa(root, '[data-vila-childcare-child]').length <= 1) {
          qs(row, '[data-vila-childcare-child-name]').value = '';
          qs(row, '[data-vila-childcare-child-age]').value = '';
          qs(row, '[data-vila-childcare-child-allergies]').value = '';
        } else {
          row.parentNode.removeChild(row);
        }
        fetchAvailability().then(function () {
          renderChips();
          renderCalendar();
        });
        saveRemembered();
      });
      ['input', 'change'].forEach(function (eventName) {
        row.addEventListener(eventName, function () {
          updateSummary();
          fetchAvailability().then(function () {
            renderChips();
            renderCalendar();
          });
          saveRemembered();
        });
      });
      return row;
    }

    function addChild(child) {
      childrenWrap.appendChild(childRow(child || {}));
      updateSummary();
      fetchAvailability().then(renderCalendar);
    }

    function rememberedProfile() {
      try {
        return JSON.parse(window.localStorage.getItem(STORAGE_KEY) || 'null');
      } catch (e) {
        return null;
      }
    }

    function saveRemembered() {
      if (!remember || !remember.checked) return;
      try {
        window.localStorage.setItem(
          STORAGE_KEY,
          JSON.stringify({
            parent_name: (qs(root, '[data-vila-childcare-parent-name]') || {}).value || '',
            parent_phone: (qs(root, '[data-vila-childcare-parent-phone]') || {}).value || '',
            telegram_username: (qs(root, '[data-vila-childcare-telegram]') || {}).value || '',
            children: childData(root),
          })
        );
      } catch (e) {
        // localStorage may be unavailable in private browsing.
      }
    }

    function loadRemembered() {
      var profile = rememberedProfile();
      if (!profile) return false;
      remember.checked = true;
      qs(root, '[data-vila-childcare-parent-name]').value = profile.parent_name || '';
      qs(root, '[data-vila-childcare-parent-phone]').value = profile.parent_phone || '';
      qs(root, '[data-vila-childcare-telegram]').value = profile.telegram_username || '';
      childrenWrap.innerHTML = '';
      (profile.children && profile.children.length ? profile.children : [{}]).forEach(addChild);
      return true;
    }

    qsa(root, '[data-vila-childcare-parent-name], [data-vila-childcare-parent-phone], [data-vila-childcare-telegram]').forEach(function (input) {
      input.addEventListener('input', saveRemembered);
    });

    if (remember) {
      remember.addEventListener('change', function () {
        if (remember.checked) saveRemembered();
        else {
          try {
            window.localStorage.removeItem(STORAGE_KEY);
          } catch (e) {}
        }
      });
    }

    addChildBtn.addEventListener('click', function () {
      addChild({});
      saveRemembered();
    });

    form.addEventListener('submit', function (e) {
      if (!String((qs(root, '[data-vila-childcare-parent-name]') || {}).value || '').trim()) {
        e.preventDefault();
        setMessage(txt(root, 'needParent', 'Please enter the parent name.'), true);
        return;
      }
      if (!String((qs(root, '[data-vila-childcare-parent-phone]') || {}).value || '').trim()) {
        e.preventDefault();
        setMessage(txt(root, 'needPhone', 'Please enter a phone number.'), true);
        return;
      }
      if (!namedChildren(root).length) {
        e.preventDefault();
        setMessage(txt(root, 'needChild', 'Add at least one child name.'), true);
        return;
      }
      if (!selectedDates().length) {
        e.preventDefault();
        setMessage(txt(root, 'needDates', 'Select at least one childcare date.'), true);
        return;
      }
      var unavailable = selectedDates().some(function (ymd) {
        return availability[ymd] && !availability[ymd].bookable;
      });
      if (unavailable) {
        e.preventDefault();
        setMessage(txt(root, 'notAvailable', 'Some selected dates are no longer available.'), true);
        return;
      }
      saveRemembered();
      syncHiddenDates();
    });

    if (!loadRemembered()) {
      addChild({});
    }
    fetchAvailability().then(function () {
      renderChips();
      renderCalendar();
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    qsa(document, '[data-vila-childcare-root]').forEach(init);
  });
})();
