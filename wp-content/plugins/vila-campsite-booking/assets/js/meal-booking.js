/**
 * Guest-facing meal booking form (find stay or walk-in) → WooCommerce checkout.
 */
(function () {
  'use strict';

  var DEFAULT_PRICING = {
    breakfast: { adult: 5, child: 3 },
    dinner: { adult: 10, child: 7.5 },
  };

  function pad2(n) {
    return n < 10 ? '0' + n : String(n);
  }

  function ymdFromDate(d) {
    return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
  }

  function parseYmd(str) {
    var p = String(str).split('-');
    if (p.length !== 3) return null;
    var d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
    return isNaN(d.getTime()) ? null : d;
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
    if (!el) {
      return fallback;
    }
    var raw = el.getAttribute(name);
    if (!raw) {
      return fallback;
    }
    try {
      return JSON.parse(raw);
    } catch (e) {
      return fallback;
    }
  }

  function cfg(root) {
    var globalCfg = window.vilaMealBooking || {};
    if (!root) {
      return {
        lookupUrl: globalCfg.lookupUrl || '',
        nonce: globalCfg.nonce || '',
        pricing: globalCfg.pricing || DEFAULT_PRICING,
        currency: globalCfg.currency || { symbol: '€', decimalSep: ',' },
        strings: globalCfg.strings || {},
      };
    }
    return {
      lookupUrl: root.getAttribute('data-lookup-url') || globalCfg.lookupUrl || '',
      nonce: root.getAttribute('data-nonce') || globalCfg.nonce || '',
      pricing: parseJsonAttr(root, 'data-pricing', globalCfg.pricing || DEFAULT_PRICING),
      currency: parseJsonAttr(root, 'data-currency', globalCfg.currency || { symbol: '€', decimalSep: ',' }),
      strings: globalCfg.strings || {},
    };
  }

  function mealRate(pricing, meal, key, fallback) {
    var row = pricing && pricing[meal];
    var value = row ? Number(row[key]) : NaN;
    return isFinite(value) ? value : fallback;
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

  function api(root, url) {
    var nonce = cfg(root).nonce || '';
    if (!nonce) {
      return Promise.reject(new Error('Security token missing. Please reload the page.'));
    }
    return fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': nonce },
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok) {
          throw new Error((data && data.message) || response.statusText);
        }
        return data;
      });
    });
  }

  function formatMoney(root, amount) {
    var c = cfg(root).currency || {};
    var sym = c.symbol || '€';
    var sep = c.decimalSep || ',';
    var n = Math.round((Number(amount) || 0) * 100) / 100;
    var whole = Math.abs(n - Math.round(n)) < 0.00001;
    if (whole) {
      return sym + String(Math.round(n));
    }
    var fixed = n.toFixed(2).replace('.', sep);
    return sym + fixed;
  }

  function isWeekdayYmd(ymd) {
    var p = String(ymd).split('-');
    if (p.length !== 3) return false;
    var d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
    var day = d.getDay();
    return day >= 1 && day <= 5;
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

  function mountMealCalendar(container, options) {
    var state = {
      left: monthStart(new Date()),
    };

    function highlightSelected() {
      qsa(container, '.vila-cb-cal__day').forEach(function (btn) {
        var key = btn.getAttribute('data-ymd');
        btn.classList.toggle('is-selected', !!(key && options.set[key]));
      });
    }

    function buildMonth(grid, anchor, offset) {
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

      var i;
      for (i = 0; i < firstDow; i++) {
        var ph = document.createElement('span');
        ph.className = 'vila-cb-cal__ph';
        cells.appendChild(ph);
      }
      for (var day = 1; day <= dim; day++) {
        var dt = new Date(y, m, day);
        var key = ymdFromDate(dt);
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'vila-cb-cal__day';
        btn.textContent = String(day);
        btn.setAttribute('data-ymd', key);
        if (options.weekdaysOnly && !isWeekdayYmd(key)) {
          btn.classList.add('is-disabled');
          btn.disabled = true;
        } else if (options.set[key]) {
          btn.classList.add('is-selected');
        }
        btn.addEventListener('click', function (ev) {
          var target = ev.currentTarget;
          if (target.disabled) return;
          var ymd = target.getAttribute('data-ymd');
          if (!ymd) return;
          if (options.weekdaysOnly && !isWeekdayYmd(ymd)) {
            options.onWeekdayError();
            return;
          }
          if (options.set[ymd]) {
            delete options.set[ymd];
          } else {
            options.set[ymd] = true;
          }
          highlightSelected();
          options.onChange();
        });
        cells.appendChild(btn);
      }
      grid.appendChild(wrap);
    }

    function render() {
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
        render();
      });
      container.appendChild(nav);
      var grid = document.createElement('div');
      grid.className = 'vila-cb-cal__grid-inner';
      buildMonth(grid, state.left, 0);
      buildMonth(grid, state.left, 1);
      container.appendChild(grid);
    }

    render();

    return {
      sync: highlightSelected,
      rerender: render,
    };
  }

  function init(root) {
    var lookupForm = qs(root, '[data-vila-mb-lookup-form]');
    var results = qs(root, '[data-vila-mb-results]');
    var form = qs(root, '[data-vila-mb-form]');
    var message = qs(root, '[data-vila-mb-message]');
    var breakfastWrap = qs(root, '[data-vila-mb-breakfast-dates]');
    var dinnerWrap = qs(root, '[data-vila-mb-dinner-dates]');
    var breakfastCalEl = qs(root, '[data-vila-mb-calendar="breakfast"]');
    var dinnerCalEl = qs(root, '[data-vila-mb-calendar="dinner"]');
    var breakfastSet = {};
    var dinnerSet = {};
    var counts = { adults: 1, children: 0, infants: 0 };
    var breakfastCal = null;
    var dinnerCal = null;

    function setMessage(text, isError) {
      message.textContent = text || '';
      message.classList.toggle('is-error', !!isError);
    }

    function mealLabel(pricing, meal, fallback) {
      var row = pricing && pricing[meal];
      return row && row.label ? String(row.label) : fallback;
    }

    function guestParts(adults, children) {
      var parts = [];
      var s = cfg(root).strings || {};
      if (adults > 0) {
        parts.push(adults + ' ' + (adults === 1 ? s.adult || 'adult' : s.adults || 'adults'));
      }
      if (children > 0) {
        parts.push(children + ' ' + (children === 1 ? s.child || 'child' : s.children || 'children'));
      }
      return parts.join(', ');
    }

    function perDayLabel(amount) {
      var template = txt(root, 'perDay', '%s / day');
      return template.replace('%s', formatMoney(root, amount));
    }

    function buildBreakdownLine(title, detail, amount) {
      var li = document.createElement('li');
      li.className = 'vila-cb-mb__breakdown-line';
      li.innerHTML =
        '<div class="vila-cb-mb__breakdown-main">' +
        '<span class="vila-cb-mb__breakdown-title"></span>' +
        '<span class="vila-cb-mb__breakdown-detail"></span>' +
        '</div>' +
        '<span class="vila-cb-mb__breakdown-amount"></span>';
      li.querySelector('.vila-cb-mb__breakdown-title').textContent = title;
      li.querySelector('.vila-cb-mb__breakdown-detail').textContent = detail;
      li.querySelector('.vila-cb-mb__breakdown-amount').textContent = amount;
      return li;
    }

    function updateTotal() {
      var P = cfg(root).pricing || DEFAULT_PRICING;
      var bDays = Object.keys(breakfastSet).length;
      var dDays = Object.keys(dinnerSet).length;
      var adults = Number(counts.adults) || 0;
      var children = Number(counts.children) || 0;
      var breakfastPerDay =
        adults * mealRate(P, 'breakfast', 'adult', DEFAULT_PRICING.breakfast.adult) +
        children * mealRate(P, 'breakfast', 'child', DEFAULT_PRICING.breakfast.child);
      var dinnerPerDay =
        adults * mealRate(P, 'dinner', 'adult', DEFAULT_PRICING.dinner.adult) +
        children * mealRate(P, 'dinner', 'child', DEFAULT_PRICING.dinner.child);
      var breakfastSub = bDays * breakfastPerDay;
      var dinnerSub = dDays * dinnerPerDay;
      var total = breakfastSub + dinnerSub;
      var guests = guestParts(adults, children);

      var breakdownEl = qs(root, '[data-vila-mb-breakdown]');
      if (breakdownEl) {
        breakdownEl.innerHTML = '';
        if (bDays > 0 && breakfastPerDay > 0) {
          breakdownEl.appendChild(
            buildBreakdownLine(
              mealLabel(P, 'breakfast', 'Breakfast') +
                ' · ' +
                bDays +
                ' ' +
                txt(root, 'mornings', 'mornings'),
              perDayLabel(breakfastPerDay) + (guests ? ' · ' + guests : ''),
              formatMoney(root, breakfastSub)
            )
          );
        }
        if (dDays > 0 && dinnerPerDay > 0) {
          breakdownEl.appendChild(
            buildBreakdownLine(
              mealLabel(P, 'dinner', 'Dinner') +
                ' · ' +
                dDays +
                ' ' +
                txt(root, 'evenings', 'evenings'),
              perDayLabel(dinnerPerDay) + (guests ? ' · ' + guests : ''),
              formatMoney(root, dinnerSub)
            )
          );
        }
        if (!breakdownEl.children.length) {
          var empty = document.createElement('li');
          empty.className = 'vila-cb-mb__breakdown-empty';
          empty.textContent = txt(root, 'breakdownEmpty', 'Select breakfast or dinner dates above to see your estimate.');
          breakdownEl.appendChild(empty);
        }
      }

      var el = qs(root, '[data-vila-mb-total]');
      if (el) {
        el.textContent = formatMoney(root, total);
      }
    }

    function clearSet(set) {
      Object.keys(set).forEach(function (key) {
        delete set[key];
      });
    }

    function syncCalendars() {
      if (breakfastCal) breakfastCal.sync();
      if (dinnerCal) dinnerCal.sync();
    }

    function renderDateChips(wrap, set, inputName) {
      wrap.innerHTML = '';
      Object.keys(set)
        .sort()
        .forEach(function (ymd) {
          var id = inputName + '-' + ymd;
          var label = document.createElement('label');
          label.className = 'vila-cb-mb__chip';
          label.innerHTML =
            '<input type="checkbox" name="' +
            inputName +
            '[]" value="' +
            ymd +
            '" id="' +
            id +
            '" checked />' +
            '<span>' +
            formatLabel(ymd) +
            '</span>';
          var input = label.querySelector('input');
          input.addEventListener('change', function () {
            if (!input.checked) {
              delete set[ymd];
              renderDateChips(wrap, set, inputName);
              syncCalendars();
              updateTotal();
            }
          });
          wrap.appendChild(label);
        });
      syncCalendars();
      updateTotal();
    }

    function onMealDatesChange(kind) {
      if (kind === 'breakfast') {
        renderDateChips(breakfastWrap, breakfastSet, 'vila_meal_breakfast_dates');
      } else {
        renderDateChips(dinnerWrap, dinnerSet, 'vila_meal_dinner_dates');
      }
      setMessage('', false);
    }

    function initCalendars() {
      if (breakfastCalEl && !breakfastCal) {
        breakfastCal = mountMealCalendar(breakfastCalEl, {
          set: breakfastSet,
          weekdaysOnly: false,
          onChange: function () {
            onMealDatesChange('breakfast');
          },
          onWeekdayError: function () {},
        });
      }
      if (dinnerCalEl && !dinnerCal) {
        dinnerCal = mountMealCalendar(dinnerCalEl, {
          set: dinnerSet,
          weekdaysOnly: true,
          onChange: function () {
            onMealDatesChange('dinner');
          },
          onWeekdayError: function () {
            setMessage(txt(root, 'needDates', 'Dinner is Monday–Friday only.'), true);
          },
        });
      }
    }

    function syncCountInputs() {
      qs(root, '[data-vila-mb-adults]').value = String(counts.adults);
      qs(root, '[data-vila-mb-children]').value = String(counts.children);
      qs(root, '[data-vila-mb-infants]').value = String(counts.infants);
      qsa(root, '[data-vila-val]').forEach(function (el) {
        var key = el.getAttribute('data-vila-val');
        if (counts[key] !== undefined) {
          el.textContent = String(counts[key]);
        }
      });
      updateTotal();
    }

    function showForm(booking) {
      form.hidden = false;
      qs(root, '[data-vila-mb-booking-id]').value = booking ? String(booking.booking_id) : '0';
      qs(root, '[data-vila-mb-order-id]').value = booking ? String(booking.order_id || 0) : '0';
      var nameInput = qs(root, '[data-vila-mb-name]');
      var emailInput = qs(root, '[data-vila-mb-email]');
      clearSet(breakfastSet);
      clearSet(dinnerSet);

      if (booking) {
        qs(root, '[data-vila-mb-selected]').textContent =
          txt(root, 'linked', 'Linked to your stay') +
          ' — ' +
          booking.booker_name +
          ' (' +
          booking.check_in +
          ' → ' +
          booking.check_out +
          ')';
        nameInput.value = booking.booker_name || '';
        counts.adults = Math.max(1, parseInt(booking.adults, 10) || 1);
        counts.children = Math.max(0, parseInt(booking.children, 10) || 0);
        counts.infants = Math.max(0, parseInt(booking.infants, 10) || 0);
        (booking.breakfast_dates || []).forEach(function (d) {
          breakfastSet[d] = true;
        });
        (booking.dinner_dates || []).forEach(function (d) {
          if (isWeekdayYmd(d)) dinnerSet[d] = true;
        });
      } else {
        qs(root, '[data-vila-mb-selected]').textContent = txt(root, 'standalone', 'Walk-in meal booking');
        nameInput.value = '';
        emailInput.value = '';
        counts = { adults: 1, children: 0, infants: 0 };
      }
      syncCountInputs();
      renderDateChips(breakfastWrap, breakfastSet, 'vila_meal_breakfast_dates');
      renderDateChips(dinnerWrap, dinnerSet, 'vila_meal_dinner_dates');
      if (breakfastCal) breakfastCal.sync();
      if (dinnerCal) dinnerCal.sync();
      form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    qsa(root, '[data-vila-step]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var key = btn.getAttribute('data-vila-step');
        var delta = parseInt(btn.getAttribute('data-delta'), 10) || 0;
        if (!counts.hasOwnProperty(key)) return;
        var next = counts[key] + delta;
        if (key === 'adults') next = Math.max(0, next);
        else next = Math.max(0, next);
        if (key === 'adults' && next + counts.children < 1 && delta < 0) return;
        counts[key] = next;
        if (counts.adults + counts.children < 1) {
          counts.adults = 1;
        }
        syncCountInputs();
      });
    });

    qs(root, '[data-vila-mb-standalone]').addEventListener('click', function () {
      results.innerHTML = '';
      showForm(null);
    });

    lookupForm.addEventListener('submit', function (e) {
      e.preventDefault();
      setMessage(txt(root, 'searching', 'Searching…'), false);
      results.innerHTML = '';
      var fd = new FormData(lookupForm);
      var u = new URL(cfg(root).lookupUrl || '/wp-json/vila-campsite-booking/v1/guest-registration/lookup', window.location.origin);
      u.searchParams.set('last_name', fd.get('last_name') || '');
      u.searchParams.set('email', fd.get('email') || '');
      api(root, u.toString())
        .then(function (data) {
          var bookings = (data && data.bookings) || [];
          if (!bookings.length) {
            setMessage(txt(root, 'noBookings', 'No matching booking found.'), false);
            return;
          }
          setMessage('', false);
          bookings.forEach(function (b) {
            var row = document.createElement('div');
            row.className = 'vila-cb-gr__result';
            row.innerHTML =
              '<div><strong></strong><span></span></div><button type="button" class="vila-cb__cta"></button>';
            row.querySelector('strong').textContent = b.booker_name || '';
            row.querySelector('span').textContent = b.check_in + ' → ' + b.check_out;
            var btn = row.querySelector('button');
            btn.textContent = txt(root, 'selectBooking', 'Use this booking');
            btn.addEventListener('click', function () {
              var emailField = lookupForm.querySelector('input[name="email"]');
              qs(root, '[data-vila-mb-email]').value = emailField ? emailField.value : '';
              showForm(b);
            });
            results.appendChild(row);
          });
        })
        .catch(function (err) {
          setMessage(err.message || 'Lookup failed', true);
        });
    });

    function syncDateHiddenInputs() {
      var breakfastHidden = qs(form, '[data-vila-mb-hidden-breakfast]');
      var dinnerHidden = qs(form, '[data-vila-mb-hidden-dinner]');
      if (breakfastHidden) {
        breakfastHidden.innerHTML = '';
        Object.keys(breakfastSet)
          .sort()
          .forEach(function (ymd) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'vila_meal_breakfast_dates[]';
            input.value = ymd;
            breakfastHidden.appendChild(input);
          });
      }
      if (dinnerHidden) {
        dinnerHidden.innerHTML = '';
        Object.keys(dinnerSet)
          .sort()
          .forEach(function (ymd) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'vila_meal_dinner_dates[]';
            input.value = ymd;
            dinnerHidden.appendChild(input);
          });
      }
    }

    form.addEventListener('submit', function (e) {
      var name = (qs(root, '[data-vila-mb-name]').value || '').trim();
      if (!name) {
        e.preventDefault();
        setMessage(txt(root, 'needName', 'Please enter your name.'), true);
        return;
      }
      if (counts.adults + counts.children < 1) {
        e.preventDefault();
        setMessage(txt(root, 'needGuests', 'Add at least one adult or child.'), true);
        return;
      }
      if (!Object.keys(breakfastSet).length && !Object.keys(dinnerSet).length) {
        e.preventDefault();
        setMessage(txt(root, 'needDates', 'Select at least one date.'), true);
        return;
      }
      syncCountInputs();
      syncDateHiddenInputs();
    });

    syncCountInputs();
    initCalendars();
  }

  document.addEventListener('DOMContentLoaded', function () {
    qsa(document, '[data-vila-mb-root]').forEach(init);
  });
})();
