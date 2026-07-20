/**
 * Guest legal registration form for SIBA export.
 */
(function () {
  'use strict';

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

  function api(root, url, opts) {
    opts = opts || {};
    opts.credentials = 'same-origin';
    opts.headers = opts.headers || {};
    var nonce = cfg(root).nonce || '';
    if (!nonce) {
      return Promise.reject(new Error('Security token missing. Please reload the page and try again.'));
    }
    // X-WP-Nonce (wp_rest) keeps the logged-in user context; without it REST forces uid 0 and our nonce fails.
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
    var results = qs(root, '[data-vila-gr-results]');
    var manualBtn = qs(root, '[data-vila-gr-manual]');
    var form = qs(root, '[data-vila-gr-form]');
    var manualFields = qs(root, '[data-vila-gr-manual-fields]');
    var selectedText = qs(root, '[data-vila-gr-selected]');
    var personsWrap = qs(root, '[data-vila-gr-persons]');
    var countInput = qs(root, '[data-vila-gr-count]');
    var message = qs(root, '[data-vila-gr-message]');
    var selectedBooking = null;
    var lookupEmail = '';

    function setMessage(text, isError) {
      message.textContent = text || '';
      message.classList.toggle('is-error', !!isError);
    }

    function syncManualRequirements(manual) {
      manualFields.querySelectorAll('input[name="check_in"], input[name="check_out"]').forEach(function (input) {
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
        '<legend>' + txt(root, 'guestHeading', 'Guest') + ' ' + n + '</legend>' +
        '<div class="vila-cb-gr__grid">' +
        '<label><span>First names</span><input type="text" name="given_name" autocomplete="given-name" required></label>' +
        '<label><span>Surname</span><input type="text" name="surname" autocomplete="family-name" required></label>' +
        '<label class="vila-cb-gr__wide vila-cb-gr__check"><input type="checkbox" name="skip_siba" data-vila-gr-skip> <span>Portuguese citizen (SIBA not required)</span></label>' +
        '<div class="vila-cb-gr__siba vila-cb-gr__grid vila-cb-gr__wide">' +
        '<label><span>Nationality (ICAO, e.g. GBR)</span><input type="text" name="nationality" maxlength="3" required></label>' +
        '<label><span>Date of birth</span><input type="date" name="date_of_birth" required></label>' +
        '<label><span>Document type</span><select name="document_type" required><option value="">Choose</option><option value="P">Passport</option><option value="B">National ID</option><option value="O">Other</option></select></label>' +
        '<label><span>Document number</span><input type="text" name="document_number" maxlength="32" required></label>' +
        '<label><span>Issuing country (ICAO)</span><input type="text" name="document_country" maxlength="3" required></label>' +
        '<label><span>Country of residence (ICAO)</span><input type="text" name="country_of_residence" maxlength="3" required></label>' +
        '</div></div></fieldset>'
      );
    }

    function renderPersons(count) {
      var n = Math.max(1, Math.min(20, parseInt(count, 10) || 1));
      countInput.value = String(n);
      personsWrap.innerHTML = '';
      for (var i = 0; i < n; i++) {
        personsWrap.insertAdjacentHTML('beforeend', personMarkup(i));
      }
      personsWrap.querySelectorAll('[data-vila-gr-skip]').forEach(function (box) {
        box.addEventListener('change', function () {
          var fieldset = box.closest('[data-vila-gr-person]');
          var siba = qs(fieldset, '.vila-cb-gr__siba');
          var nat = qs(fieldset, 'input[name="nationality"]');
          siba.hidden = box.checked;
          siba.querySelectorAll('input, select').forEach(function (input) {
            input.required = !box.checked;
            input.disabled = box.checked;
          });
          if (box.checked && nat) {
            nat.disabled = false;
            nat.value = 'PRT';
          }
        });
      });
      personsWrap.querySelectorAll('input[maxlength="3"]').forEach(function (input) {
        input.addEventListener('input', function () {
          input.value = input.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3);
        });
      });
    }

    function showForm(manual, booking) {
      selectedBooking = booking || null;
      form.hidden = false;
      manualFields.hidden = !manual;
      syncManualRequirements(manual);
      qs(root, '[data-vila-gr-booking-id]').value = booking ? String(booking.booking_id) : '0';
      qs(root, '[data-vila-gr-order-id]').value = booking ? String(booking.order_id || 0) : '0';
      if (booking) {
        selectedText.textContent = booking.booker_name + ': ' + booking.check_in + ' to ' + booking.check_out;
        renderPersons(booking.suggested_guests || 1);
      } else {
        selectedText.textContent = 'Manual stay details';
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
            results.textContent = txt(root, 'noBookings', 'No matching booking found. You can enter the stay details manually.');
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
      showForm(true, null);
    });

    countInput.addEventListener('change', function () {
      renderPersons(countInput.value);
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      setMessage('', false);
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
          form.hidden = true;
          setMessage(txt(root, 'saved', 'Thank you. Your legal guest registration details have been saved.'), false);
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
