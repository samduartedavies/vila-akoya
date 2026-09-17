/**
 * Guest legal registration form for SIBA export.
 */
(function () {
  'use strict';

  var COUNTRIES = window.vilaIcaoCountries || [];
  var POPULAR = ['GBR', 'PRT', 'D', 'FRA', 'ESP', 'NLD', 'BEL', 'IRL', 'USA', 'CHE', 'ITA', 'BRA', 'CAN', 'AUS', 'POL', 'SWE', 'NOR', 'DNK', 'FIN', 'AUT'];

  function cfg(root) {
    var globalCfg = window.vilaGuestRegistration || {};
    if (!root) {
      return globalCfg;
    }
    return {
      lookupUrl: root.getAttribute('data-lookup-url') || globalCfg.lookupUrl || '',
      submitUrl: root.getAttribute('data-submit-url') || globalCfg.submitUrl || '',
      nonce: root.getAttribute('data-nonce') || globalCfg.nonce || '',
      strings: globalCfg.strings || {},
    };
  }

  function txt(root, key, fallback) {
    var strings = cfg(root).strings || {};
    return strings[key] || fallback;
  }

  function qs(root, sel) {
    return root.querySelector(sel);
  }

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text) node.textContent = text;
    return node;
  }

  function countryByCode(code) {
    code = String(code || '').toUpperCase();
    for (var i = 0; i < COUNTRIES.length; i++) {
      if (COUNTRIES[i].code === code) {
        return COUNTRIES[i];
      }
    }
    return null;
  }

  function countryLabel(item) {
    return item.name + ' (' + item.code + ')';
  }

  function filterCountries(query) {
    var q = String(query || '').trim().toLowerCase();
    var popularSet = {};
    POPULAR.forEach(function (c) {
      popularSet[c] = true;
    });
    var list = COUNTRIES.slice();
    if (!q) {
      var top = [];
      POPULAR.forEach(function (code) {
        var item = countryByCode(code);
        if (item) {
          top.push(item);
        }
      });
      var rest = list.filter(function (item) {
        return !popularSet[item.code];
      });
      return top.concat(rest);
    }
    return list.filter(function (item) {
      return (
        item.code.toLowerCase().indexOf(q) === 0 ||
        item.name.toLowerCase().indexOf(q) !== -1 ||
        (item.code + ' ' + item.name).toLowerCase().indexOf(q) !== -1
      );
    });
  }

  function countryFieldMarkup(name, label) {
    return (
      '<label class="vila-cb-gr__country">' +
      '<span>' + label + '</span>' +
      '<div class="vila-cb-gr__country-wrap" data-vila-gr-country data-name="' + name + '">' +
      '<input type="hidden" name="' + name + '" value="" required />' +
      '<input type="text" class="vila-cb-gr__country-search" autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" placeholder="Search country…" />' +
      '<ul class="vila-cb-gr__country-list" role="listbox" hidden></ul>' +
      '</div></label>'
    );
  }

  function initCountryPicker(wrap) {
    var hidden = qs(wrap, 'input[type="hidden"]');
    var search = qs(wrap, '.vila-cb-gr__country-search');
    var list = qs(wrap, '.vila-cb-gr__country-list');
    var active = -1;

    function close() {
      list.hidden = true;
      search.setAttribute('aria-expanded', 'false');
      active = -1;
    }

    function setValue(code) {
      var item = countryByCode(code);
      hidden.value = item ? item.code : '';
      search.value = item ? countryLabel(item) : '';
      close();
    }

    function renderList(query) {
      var matches = filterCountries(query).slice(0, 80);
      list.innerHTML = '';
      if (!matches.length) {
        var empty = el('li', 'vila-cb-gr__country-empty', 'No matches');
        list.appendChild(empty);
        list.hidden = false;
        search.setAttribute('aria-expanded', 'true');
        return;
      }
      matches.forEach(function (item, idx) {
        var li = el('li', 'vila-cb-gr__country-option', countryLabel(item));
        li.setAttribute('role', 'option');
        li.setAttribute('data-code', item.code);
        li.addEventListener('mousedown', function (event) {
          event.preventDefault();
          setValue(item.code);
        });
        if (idx === active) {
          li.classList.add('is-active');
        }
        list.appendChild(li);
      });
      list.hidden = false;
      search.setAttribute('aria-expanded', 'true');
    }

    function moveActive(delta) {
      var options = list.querySelectorAll('[data-code]');
      if (!options.length) {
        return;
      }
      active = (active + delta + options.length) % options.length;
      options.forEach(function (opt, idx) {
        opt.classList.toggle('is-active', idx === active);
        if (idx === active) {
          opt.scrollIntoView({ block: 'nearest' });
        }
      });
    }

    search.addEventListener('focus', function () {
      if (hidden.value && countryByCode(hidden.value)) {
        search.value = '';
      }
      renderList(search.value);
    });

    search.addEventListener('input', function () {
      hidden.value = '';
      active = -1;
      renderList(search.value);
    });

    search.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        if (list.hidden) {
          renderList(search.value);
        }
        moveActive(1);
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        moveActive(-1);
      } else if (event.key === 'Enter') {
        var options = list.querySelectorAll('[data-code]');
        if (!list.hidden && options.length) {
          event.preventDefault();
          var pick = options[active >= 0 ? active : 0];
          setValue(pick.getAttribute('data-code'));
        }
      } else if (event.key === 'Escape') {
        if (hidden.value) {
          setValue(hidden.value);
        } else {
          close();
        }
      }
    });

    search.addEventListener('blur', function () {
      window.setTimeout(function () {
        if (hidden.value && countryByCode(hidden.value)) {
          search.value = countryLabel(countryByCode(hidden.value));
        } else if (!hidden.value) {
          search.value = '';
        }
        close();
      }, 120);
    });

    wrap._vilaSetCountry = setValue;
    wrap._vilaDisableCountry = function (disabled) {
      search.disabled = !!disabled;
      hidden.disabled = !!disabled;
      if (disabled) {
        close();
      }
    };
  }

  function api(root, url, opts) {
    opts = opts || {};
    opts.credentials = 'same-origin';
    opts.headers = opts.headers || {};
    var nonce = cfg(root).nonce || '';
    if (!nonce) {
      return Promise.reject(new Error('Security token missing. Please reload the page and try again.'));
    }
    opts.headers['X-WP-Nonce'] = nonce;
    if (opts.body && !opts.headers['Content-Type']) {
      opts.headers['Content-Type'] = 'application/json';
    }
    return fetch(url, opts).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok) {
          throw new Error((data && data.message) || response.statusText);
        }
        return data;
      });
    });
  }

  function init(root) {
    var lookupForm = qs(root, '[data-vila-gr-lookup-form]');
    var lookupPanel = qs(root, '[data-vila-gr-lookup-panel]');
    var intro = qs(root, '[data-vila-gr-intro]');
    var results = qs(root, '[data-vila-gr-results]');
    var manualBtn = qs(root, '[data-vila-gr-manual]');
    var form = qs(root, '[data-vila-gr-form]');
    var manualFields = qs(root, '[data-vila-gr-manual-fields]');
    var stayTypeWrap = qs(root, '[data-vila-gr-stay-type]');
    var selectedText = qs(root, '[data-vila-gr-selected]');
    var personsWrap = qs(root, '[data-vila-gr-persons]');
    var countInput = qs(root, '[data-vila-gr-count]');
    var message = qs(root, '[data-vila-gr-message]');
    var successPanel = qs(root, '[data-vila-gr-success]');
    var successStay = qs(root, '[data-vila-gr-success-stay]');
    var peopleTitle = qs(root, '[data-vila-gr-people-title]');
    var addGuestBtn = qs(root, '[data-vila-gr-add-guest]');
    var backBtn = qs(root, '[data-vila-gr-back]');
    var selectedBooking = null;
    var lookupEmail = '';

    function stayType() {
      var checked = form.querySelector('input[name="stay_type"]:checked');
      return checked ? checked.value : 'guest';
    }

    function personHeading() {
      return stayType() === 'volunteer'
        ? txt(root, 'personHeading', 'Person')
        : txt(root, 'guestHeading', 'Guest');
    }

    function setStayType(value) {
      form.querySelectorAll('input[name="stay_type"]').forEach(function (input) {
        input.checked = input.value === value;
      });
      syncStayTypeLabels();
    }

    function syncStayTypeLabels() {
      var volunteer = stayType() === 'volunteer';
      if (peopleTitle) {
        peopleTitle.textContent = volunteer ? 'People staying' : 'Guests';
      }
      if (addGuestBtn) {
        addGuestBtn.textContent = volunteer
          ? txt(root, 'addPerson', 'Add person')
          : txt(root, 'addGuest', 'Add guest');
      }
      personsWrap.querySelectorAll('[data-vila-gr-person]').forEach(function (fieldset, i) {
        var title = qs(fieldset, '.vila-cb-gr__person-title');
        if (title) {
          title.textContent = personHeading() + ' ' + (i + 1);
        }
      });
    }

    function setMessage(text, isError) {
      if (successPanel && !successPanel.hidden) {
        return;
      }
      message.textContent = text || '';
      message.classList.toggle('is-error', !!isError);
      message.hidden = !text;
    }

    function showSuccess() {
      if (intro) {
        intro.hidden = true;
      }
      if (lookupPanel) {
        lookupPanel.hidden = true;
      }
      form.hidden = true;
      message.textContent = '';
      message.hidden = true;
      if (successPanel) {
        successPanel.hidden = false;
        if (successStay) {
          var cin = selectedBooking ? selectedBooking.check_in : '';
          var cout = selectedBooking ? selectedBooking.check_out : '';
          if (!cin || !cout) {
            var fd = new FormData(form);
            cin = String(fd.get('check_in') || '');
            cout = String(fd.get('check_out') || '');
          }
          if (cin && cout) {
            successStay.textContent = txt(root, 'successStay', 'Your stay: %1$s to %2$s')
              .replace('%1$s', cin)
              .replace('%2$s', cout);
            successStay.hidden = false;
          } else {
            successStay.hidden = true;
          }
        }
        successPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } else {
        setMessage(txt(root, 'saved', 'Thank you. Your legal guest registration details have been saved.'), false);
      }
    }

    function syncManualRequirements(manual) {
      manualFields.querySelectorAll('input[name="booker_name"], input[name="contact_email"], input[name="check_in"], input[name="check_out"]').forEach(function (input) {
        input.required = !!manual;
        input.disabled = !manual;
        if (!manual) {
          input.value = '';
        }
      });
    }

    function personMarkup(i) {
      var n = i + 1;
      return (
        '<fieldset class="vila-cb-gr__person" data-vila-gr-person>' +
        '<legend>' +
        '<span class="vila-cb-gr__person-title">' + personHeading() + ' ' + n + '</span>' +
        '<button type="button" class="vila-cb-gr__remove-guest" data-vila-gr-remove-guest hidden>' +
        txt(root, 'removeGuest', 'Remove') +
        '</button>' +
        '</legend>' +
        '<div class="vila-cb-gr__grid">' +
        '<label><span>First names</span><input type="text" name="given_name" autocomplete="given-name" required></label>' +
        '<label><span>Surname</span><input type="text" name="surname" autocomplete="family-name" required></label>' +
        '<label class="vila-cb-gr__wide vila-cb-gr__check"><input type="checkbox" name="skip_siba" data-vila-gr-skip> <span>Portuguese citizen (SIBA not required)</span></label>' +
        '<div class="vila-cb-gr__siba vila-cb-gr__grid vila-cb-gr__wide">' +
        countryFieldMarkup('nationality', 'Nationality') +
        '<label><span>Date of birth</span><input type="date" name="date_of_birth" min="1900-01-01" required></label>' +
        '<label><span>Document type</span><select name="document_type" required><option value="">Choose</option><option value="P">Passport</option><option value="B">National ID</option><option value="O">Other</option></select></label>' +
        '<label><span>Document number</span><input type="text" name="document_number" maxlength="32" required></label>' +
        countryFieldMarkup('document_country', 'Issuing country') +
        countryFieldMarkup('country_of_residence', 'Country of residence') +
        '</div></div></fieldset>'
      );
    }

    function personCount() {
      return personsWrap.querySelectorAll('[data-vila-gr-person]').length;
    }

    function syncCountInput() {
      countInput.value = String(Math.max(1, personCount()));
    }

    function renumberPersons() {
      personsWrap.querySelectorAll('[data-vila-gr-person]').forEach(function (fieldset, i) {
        var title = qs(fieldset, '.vila-cb-gr__person-title');
        if (title) {
          title.textContent = personHeading() + ' ' + (i + 1);
        }
        var removeBtn = qs(fieldset, '[data-vila-gr-remove-guest]');
        if (removeBtn) {
          removeBtn.hidden = personCount() <= 1;
        }
      });
      syncCountInput();
      var addBtn = qs(root, '[data-vila-gr-add-guest]');
      if (addBtn) {
        addBtn.disabled = personCount() >= 20;
      }
    }

    function bindSkipToggle(box) {
      box.addEventListener('change', function () {
        var fieldset = box.closest('[data-vila-gr-person]');
        var siba = qs(fieldset, '.vila-cb-gr__siba');
        var natWrap = qs(fieldset, '[data-vila-gr-country][data-name="nationality"]');
        siba.hidden = box.checked;
        siba.querySelectorAll('input, select').forEach(function (input) {
          if (input.classList.contains('vila-cb-gr__country-search')) {
            return;
          }
          if (input.type === 'hidden' && input.closest('[data-vila-gr-country]')) {
            return;
          }
          input.required = !box.checked;
          input.disabled = box.checked;
        });
        fieldset.querySelectorAll('[data-vila-gr-country]').forEach(function (wrap) {
          if (wrap._vilaDisableCountry) {
            wrap._vilaDisableCountry(box.checked);
          }
          var hidden = qs(wrap, 'input[type="hidden"]');
          if (hidden) {
            hidden.required = !box.checked;
          }
        });
        if (box.checked && natWrap && natWrap._vilaSetCountry) {
          natWrap._vilaDisableCountry(false);
          natWrap._vilaSetCountry('PRT');
          qs(natWrap, 'input[type="hidden"]').required = false;
        }
      });
    }

    function bindPerson(fieldset) {
      fieldset.querySelectorAll('[data-vila-gr-country]').forEach(initCountryPicker);
      var skip = qs(fieldset, '[data-vila-gr-skip]');
      if (skip) {
        bindSkipToggle(skip);
      }
      var removeBtn = qs(fieldset, '[data-vila-gr-remove-guest]');
      if (removeBtn) {
        removeBtn.addEventListener('click', function () {
          if (personCount() <= 1) {
            return;
          }
          fieldset.remove();
          renumberPersons();
        });
      }
    }

    function appendPerson() {
      if (personCount() >= 20) {
        return null;
      }
      personsWrap.insertAdjacentHTML('beforeend', personMarkup(personCount()));
      var fieldset = personsWrap.lastElementChild;
      bindPerson(fieldset);
      renumberPersons();
      return fieldset;
    }

    function renderPersons(count) {
      var n = Math.max(1, Math.min(20, parseInt(count, 10) || 1));
      var current = personCount();
      if (n === current) {
        renumberPersons();
        return;
      }
      if (n > current) {
        while (personCount() < n) {
          appendPerson();
        }
        return;
      }
      while (personCount() > n) {
        var last = personsWrap.lastElementChild;
        if (!last) {
          break;
        }
        last.remove();
      }
      renumberPersons();
    }

    function addGuest() {
      var fieldset = appendPerson();
      if (!fieldset) {
        return;
      }
      fieldset.scrollIntoView({ behavior: 'smooth', block: 'center' });
      var first = qs(fieldset, 'input[name="given_name"]');
      if (first) {
        first.focus();
      }
    }

    function showForm(manual, booking, type) {
      selectedBooking = booking || null;
      form.hidden = false;
      if (lookupPanel) {
        lookupPanel.hidden = true;
      }
      if (backBtn) {
        backBtn.hidden = false;
      }
      manualFields.hidden = !manual;
      if (stayTypeWrap) {
        stayTypeWrap.hidden = !manual;
      }
      syncManualRequirements(manual);
      setStayType(type || (booking ? 'guest' : 'volunteer'));
      qs(root, '[data-vila-gr-booking-id]').value = booking ? String(booking.booking_id) : '0';
      qs(root, '[data-vila-gr-order-id]').value = booking ? String(booking.order_id || 0) : '0';
      personsWrap.innerHTML = '';
      if (booking) {
        selectedText.textContent = booking.booker_name + ': ' + booking.check_in + ' to ' + booking.check_out;
        renderPersons(booking.suggested_guests || 1);
      } else {
        selectedText.textContent = txt(root, 'manualStay', 'Checking in without a booking');
        renderPersons(1);
      }
      setMessage('', false);
      form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    lookupForm.addEventListener('submit', function (event) {
      event.preventDefault();
      setMessage('', false);
      results.textContent = txt(root, 'searching', 'Searching bookings...');
      var data = new FormData(lookupForm);
      lookupEmail = String(data.get('email') || '');
      var u = new URL(cfg(root).lookupUrl || '/wp-json/vila-campsite-booking/v1/guest-registration/lookup', window.location.origin);
      u.searchParams.set('last_name', String(data.get('last_name') || ''));
      u.searchParams.set('email', lookupEmail);
      api(root, u.toString())
        .then(function (payload) {
          results.innerHTML = '';
          var bookings = payload.bookings || [];
          if (!bookings.length) {
            results.textContent = txt(root, 'noBookings', 'No matching booking found. You can check in without a booking instead.');
            return;
          }
          bookings.forEach(function (booking) {
            var card = el('div', 'vila-cb-gr__result');
            var text = el('p', '', booking.booker_name + ': ' + booking.check_in + ' to ' + booking.check_out + ' · ' + booking.suggested_guests + ' people');
            var btn = el('button', 'vila-cb-gr__result-btn', txt(root, 'selectBooking', 'Use this booking'));
            btn.type = 'button';
            btn.addEventListener('click', function () {
              showForm(false, booking);
            });
            card.appendChild(text);
            card.appendChild(btn);
            results.appendChild(card);
          });
        })
        .catch(function (err) {
          results.textContent = '';
          setMessage(err.message || txt(root, 'failed', 'Could not complete the search.'), true);
        });
    });

    manualBtn.addEventListener('click', function () {
      showForm(true, null, 'volunteer');
    });

    if (stayTypeWrap) {
      stayTypeWrap.addEventListener('change', syncStayTypeLabels);
    }

    if (backBtn) {
      backBtn.addEventListener('click', function () {
        form.hidden = true;
        backBtn.hidden = true;
        if (lookupPanel) {
          lookupPanel.hidden = false;
        }
        selectedBooking = null;
        setMessage('', false);
        if (lookupPanel) {
          lookupPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      });
    }

    countInput.addEventListener('change', function () {
      renderPersons(countInput.value);
    });

    var addGuestBtnBind = qs(root, '[data-vila-gr-add-guest]');
    if (addGuestBtnBind) {
      addGuestBtnBind.addEventListener('click', addGuest);
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      setMessage('', false);
      var missingCountry = false;
      personsWrap.querySelectorAll('[data-vila-gr-person]').forEach(function (row) {
        if (qs(row, '[name="skip_siba"]').checked) {
          return;
        }
        row.querySelectorAll('[data-vila-gr-country] input[type="hidden"]').forEach(function (hidden) {
          if (!hidden.value) {
            missingCountry = true;
            var search = qs(hidden.closest('[data-vila-gr-country]'), '.vila-cb-gr__country-search');
            if (search) {
              search.focus();
            }
          }
        });
      });
      if (missingCountry) {
        setMessage(txt(root, 'required', 'Please complete the required fields.'), true);
        return;
      }
      var fd = new FormData(form);
      var persons = [];
      personsWrap.querySelectorAll('[data-vila-gr-person]').forEach(function (row) {
        var skip = !!qs(row, '[name="skip_siba"]').checked;
        persons.push({
          given_name: qs(row, '[name="given_name"]').value,
          surname: qs(row, '[name="surname"]').value,
          skip_siba: skip,
          nationality: skip ? 'PRT' : qs(row, '[name="nationality"]').value,
          date_of_birth: skip ? '' : qs(row, '[name="date_of_birth"]').value,
          document_type: skip ? '' : qs(row, '[name="document_type"]').value,
          document_number: skip ? '' : qs(row, '[name="document_number"]').value,
          document_country: skip ? '' : qs(row, '[name="document_country"]').value,
          country_of_residence: skip ? '' : qs(row, '[name="country_of_residence"]').value,
        });
      });
      var payload = {
        website: String(fd.get('website') || ''),
        booking_id: selectedBooking ? selectedBooking.booking_id : 0,
        order_id: selectedBooking ? selectedBooking.order_id || 0 : 0,
        stay_type: selectedBooking ? 'guest' : stayType(),
        booker_name: selectedBooking ? selectedBooking.booker_name : String(fd.get('booker_name') || ''),
        contact_email: selectedBooking ? lookupEmail : String(fd.get('contact_email') || ''),
        check_in: selectedBooking ? selectedBooking.check_in : String(fd.get('check_in') || ''),
        check_out: selectedBooking ? selectedBooking.check_out : String(fd.get('check_out') || ''),
        persons: persons,
      };
      api(root, cfg(root).submitUrl || '/wp-json/vila-campsite-booking/v1/guest-registration', {
        method: 'POST',
        body: JSON.stringify(payload),
      })
        .then(function () {
          showSuccess();
        })
        .catch(function (err) {
          setMessage(err.message || txt(root, 'failed', 'Could not save the registration.'), true);
        });
    });

    syncManualRequirements(false);
    renderPersons(1);
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-vila-gr-root]').forEach(init);
  });
})();
