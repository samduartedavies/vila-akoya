/**
 * Range calendar + pitch supplement hint for Bookings for WooCommerce (MWB).
 * Guest count for the core nightly rate is handled by MWB (#mwb_mbfw_people_number + AJAX totals).
 */
(function ($) {
  'use strict';

  var vilaGuestPickerDocBound = false;
  var vilaPlotSelectorBound = false;
  var vilaPlotDropdownBound = false;
  var vilaSelectedPlotId = '';

  /**
   * Pushes unavailable Y-m-d into MWB. When Vila Campsite Booking is active, that list is
   * fully_booked_dates (all pitches full that night), not per-plot theme transients.
   */
  function applySelectedPlotUnavailableDates() {
    if (typeof vilaAkoyaBooking === 'undefined') {
      return;
    }
    if (vilaAkoyaBooking.useSharedCampsiteInventory && Array.isArray(window.vilaMwbUnavailableList)) {
      if (typeof mwb_mbfw_public_obj !== 'undefined') {
        mwb_mbfw_public_obj.single_unavailable_dates = window.vilaMwbUnavailableList;
      }
      return;
    }
    if (!vilaAkoyaBooking.plotUnavailableYmd) {
      return;
    }
    var $chk = $('.vila-plot-input:checked');
    var pid = $chk.length ? String($chk.val()) : String(vilaAkoyaBooking.defaultPlotProductId || '');
    var map = vilaAkoyaBooking.plotUnavailableYmd;
    var dates = map[pid] || [];
    if (typeof mwb_mbfw_public_obj !== 'undefined') {
      mwb_mbfw_public_obj.single_unavailable_dates = dates;
    }
    window.vilaMwbUnavailableList = dates;
  }

  function setAddToCartProductId(productId) {
    var id = String(productId);
    $('form.cart')
      .find('input[name="add-to-cart"], button[name="add-to-cart"]')
      .val(id);
    $('form.cart').find('button[name="add-to-cart"]').attr('value', id);
  }

  function redrawRangePickerIfAny() {
    var fp = document.getElementById('vila-akoya-booking-range');
    if (fp && fp._flatpickr) {
      var inst = fp._flatpickr;
      inst.set('disable', [
        function (date) {
          var list = window.vilaMwbUnavailableList || [];
          var y =
            date.getFullYear() +
            '-' +
            pad2(date.getMonth() + 1) +
            '-' +
            pad2(date.getDate());
          return list.indexOf(y) !== -1;
        },
      ]);
    }
  }

  function initPlotSelector() {
    if (typeof vilaAkoyaBooking === 'undefined' || !vilaAkoyaBooking.plotProductIds || vilaAkoyaBooking.plotProductIds.length < 2) {
      return;
    }
    if (vilaPlotSelectorBound) {
      return;
    }
    vilaPlotSelectorBound = true;
    applySelectedPlotUnavailableDates();
    var $r = $('.vila-plot-input:checked');
    if ($r.length) {
      setAddToCartProductId($r.val());
    }
    $(document).on('change', '.vila-plot-input', function () {
      var pid = $(this).val();
      setAddToCartProductId(pid);
      applySelectedPlotUnavailableDates();
      redrawRangePickerIfAny();
      $('#mwb-mbfw-booking-from-time').trigger('change');
    });
  }

  function getSelectedPlotId() {
    return vilaSelectedPlotId;
  }

  function fetchAndApplyPlotAvailability(plotId) {
    var booking = window.vilaAkoyaBooking;
    if (!booking || !booking.vilaCbAvailabilityRestUrl) {
      return;
    }
    var from = new Date();
    var to = new Date(from.getTime());
    to.setFullYear(to.getFullYear() + 2);
    var url =
      booking.vilaCbAvailabilityRestUrl +
      '?from=' + encodeURIComponent(ymdFromDate(from)) +
      '&to=' + encodeURIComponent(ymdFromDate(to));
    if (plotId) {
      url += '&plot_id=' + encodeURIComponent(plotId);
    }
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        window.vilaMwbUnavailableList = Array.isArray(data.fully_booked_dates)
          ? data.fully_booked_dates
          : [];
        redrawRangePickerIfAny();
        scheduleStayRangeAvailabilityCheck();
      })
      .catch(function () {});
  }

  function initPlotDropdown() {
    if (typeof vilaAkoyaBooking === 'undefined' || !vilaAkoyaBooking.useSharedCampsiteInventory) {
      return;
    }
    if (vilaPlotDropdownBound) {
      return;
    }
    var $sel = $('#vila-plot-select');
    if (!$sel.length) {
      return;
    }
    vilaPlotDropdownBound = true;

    $sel.on('change', function () {
      var val = $(this).val();
      vilaSelectedPlotId = val;
      $('#vila-cb-preferred-plot-id').val(val);
      clearAvailabilityWarning();
      fetchAndApplyPlotAvailability(val);
      var stay = getStayYmdFromMwbInputs();
      if (stay && val) {
        setAddToCartBlocked(true);
        runStayRangeAvailabilityCheck();
      }
    });
  }

  function pad2(n) {
    return String(n).padStart(2, '0');
  }

  function ymdFromDate(d) {
    return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
  }

  /** REST uses the same stay as checkout: check-out is exclusive (half-open range). */
  function dmyInputToYmd(val) {
    var d = parseDMY(val);
    return d ? ymdFromDate(d) : '';
  }

  function getStayYmdFromMwbInputs() {
    var cin = dmyInputToYmd($('#mwb-mbfw-booking-from-time').val());
    var cout = dmyInputToYmd($('#mwb-mbfw-booking-to-time').val());
    if (cin && cout && cout > cin) {
      return { check_in: cin, check_out: cout };
    }
    // MWB can transiently clear/rewrite hidden fields during AJAX recalcs.
    // Keep CTA state in sync with the visible range picker selection.
    var rangeEl = document.getElementById('vila-akoya-booking-range');
    if (rangeEl && rangeEl._flatpickr && Array.isArray(rangeEl._flatpickr.selectedDates)) {
      var picked = rangeEl._flatpickr.selectedDates;
      if (picked.length === 2 && picked[0] instanceof Date && picked[1] instanceof Date) {
        var a = ymdFromDate(picked[0]);
        var b = ymdFromDate(picked[1]);
        if (a && b && b > a) {
          return { check_in: a, check_out: b };
        }
      }
    }
    return null;
  }

  function getRestUrlForRangeCheck() {
    if (typeof vilaAkoyaBooking === 'undefined' || !vilaAkoyaBooking.vilaCbAvailabilityRestUrl) {
      return '';
    }
    return vilaAkoyaBooking.vilaCbAvailabilityRestUrl;
  }

  var vilaAvailTimer = null;
  var vilaAvailSeq = 0;
  var vilaAvailAbort = null;
  var vilaRangeUnavailable = false;
  var vilaUserPickedDates = false;

  // ── Smooth live-total updates ────────────────────────────────────────────
  // MWB re-renders its whole bill via admin-ajax on every recalc. Rapid changes
  // (toggling extras, tapping the guest steppers) fire several overlapping
  // requests whose out-of-order responses make rows pop in and out. We (a)
  // debounce the extras-triggered recalc so a burst of clicks becomes one
  // request, and (b) dim the bill while any MWB recalc is in flight so the user
  // sees a single settle instead of intermediate flicker.
  var vilaMwbBillRecalcTimer = null;
  var vilaBillUpdatingSafety = null;

  function getBookingBillEl() {
    return $('.vila-wc-booking .mwb-mbfw-total-area').first();
  }

  function setBookingBillUpdating(on) {
    var $bill = getBookingBillEl();
    if (!$bill.length) {
      return;
    }
    clearTimeout(vilaBillUpdatingSafety);
    if (on) {
      $bill.addClass('vila-bill-updating');
      // Never leave the bill dimmed if an expected ajaxComplete never arrives.
      vilaBillUpdatingSafety = setTimeout(function () {
        getBookingBillEl().removeClass('vila-bill-updating');
      }, 6000);
    } else {
      $bill.removeClass('vila-bill-updating');
    }
  }

  function isMwbTotalsRequest(settings) {
    if (!settings) {
      return false;
    }
    var hay = '';
    if (typeof settings.data === 'string') {
      hay += settings.data;
    }
    if (typeof settings.url === 'string') {
      hay += ' ' + settings.url;
    }
    return /mbfw/i.test(hay);
  }

  function scheduleMwbTotalsRecalc() {
    clearTimeout(vilaMwbBillRecalcTimer);
    // Dim immediately so the click feels responsive even before the debounce
    // fires the actual (slow) request.
    setBookingBillUpdating(true);
    vilaMwbBillRecalcTimer = setTimeout(function () {
      var $from = $('#mwb-mbfw-booking-from-time');
      var $to = $('#mwb-mbfw-booking-to-time');
      if ($from.length && $to.length && $from.val() && $to.val()) {
        $from.trigger('change');
      } else {
        setBookingBillUpdating(false);
      }
    }, 300);
  }

  function ensureAvailabilityBanner() {
    var $el = $('#vila-booking-availability-msg');
    if ($el.length) {
      return $el;
    }
    $el = $(
      '<p class="vila-booking-availability-msg mb-0 bg-red-50 px-4 py-5 text-sm font-bold text-primary mb-8 hidden" role="alert" id="vila-booking-availability-msg"></p>'
    );
    var $anchor = $('.vila-airbnb-booking--dates').first();
    if (!$anchor.length) {
      $anchor = $('.mbfw-date-picker-section__wrapper').first();
    }
    if ($anchor.length) {
      $anchor.after($el);
    } else {
      $('form.cart').prepend($el);
    }
    return $el;
  }

  function setAddToCartBlocked(blocked) {
    var $btn = $(
      'form.cart .single_add_to_cart_button,' +
      ' form.cart button[name="add-to-cart"],' +
      ' form.cart input.single_add_to_cart_button[type="submit"],' +
      ' form.cart input[name="add-to-cart"][type="submit"]'
    );
    if (!$btn.length) {
      return;
    }
    $btn.prop('disabled', !!blocked);
    if (blocked) {
      $btn.attr('disabled', 'disabled');
    } else {
      $btn.removeAttr('disabled');
    }
    $btn.toggleClass('disabled', !!blocked);
    $btn.attr('aria-disabled', blocked ? 'true' : 'false');
  }

  function syncBookingTotalsAndCtaState() {
    // Enable CTA based on actual valid stay dates in MWB inputs.
    // Programmatic updates from flatpickr may not carry originalEvent.
    var hasStay = !!getStayYmdFromMwbInputs();
    $('body').toggleClass('vila-booking-dates-selected', hasStay);
    setAddToCartBlocked(!hasStay || vilaRangeUnavailable);
  }

  function clearAvailabilityWarning() {
    ensureAvailabilityBanner().addClass('hidden').text('');
    vilaRangeUnavailable = false;
    syncBookingTotalsAndCtaState();
    $('#vila-akoya-booking-range').removeClass('vila-date-input-error');
    $('.vila-airbnb-booking--dates').removeClass('vila-date-input-error');
  }

  function showAvailabilityWarning(msg) {
    ensureAvailabilityBanner().removeClass('hidden').text(msg);
    vilaRangeUnavailable = true;
    syncBookingTotalsAndCtaState();
    $('#vila-akoya-booking-range').addClass('vila-date-input-error');
    $('.vila-airbnb-booking--dates').addClass('vila-date-input-error');
  }

  function runStayRangeAvailabilityCheck() {
    var base = getRestUrlForRangeCheck();
    if (!base) {
      clearAvailabilityWarning();
      return;
    }
    var stay = getStayYmdFromMwbInputs();
    if (!stay) {
      clearAvailabilityWarning();
      return;
    }
    vilaAvailSeq++;
    var seq = vilaAvailSeq;
    if (vilaAvailAbort) {
      vilaAvailAbort.abort();
    }
    vilaAvailAbort = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var signal = vilaAvailAbort ? vilaAvailAbort.signal : undefined;
    var q =
      '?from=' +
      encodeURIComponent(stay.check_in) +
      '&to=' +
      encodeURIComponent(stay.check_out) +
      '&check_in=' +
      encodeURIComponent(stay.check_in) +
      '&check_out=' +
      encodeURIComponent(stay.check_out);
    fetch(base + q, { credentials: 'same-origin', signal: signal })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (seq !== vilaAvailSeq) {
          return;
        }
        if (data && data.bookable === false) {
          var msg;
          if (vilaAkoyaBooking.strings && vilaAkoyaBooking.strings.rangeUnavailable) {
            msg = vilaAkoyaBooking.strings.rangeUnavailable;
          } else {
            msg = 'No pitch is available for those dates.';
          }
          showAvailabilityWarning(msg);
        } else {
          clearAvailabilityWarning();
        }
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') {
          return;
        }
        if (seq !== vilaAvailSeq) {
          return;
        }
        clearAvailabilityWarning();
      });
  }

  function scheduleStayRangeAvailabilityCheck() {
    clearTimeout(vilaAvailTimer);
    vilaAvailTimer = setTimeout(runStayRangeAvailabilityCheck, 320);
  }

  /**
   * Desktop: Guests + Your dates side by side; Your setup; then Notes full width.
   */
  function layoutBookingFormSections() {
    var $scopes = $('.vila-wc-booking form.cart');
    if (!$scopes.length) {
      $scopes = $('body.single-product.post-type-product form.cart');
    }
    $scopes.each(function () {
      var $form = $(this);
      if ($form.data('vilaBookingLayoutDone')) {
        return;
      }
      var $datesWrap = $form.find('.mbfw-date-picker-section__wrapper').first();
      var $who = $form.find('.vila-airbnb-booking--who').first();
      var $notes = $form.find('.vila-booking-notes-wrap').first();
      var $setup = $form.find('.vila-airbnb-booking--units').first();

      if (!$datesWrap.length || !$who.length) {
        return;
      }

      if (!$who.closest('.vila-booking-dates-who').length) {
        var $row = $('<div class="vila-booking-dates-who"></div>');
        $datesWrap.before($row);
        $row.append($who);
        $row.append($datesWrap);
      }

      if ($notes.length && $setup.length) {
        $notes.insertAfter($setup);
      }

      $form.data('vilaBookingLayoutDone', true);
    });
  }

  /** @deprecated Use layoutBookingFormSections */
  function layoutDatesAndWhoRow() {
    layoutBookingFormSections();
  }

  function initBookingMapLightbox() {
    var $trigger = $('.va-booking-map-trigger').first();
    if (!$trigger.length || $trigger.data('vaMapLightboxBound')) {
      return;
    }
    $trigger.data('vaMapLightboxBound', true);

    var imageSrc = String($trigger.attr('href') || '');
    if (!imageSrc) {
      return;
    }

    var $lightbox = $(
      '<div class="va-map-lightbox hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-label="Campsite map fullscreen view">' +
        '<button type="button" class="va-map-lightbox__backdrop" aria-label="Close campsite map popup"></button>' +
        '<div class="va-map-lightbox__content">' +
          '<button type="button" class="va-map-lightbox__close" aria-label="Close popup">×</button>' +
          '<img class="va-map-lightbox__image" alt="Map of campsite plots fullscreen view" />' +
          '<div class="va-map-lightbox__hotspots" aria-hidden="false"></div>' +
        '</div>' +
      '</div>'
    );

    $lightbox.find('.va-map-lightbox__image').attr('src', imageSrc);
    $('body').append($lightbox);

    function closeLightbox() {
      $lightbox.addClass('hidden').attr('aria-hidden', 'true');
      $('body').removeClass('va-map-lightbox-open');
    }

    function openLightbox() {
      $lightbox.removeClass('hidden').attr('aria-hidden', 'false');
      $('body').addClass('va-map-lightbox-open');
    }

    $trigger.on('click', function (e) {
      e.preventDefault();
      openLightbox();
    });

    $lightbox.on('click', '.va-map-lightbox__close, .va-map-lightbox__backdrop', function (e) {
      e.preventDefault();
      closeLightbox();
    });

    $(document).on('keydown.vilaBookingMapLightbox', function (e) {
      if (e.key === 'Escape') {
        closeLightbox();
      }
    });

    var hotspots = [];
    var $hotspotLayer = $lightbox.find('.va-map-lightbox__hotspots');
    if (hotspots.length) {
      hotspots.forEach(function (spot, idx) {
        var id = String(spot && spot.id ? spot.id : '');
        if (!id) {
          return;
        }
        var label = String(spot && spot.label ? spot.label : ('Plot ' + String(idx + 1)));
        var x = parseFloat(spot && spot.x !== undefined ? spot.x : '50');
        var y = parseFloat(spot && spot.y !== undefined ? spot.y : '50');
        if (isNaN(x)) {
          x = 50;
        }
        if (isNaN(y)) {
          y = 50;
        }
        var shortLabel = label.replace(/^Plot\s+/i, '');
        var $btn = $('<button type="button" class="va-map-plot-hotspot"></button>');
        $btn.attr('data-plot-id', id);
        $btn.attr('aria-label', 'Select ' + label);
        $btn.css({ left: x + '%', top: y + '%' });
        $btn.html('<span class="va-map-plot-hotspot__dot"></span><span class="va-map-plot-hotspot__text">' + shortLabel + '</span>');
        $hotspotLayer.append($btn);
      });
    }
  }

  function bootstrapRangePicker() {
    // Mount custom "Stay dates" UI immediately so MWB's native from/to fields
    // don't flash before async availability data returns.
    initRangePicker();

    var booking = window.vilaAkoyaBooking;
    if (
      booking &&
      booking.useSharedCampsiteInventory &&
      booking.vilaCbAvailabilityRestUrl
    ) {
      var from = new Date();
      var to = new Date(from.getTime());
      to.setFullYear(to.getFullYear() + 2);
      var url =
        booking.vilaCbAvailabilityRestUrl +
        '?from=' +
        encodeURIComponent(ymdFromDate(from)) +
        '&to=' +
        encodeURIComponent(ymdFromDate(to));
      fetch(url, { credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          window.vilaMwbUnavailableList = Array.isArray(data.fully_booked_dates)
            ? data.fully_booked_dates
            : [];
          redrawRangePickerIfAny();
        })
        .catch(function () {
          redrawRangePickerIfAny();
        });
    }
  }

  function formatDMY(d) {
    return pad2(d.getDate()) + '-' + pad2(d.getMonth() + 1) + '-' + d.getFullYear();
  }

  function parseDMY(s) {
    if (!s || typeof s !== 'string') {
      return null;
    }
    var raw = String(s).trim();
    if (!raw) {
      return null;
    }
    var dateOnly = raw.split(' ')[0];
    var p = dateOnly.split(/[-./]/);
    if (p.length !== 3) {
      return null;
    }
    var a = parseInt(p[0], 10);
    var b = parseInt(p[1], 10);
    var c = parseInt(p[2], 10);
    if (isNaN(a) || isNaN(b) || isNaN(c)) {
      return null;
    }
    var day;
    var month;
    var year;
    // Support both D-M-Y (theme range picker) and Y-M-D (some MWB outputs).
    if (a > 31) {
      year = a;
      month = b - 1;
      day = c;
    } else {
      day = a;
      month = b - 1;
      year = c;
    }
    if (isNaN(day) || isNaN(month) || isNaN(year)) {
      return null;
    }
    return new Date(year, month, day);
  }

  function guestSummaryString(a, c, i, p) {
    var people = a + c + i;
    var parts = [];
    var gs = typeof vilaAkoyaBooking !== 'undefined' && vilaAkoyaBooking.guestStrings ? vilaAkoyaBooking.guestStrings : null;
    if (people === 1) {
      parts.push(gs && gs.oneGuest ? gs.oneGuest : '1 guest');
    } else {
      var tpl = gs && gs.nGuests ? gs.nGuests : '%d guests';
      parts.push(tpl.replace('%d', String(people)));
    }
    if (p > 0) {
      if (p === 1) {
        parts.push(gs && gs.onePet ? gs.onePet : '1 pet');
      } else {
        var petTpl = gs && gs.nPets ? gs.nPets : '%d pets';
        parts.push(petTpl.replace('%d', String(p)));
      }
    }
    return parts.join(' · ');
  }

  function getGuestState($root) {
    return {
      adults: parseInt($root.find('#mwb_mbfw_people_number').val(), 10) || 0,
      children: parseInt($root.find('#vila_guest_children').val(), 10) || 0,
      infants: parseInt($root.find('#vila_guest_infants').val(), 10) || 0,
      pets: parseInt($root.find('#vila_guest_pets').val(), 10) || 0,
    };
  }

  function setGuestState($root, s) {
    var minA = parseInt($root.attr('data-vila-min-adults'), 10);
    if (isNaN(minA)) {
      minA = 1;
    }
    var maxP = parseInt($root.attr('data-vila-max-people'), 10);
    if (isNaN(maxP)) {
      maxP = 8;
    }
    var maxPet = parseInt($root.attr('data-vila-max-pets'), 10);
    if (isNaN(maxPet)) {
      maxPet = 5;
    }
    s.adults = Math.max(minA, s.adults);
    s.children = Math.max(0, s.children);
    s.infants = Math.max(0, s.infants);
    s.pets = Math.max(0, Math.min(maxPet, s.pets));
    if (s.adults + s.children + s.infants > maxP) {
      var over = s.adults + s.children + s.infants - maxP;
      while (over > 0 && s.children > 0) {
        s.children--;
        over--;
      }
      while (over > 0 && s.infants > 0) {
        s.infants--;
        over--;
      }
      while (over > 0 && s.adults > minA) {
        s.adults--;
        over--;
      }
    }
    $root.find('#mwb_mbfw_people_number').val(String(s.adults)).trigger('change');
    $root.find('#vila_guest_children').val(String(s.children));
    $root.find('#vila_guest_infants').val(String(s.infants));
    $root.find('#vila_guest_pets').val(String(s.pets));
    $root.find('.vila-guest-picker__summary').text(guestSummaryString(s.adults, s.children, s.infants, s.pets));
    $root.find('[data-vila-value-for]').each(function () {
      var slug = $(this).attr('data-vila-value-for');
      if (slug && s[slug] !== undefined) {
        $(this).text(String(s[slug]));
      }
    });
    updateGuestStepperUi($root, s, minA, maxP, maxPet);
    updateVehicleHint();
  }

  function updateGuestStepperUi($root, s, minA, maxP, maxPet) {
    var totalPeople = s.adults + s.children + s.infants;
    $root.find('.vila-guest-picker__btn').each(function () {
      var $btn = $(this);
      var slug = $btn.attr('data-vila-slug');
      var delta = parseInt($btn.attr('data-vila-delta'), 10);
      if (!slug || isNaN(delta)) {
        return;
      }
      var cur = s[slug];
      var next = cur + delta;
      var dis = false;
      if (slug === 'adults') {
        if (delta < 0 && next < minA) {
          dis = true;
        }
        if (delta > 0 && totalPeople >= maxP) {
          dis = true;
        }
        if (delta > 0 && next > maxP - s.children - s.infants) {
          dis = true;
        }
      } else if (slug === 'children' || slug === 'infants') {
        if (delta < 0 && next < 0) {
          dis = true;
        }
        if (delta > 0 && totalPeople >= maxP) {
          dis = true;
        }
      } else if (slug === 'pets') {
        if (delta < 0 && next < 0) {
          dis = true;
        }
        if (delta > 0 && next > maxPet) {
          dis = true;
        }
      }
      $btn.prop('disabled', dis);
    });
  }

  function initGuestPicker() {
    var $root = $('.vila-guest-picker');
    if (!$root.length) {
      return;
    }

    $root.each(function () {
      var $r = $(this);
      var minA = parseInt($r.attr('data-vila-min-adults'), 10);
      if (isNaN(minA)) {
        minA = 1;
      }
      var maxP = parseInt($r.attr('data-vila-max-people'), 10);
      if (isNaN(maxP)) {
        maxP = 8;
      }
      var maxPet = parseInt($r.attr('data-vila-max-pets'), 10);
      if (isNaN(maxPet)) {
        maxPet = 5;
      }
      var defA = parseInt($r.attr('data-vila-default-adults'), 10);
      if (isNaN(defA)) {
        defA = minA;
      }
      var s = getGuestState($r);
      if (!s.adults) {
        s.adults = defA;
      }
      setGuestState($r, s);

      $r.find('.vila-guest-picker__trigger').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $panel = $r.find('.vila-guest-picker__panel');
        var open = $panel.prop('hidden') === false;
        $panel.prop('hidden', open);
        $(this).attr('aria-expanded', open ? 'false' : 'true');
      });

      $r.find('.vila-guest-picker__btn').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if ($(this).prop('disabled')) {
          return;
        }
        var slug = $(this).attr('data-vila-slug');
        var delta = parseInt($(this).attr('data-vila-delta'), 10);
        if (!slug || isNaN(delta)) {
          return;
        }
        var st = getGuestState($r);
        if (st[slug] === undefined) {
          return;
        }
        st[slug] += delta;
        setGuestState($r, st);
      });

      $r.on('click', function (e) {
        e.stopPropagation();
      });
    });

    if (!vilaGuestPickerDocBound) {
      vilaGuestPickerDocBound = true;
      $(document).on('click.vilaGuestPicker', function (e) {
        if (!$(e.target).closest('.vila-guest-picker').length) {
          $('.vila-guest-picker__panel').prop('hidden', true);
          $('.vila-guest-picker__trigger').attr('aria-expanded', 'false');
        }
      });

      $(document).on('keydown.vilaGuestPicker', function (e) {
        if (e.key === 'Escape') {
          $('.vila-guest-picker__panel').prop('hidden', true);
          $('.vila-guest-picker__trigger').attr('aria-expanded', 'false');
        }
      });
    }
  }

  function updateVehicleHint() {
    var $hint = $('.vila-vehicle-hint');
    if (!$hint.length || typeof vilaAkoyaBooking === 'undefined' || !vilaAkoyaBooking.strings) {
      return;
    }
    if (!$('input[name="vila_vehicle_types[]"]').length) {
      return;
    }
    var parts = [];
    if (vilaAkoyaBooking.strings.guestCountAffectsRate && !$('.vila-guest-picker').length) {
      parts.push(vilaAkoyaBooking.strings.guestCountAffectsRate);
    }
    $hint.text(parts.join(' '));
  }


  function normaliseBookingBillLayout() {
    // The MWB DOM already nests line-item wrappers above the total row inside
    // .mbfw-total-listing-single-page__wrapper-parent — no manual moves needed.
  }

  function initRangePicker() {
    if (typeof mwb_mbfw_public_obj === 'undefined' || typeof flatpickr === 'undefined') {
      return;
    }
    applySelectedPlotUnavailableDates();
    if (mwb_mbfw_public_obj.wps_cal_type !== 'dual_cal') {
      initPlotSelector();
      scheduleStayRangeAvailabilityCheck();
      layoutBookingFormSections();
      return;
    }
    if (mwb_mbfw_public_obj.booking_unit === 'hour') {
      return;
    }
    if (mwb_mbfw_public_obj.mwb_mbfw_show_date_with_time === 'yes') {
      return;
    }

    var fromEl = document.getElementById('mwb-mbfw-booking-from-time');
    var toEl = document.getElementById('mwb-mbfw-booking-to-time');
    if (!fromEl || !toEl) {
      return;
    }

    var $wrap = $(fromEl).closest('.mbfw-date-picker-section__wrapper');
    if (!$wrap.length) {
      return;
    }

    if (fromEl._flatpickr) {
      fromEl._flatpickr.destroy();
    }
    if (toEl._flatpickr) {
      toEl._flatpickr.destroy();
    }

    $wrap.addClass('vila-booking-range-wrap-enhanced');

    if (document.getElementById('vila-akoya-booking-range')) {
      syncRangeFromInputs();
      updateVehicleHint();
      initPlotSelector();
      scheduleStayRangeAvailabilityCheck();
      layoutBookingFormSections();
      return;
    }

    var unavailable = window.vilaMwbUnavailableList || mwb_mbfw_public_obj.single_unavailable_dates || [];
    var lang = mwb_mbfw_public_obj.lang || 'default';
    var localeBase = flatpickr.l10ns[lang] || flatpickr.l10ns.default || {};
    var firstDow = parseInt(mwb_mbfw_public_obj.firstDayOf_Week, 10);
    if (isNaN(firstDow)) {
      firstDow = 1;
    }
    var locale = Object.assign({}, localeBase, { firstDayOfWeek: firstDow });

    var $ui = $(
      '<div class="vila-airbnb-booking vila-airbnb-booking--dates vila-booking-range-ui mb-6">' +
        '<p class="vila-airbnb-booking__legend mb-2">' +
        (vilaAkoyaBooking && vilaAkoyaBooking.strings ? vilaAkoyaBooking.strings.stayDates : 'Stay dates') +
        '</p>' +
        '<input type="text" id="vila-akoya-booking-range" class="vila-airbnb-booking__date-input w-full min-w-0 font-body text-left cursor-pointer" readonly tabindex="0" />' +
        '</div>'
    );
    $wrap.prepend($ui);

    var ph =
      vilaAkoyaBooking && vilaAkoyaBooking.strings
        ? vilaAkoyaBooking.strings.placeholder
        : 'Check-in — Check-out';
    $('#vila-akoya-booking-range').attr('placeholder', ph);

    var mq = window.matchMedia('(min-width: 640px)');
    var showMo = mq.matches ? 2 : 1;

    flatpickr('#vila-akoya-booking-range', {
      mode: 'range',
      showMonths: showMo,
      dateFormat: 'd-m-Y',
      minDate: mwb_mbfw_public_obj.today_date || 'today',
      locale: locale,
      disable: [
        function (date) {
          var list = window.vilaMwbUnavailableList || unavailable || [];
          var y =
            date.getFullYear() +
            '-' +
            pad2(date.getMonth() + 1) +
            '-' +
            pad2(date.getDate());
          return list.indexOf(y) !== -1;
        },
      ],
      onChange: function (selectedDates) {
        if (selectedDates.length === 2) {
          vilaUserPickedDates = true;
          $(fromEl)
            .val(formatDMY(selectedDates[0]))
            .trigger('change');
          $(toEl).val(formatDMY(selectedDates[1])).trigger('change');
        }
        if (selectedDates.length === 0) {
          vilaUserPickedDates = false;
          $(fromEl).val('').trigger('change');
          $(toEl).val('').trigger('change');
        }
        syncBookingTotalsAndCtaState();
        updateVehicleHint();
        scheduleStayRangeAvailabilityCheck();
      },
      onClose: function () {
        updateVehicleHint();
        scheduleStayRangeAvailabilityCheck();
      },
    });

    syncRangeFromInputs();
    updateVehicleHint();
    initPlotSelector();
    scheduleStayRangeAvailabilityCheck();
    layoutBookingFormSections();
  }

  function syncRangeFromInputs() {
    var fp = document.getElementById('vila-akoya-booking-range');
    if (!fp || !fp._flatpickr) {
      return;
    }
    var f = $('#mwb-mbfw-booking-from-time').val();
    var t = $('#mwb-mbfw-booking-to-time').val();
    var da = parseDMY(f);
    var db = parseDMY(t);
    if (da && db) {
      fp._flatpickr.setDate([da, db], true);
    }
    scheduleStayRangeAvailabilityCheck();
  }

  function syncInputsFromVisibleRange() {
    var rangeEl = document.getElementById('vila-akoya-booking-range');
    var fromEl = document.getElementById('mwb-mbfw-booking-from-time');
    var toEl = document.getElementById('mwb-mbfw-booking-to-time');
    if (!rangeEl || !rangeEl._flatpickr || !fromEl || !toEl) {
      return false;
    }
    var picked = rangeEl._flatpickr.selectedDates || [];
    if (!Array.isArray(picked) || picked.length !== 2 || !(picked[0] instanceof Date) || !(picked[1] instanceof Date)) {
      return false;
    }
    var fromVal = formatDMY(picked[0]);
    var toVal = formatDMY(picked[1]);
    if (!fromVal || !toVal) {
      return false;
    }
    $(fromEl).val(fromVal).trigger('change');
    $(toEl).val(toVal).trigger('change');
    return true;
  }

  function initBookingReserveModeTabs() {
    var $card = $('.va-booking-reserve-card').first();
    if (!$card.length || !$card.find('[data-va-booking-tabs]').length) {
      return;
    }

    function activate(mode) {
      $card.find('[data-va-booking-tab]').each(function () {
        var sel = $(this).attr('data-va-booking-tab') === mode;
        $(this).toggleClass('is-active', sel);
        $(this).attr('aria-selected', sel ? 'true' : 'false');
        $(this).attr('tabindex', sel ? '0' : '-1');
      });
      $card.find('[data-va-booking-panel]').each(function () {
        var show = $(this).attr('data-va-booking-panel') === mode;
        $(this).prop('hidden', !show);
        $(this).attr('aria-hidden', show ? 'false' : 'true');
      });
    }

    $card.on('click', '[data-va-booking-tab]', function (e) {
      e.preventDefault();
      var mode = $(this).attr('data-va-booking-tab');
      if (!mode) {
        return;
      }
      activate(mode);
      if (window.history && typeof window.history.replaceState === 'function') {
        var path = window.location.pathname + window.location.search;
        var hash = mode === 'village' ? '#village' : '';
        window.history.replaceState(null, '', path + hash);
      }
    });

    $(window).on('hashchange.vilaBookingReserveTabs', function () {
      if (/^#village$/i.test(String(window.location.hash || ''))) {
        activate('village');
      } else {
        activate('campsite');
      }
    });

    if (/^#village$/i.test(String(window.location.hash || ''))) {
      activate('village');
    } else {
      activate('campsite');
    }
  }

  $(function () {
    setTimeout(bootstrapRangePicker, 120);
    initGuestPicker();
    layoutBookingFormSections();
    initBookingMapLightbox();
    initBookingReserveModeTabs();
    setTimeout(layoutBookingFormSections, 400);
    setTimeout(layoutBookingFormSections, 1000);

    $(document).on(
      'change input',
      'input[name="vila_vehicle_types[]"], #mwb-mbfw-booking-from-time, #mwb-mbfw-booking-to-time, #mwb_mbfw_people_number',
      function () {
        updateVehicleHint();
      }
    );

    // MWB only recalculates its live-total AJAX when its own date/people fields
    // change. The "Your setup" extras (Caravan, Campervan, electricity, …) are
    // custom fields it doesn't watch, so toggling one never refreshed the summary
    // and the selected extra was missing from the first-screen breakdown. Nudge
    // MWB's date field so it re-runs totals with the extras included in the POST.
    // Debounced so a quick burst of toggles becomes a single recalc.
    $(document).on('change', 'input[name="vila_vehicle_types[]"]', function () {
      scheduleMwbTotalsRecalc();
    });

    $(document).on(
      'change input',
      '#mwb-mbfw-booking-from-time, #mwb-mbfw-booking-to-time',
      function (e) {
        if (e && e.originalEvent) {
          vilaUserPickedDates = true;
        }
        if (!$('#mwb-mbfw-booking-from-time').val() || !$('#mwb-mbfw-booking-to-time').val()) {
          vilaUserPickedDates = false;
        }
        syncBookingTotalsAndCtaState();
        scheduleStayRangeAvailabilityCheck();
      }
    );

    // MWB can clear hidden date inputs during async recalculations.
    // Ensure backend receives dates that are visibly selected in our range picker.
    $(document).on('click', 'form.cart .single_add_to_cart_button, form.cart button[name="add-to-cart"], form.cart input[name="add-to-cart"]', function () {
      syncInputsFromVisibleRange();
    });
    $(document).on('submit', 'form.cart', function () {
      syncInputsFromVisibleRange();
    });

    setTimeout(scheduleStayRangeAvailabilityCheck, 600);
    setTimeout(function () {
      normaliseBookingBillLayout();
      syncBookingTotalsAndCtaState();
    }, 150);

    // Dim the bill while any MWB recalc is in flight (from extras, guest
    // steppers or date changes) so the summary settles once instead of
    // flickering rows in and out as overlapping responses land.
    $(document).ajaxSend(function (e, xhr, settings) {
      if (isMwbTotalsRequest(settings)) {
        setBookingBillUpdating(true);
      }
    });

    $(document).ajaxComplete(function (e, xhr, settings) {
      normaliseBookingBillLayout();
      syncBookingTotalsAndCtaState();
      setTimeout(syncBookingTotalsAndCtaState, 100);
      if (isMwbTotalsRequest(settings)) {
        setBookingBillUpdating(false);
      }
    });
  });
})(jQuery);
