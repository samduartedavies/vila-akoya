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
    if (vilaAkoyaBooking.stayMode && vilaAkoyaBooking.plotUnavailableYmd) {
      var sid = String(vilaAkoyaBooking.defaultPlotProductId || '');
      var stayDates = vilaAkoyaBooking.plotUnavailableYmd[sid] || [];
      window.vilaMwbUnavailableList = stayDates;
      if (typeof mwb_mbfw_public_obj !== 'undefined') {
        mwb_mbfw_public_obj.single_unavailable_dates = stayDates;
      }
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

  // ── Instant client-side bill ─────────────────────────────────────────────
  // The live total used to depend on MWB's admin-ajax, which re-rendered the
  // whole bill on every field change with no request sequencing. Overlapping,
  // out-of-order responses meant a stale pass (e.g. computed before both dates
  // were posted → "× 1 night") could land last and stick. We now compute and
  // render the breakdown ourselves, synchronously, from the current form state.
  // It's instant, race-free and always correct. MWB's own (now-hidden) AJAX
  // still runs to keep its internal state happy, and the server remains
  // authoritative at checkout — the numbers here mirror those formulas exactly.

  function vilaBookingData() {
    return window.vilaAkoyaBooking || null;
  }

  function vilaPricingReady() {
    var d = vilaBookingData();
    return !!(d && d.pricing && d.currency && d.billStrings);
  }

  // sprintf for PHP positional tokens (%1$d, %2$s, …), stringifying each arg.
  function vilaSprintf(fmt) {
    var args = Array.prototype.slice.call(arguments, 1);
    return String(fmt).replace(/%(\d+)\$[ds]/g, function (m, i) {
      var v = args[parseInt(i, 10) - 1];
      return v === undefined || v === null ? '' : String(v);
    });
  }

  function vilaEscapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // Group digits + apply decimal/thousand separators.
  // Whole amounts omit decimals (€5); fractional keep two digits (€7,50).
  function vilaFormatNumber(amount) {
    var c = vilaBookingData().currency;
    var neg = amount < 0;
    amount = Math.abs(Number(amount) || 0);
    var rounded = Math.round(amount * 100) / 100;
    var isWhole = Math.abs(rounded - Math.round(rounded)) < 0.00001;
    var intPart;
    var out;
    if (isWhole) {
      intPart = String(Math.round(rounded));
      intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, c.thousandSep);
      out = intPart;
    } else {
      var fixed = rounded.toFixed(2);
      var parts = fixed.split('.');
      intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, c.thousandSep);
      out = intPart + c.decimalSep + parts[1];
    }
    return (neg ? '-' : '') + out;
  }

  // Plain price for breakdown titles (matches vila_akoya_format_price_plain()).
  // Always places the currency symbol before the number.
  function vilaFormatPricePlain(amount) {
    var c = vilaBookingData().currency;
    return String(c.symbol || '€') + vilaFormatNumber(amount);
  }

  // HTML price for the value column — symbol before amount, smart decimals.
  function vilaFormatPriceHtml(amount) {
    var c = vilaBookingData().currency;
    var n = Number(amount) || 0;
    var neg = n < 0;
    var abs = Math.abs(n);
    var sym = '<span class="woocommerce-Price-currencySymbol">' + vilaEscapeHtml(c.symbol || '€') + '</span>';
    return '<span class="woocommerce-Price-amount amount"><bdi>' + (neg ? '−' : '') + sym + vilaFormatNumber(abs) + '</bdi></span>';
  }

  // Whole nights for the visible stay. The flatpickr range is the source of
  // truth (MWB can transiently clear its hidden inputs during its own AJAX).
  function getStayDatePair() {
    var el = document.getElementById('vila-akoya-booking-range');
    if (el && el._flatpickr && Array.isArray(el._flatpickr.selectedDates) && el._flatpickr.selectedDates.length === 2) {
      var a = el._flatpickr.selectedDates[0];
      var b = el._flatpickr.selectedDates[1];
      if (a instanceof Date && b instanceof Date) {
        return [a, b];
      }
    }
    var f = parseDMY($('#mwb-mbfw-booking-from-time').val());
    var t = parseDMY($('#mwb-mbfw-booking-to-time').val());
    if (f && t) {
      return [f, t];
    }
    return null;
  }

  function getStayNights() {
    var pair = getStayDatePair();
    if (!pair) {
      return 0;
    }
    return Math.max(1, Math.round((pair[1] - pair[0]) / 86400000));
  }

  // Count Mon–Fri nights (check-in inclusive, check-out exclusive).
  function getWeekdayNights() {
    var pair = getStayDatePair();
    if (!pair) {
      return 0;
    }
    var cur = new Date(pair[0].getFullYear(), pair[0].getMonth(), pair[0].getDate());
    var end = new Date(pair[1].getFullYear(), pair[1].getMonth(), pair[1].getDate());
    var count = 0;
    while (cur < end) {
      var day = cur.getDay(); // 0 Sun … 6 Sat
      if (day >= 1 && day <= 5) {
        count += 1;
      }
      cur.setDate(cur.getDate() + 1);
    }
    return count;
  }

  function ensureLiveBillContainer() {
    var $existing = $('.vila-live-bill');
    if ($existing.length) {
      return $existing.first();
    }
    var $c = $('<div class="vila-live-bill" aria-live="polite"></div>');
    var $mwb = $('.mwb-mbfw-total-area').first();
    if ($mwb.length) {
      $mwb.after($c);
    } else {
      var $btn = $(
        'form.cart .single_add_to_cart_button, form.cart button[name="add-to-cart"], form.cart input[name="add-to-cart"]'
      ).first();
      if ($btn.length) {
        $btn.before($c);
      } else {
        $('form.cart').append($c);
      }
    }
    return $c;
  }

  function liveBillRow(title, value, extraClass) {
    var wrap = 'mbfw-total-listing-single-page__wrapper';
    if (extraClass) {
      wrap += ' ' + extraClass;
    }
    return (
      '<div class="' + wrap + '">' +
      '<div class="mbfw-total-listing-single-page">' + vilaEscapeHtml(title) + '</div>' +
      '<div class="mbfw-total-listing-single-page">' + vilaFormatPriceHtml(value) + '</div>' +
      '</div>'
    );
  }

  function billableWeekly(stayNights) {
    var stay = Math.max(0, parseInt(stayNights, 10) || 0);
    return stay - Math.floor(stay / 7);
  }

  function billableMonthly(stayNights) {
    var stay = Math.max(0, parseInt(stayNights, 10) || 0);
    var blocks = Math.floor(stay / 30);
    var remainder = stay % 30;
    return blocks * 20 + remainder;
  }

  function billableNightsForStay(stayNights) {
    var stay = Math.max(0, parseInt(stayNights, 10) || 0);
    if (stay < 1) {
      return 0;
    }
    return Math.min(billableWeekly(stay), billableMonthly(stay));
  }

  function noElectricityFromForm() {
    var $included = $('#vila_campsite_electricity_included');
    if ($included.length) {
      return !$included.is(':checked');
    }
    var $legacy = $('#vila_campsite_no_electricity');
    if ($legacy.length && $legacy.attr('type') === 'checkbox') {
      return $legacy.is(':checked');
    }
    return $legacy.length && String($legacy.val() || '') === '1';
  }

  function syncCampsiteElectricityFields() {
    var $included = $('#vila_campsite_electricity_included');
    var $hidden = $('#vila_campsite_no_electricity');
    if (!$included.length || !$hidden.length || $hidden.attr('type') === 'checkbox') {
      return;
    }
    $hidden.val($included.is(':checked') ? '' : '1');
  }

  function campsiteTierCard(P, solo, noElec) {
    var t = (P && P.campsiteTiers) || {};
    if (solo) {
      return noElec
        ? t.soloTentNoElectricity || { night: 12, week: 72, fourWeeks: 270 }
        : t.soloTent || { night: 15, week: 90, fourWeeks: 330 };
    }
    return noElec
      ? t.standardNoElectricity || { night: 24, week: 147, fourWeeks: 495 }
      : t.standard || { night: 27, week: 165, fourWeeks: 555 };
  }

  function isSoloTentFromForm() {
    var adults = Math.max(1, parseInt($('#mwb_mbfw_people_number').val(), 10) || 1);
    var children = Math.max(0, parseInt($('#vila_guest_children').val(), 10) || 0);
    var infants = Math.max(0, parseInt($('#vila_guest_infants').val(), 10) || 0);
    return adults === 1 && children === 0 && infants === 0;
  }

  function campsiteTierTotal(nights, P, solo, noElec) {
    var r = campsiteTierCard(P, solo, noElec);
    var wn = (P.campsiteTiers && P.campsiteTiers.weekNights) || 7;
    var fn = (P.campsiteTiers && P.campsiteTiers.fourWeekNights) || 28;
    var stay = Math.max(0, parseInt(nights, 10) || 0);
    var four = Math.floor(stay / fn);
    var rem = stay % fn;
    var week = Math.floor(rem / wn);
    var single = rem % wn;
    return four * (parseFloat(r.fourWeeks) || 0) + week * (parseFloat(r.week) || 0) + single * (parseFloat(r.night) || 0);
  }

  function campsiteTierUpsellTarget(stay) {
    if (stay < 7) {
      return 7;
    }
    if (stay < 28) {
      return 28;
    }
    var blocks = Math.floor(stay / 28);
    var rem = stay % 28;
    return rem > 0 ? (blocks + 1) * 28 : stay + 28;
  }

  function vilaCampsiteStayDiscountInfo(stayNights, P) {
    P = P || {};
    var solo = isSoloTentFromForm();
    var noElec = noElectricityFromForm();
    var stay = Math.max(0, parseInt(stayNights, 10) || 0);
    var target = campsiteTierUpsellTarget(stay);
    var moreNights = Math.max(0, target - stay);
    var next = null;
    if (moreNights > 0 && target > stay) {
      next = {
        kind: target === 7 ? 'week' : 'four_weeks',
        stay: target,
        total: campsiteTierTotal(target, P, solo, noElec),
      };
    }
    return {
      stay: stay,
      solo_tent: solo,
      no_electricity: noElec,
      total: campsiteTierTotal(stay, P, solo, noElec),
      next: next,
      moreNights: moreNights,
    };
  }

  function campsiteTierBreakdownRows(nights, P, solo, noElec, S) {
    var r = campsiteTierCard(P, solo, noElec);
    var wn = (P.campsiteTiers && P.campsiteTiers.weekNights) || 7;
    var fn = (P.campsiteTiers && P.campsiteTiers.fourWeekNights) || 28;
    var stay = Math.max(0, parseInt(nights, 10) || 0);
    var four = Math.floor(stay / fn);
    var rem = stay % fn;
    var week = Math.floor(rem / wn);
    var single = rem % wn;
    var prefix = solo
      ? noElec
        ? S.soloNoElectricity || 'Solo (no pitch electricity)'
        : S.soloTentStay || 'Solo traveller'
      : noElec
        ? S.standardNoElectricity || 'Standard (no pitch electricity)'
        : S.standardCamping || 'Standard camping';
    var rows = [];
    if (four > 0) {
      var fourVal = four * (parseFloat(r.fourWeeks) || 0);
      rows.push({
        title: prefix + ' — ' + vilaSprintf(S.tierFourWeeks || '%1$d × 4 weeks — %2$s', four, vilaFormatPricePlain(fourVal / four)),
        value: fourVal,
      });
    }
    if (week > 0) {
      var weekVal = week * (parseFloat(r.week) || 0);
      rows.push({
        title: prefix + ' — ' + vilaSprintf(S.tierWeek || '%1$d × 1 week — %2$s', week, vilaFormatPricePlain(weekVal / week)),
        value: weekVal,
      });
    }
    if (single > 0) {
      var nightRate = parseFloat(r.night) || 0;
      rows.push({
        title: prefix + ' — ' + vilaSprintf(S.tierNights || '%1$d × %2$s/night', single, vilaFormatPricePlain(nightRate)),
        value: single * nightRate,
      });
    }
    return rows;
  }

  function upsellTargetStay(stayNights) {
    var stay = Math.max(0, parseInt(stayNights, 10) || 0);
    if (stay < 7) {
      return 7;
    }
    if (stay < 30) {
      return 30;
    }
    return (Math.floor(stay / 30) + 1) * 30;
  }

  function vilaStayDiscountInfo(stayNights) {
    var stay = Math.max(0, parseInt(stayNights, 10) || 0);
    var billable = billableNightsForStay(stay);
    var free = Math.max(0, stay - billable);
    var applied =
      free > 0
        ? {
            stay: stay,
            pay: billable,
          }
        : null;
    var target = upsellTargetStay(stay);
    var targetBillable = billableNightsForStay(target);
    var targetFree = Math.max(0, target - targetBillable);
    var saveNights = Math.max(0, targetFree - free);
    var next = null;
    var moreNights = 0;
    if (saveNights > 0 && target > stay) {
      next = {
        stay: target,
        pay: targetBillable,
        saveNights: saveNights,
      };
      moreNights = target - stay;
    }
    return {
      stay: stay,
      billable: billable,
      free: free,
      applied: applied,
      next: next,
      moreNights: moreNights,
    };
  }

  function stayDiscountUpsellMessage(disc, nightlyAccom, S) {
    if (!disc || !disc.next || disc.moreNights < 1) {
      return '';
    }
    S = S || {};
    var moreWord = disc.moreNights === 1 ? S.nightSingular || 'night' : S.nightPlural || 'nights';
    if (disc.next.kind === 'week' || disc.next.kind === 'four_weeks') {
      var tierPrice = vilaFormatPricePlain(disc.next.total || 0);
      if (disc.next.kind === 'week') {
        return vilaSprintf(
          S.upsellWeek || 'Add %1$d more %2$s to reach 7 nights — %3$s for the week.',
          disc.moreNights,
          moreWord,
          tierPrice
        );
      }
      var targetStay = disc.next.stay || 28;
      var targetWeeks = Math.round(targetStay / 7);
      return vilaSprintf(
        S.upsellFourWeeks || 'Add %1$d more %2$s to reach %3$d nights (%4$d weeks) — %5$s.',
        disc.moreNights,
        moreWord,
        targetStay,
        targetWeeks,
        tierPrice
      );
    }
    if (nightlyAccom <= 0) {
      return '';
    }
    var upsellFmt =
      S.upsell || 'Only %1$d more %2$s and you unlock stay %3$d, pay %4$d (%5$d extra %6$s free).';
    var saveNights = disc.next.saveNights || Math.max(0, disc.next.stay - disc.next.pay);
    var freeWord = saveNights === 1 ? S.nightSingular || 'night' : S.nightPlural || 'nights';
    return vilaSprintf(upsellFmt, disc.moreNights, moreWord, disc.next.stay, disc.next.pay, saveNights, freeWord);
  }

  function ensureStayDiscountUpsellRow() {
    var $row = $('.vila-stay-discount-upsell-row').first();
    if ($row.length) {
      return $row;
    }
    var $anchor = $('.vila-booking-dates-who').first();
    if (!$anchor.length) {
      $anchor = $('.mbfw-date-picker-section__wrapper').first();
    }
    if (!$anchor.length) {
      return $();
    }
    $row = $('<div class="vila-stay-discount-upsell-row" role="status" hidden></div>');
    $anchor.after($row);
    return $row;
  }

  function updateStayDiscountUpsellRow(disc, nightlyAccom, S) {
    var $row = ensureStayDiscountUpsellRow();
    if (!$row.length) {
      return;
    }
    var message = stayDiscountUpsellMessage(disc, nightlyAccom, S);
    if (message) {
      $row.text(message).removeAttr('hidden');
    } else {
      $row.attr('hidden', 'hidden').empty();
    }
  }

  function vilaMealBundleLabel(slugs, S) {
    if (!slugs || !slugs.length) {
      return '';
    }
    var hasBreakfast = slugs.indexOf('breakfast') !== -1;
    var hasDinner = slugs.indexOf('dinner') !== -1;
    if (hasBreakfast && hasDinner) {
      return S.mealBundleBoth || 'Campsite stay with breakfast & weekday dinner included';
    }
    if (hasBreakfast) {
      return S.mealBundleBreakfast || 'Campsite stay with breakfast included';
    }
    if (hasDinner) {
      return S.mealBundleDinner || 'Campsite stay with weekday dinner included';
    }
    return '';
  }

  // Build + inject the breakdown. Mirrors vila_akoya_mbfw_expand_booking_charge_breakdown().
  function renderLiveBill() {
    if (!vilaPricingReady()) {
      return;
    }
    var $bill = ensureLiveBillContainer();
    var d = vilaBookingData();
    var P = d.pricing;
    var S = d.billStrings;

    var nights = getStayNights();
    if (nights < 1) {
      $bill.empty();
      updateStayDiscountUpsellRow(null, 0, S);
      return;
    }

    var stayMode = !!(d && d.stayMode);
    var disc = stayMode ? vilaStayDiscountInfo(nights) : vilaCampsiteStayDiscountInfo(nights, P);
    var supplementNights = nights;
    var adults = Math.max(1, parseInt($('#mwb_mbfw_people_number').val(), 10) || 1);
    var children = Math.max(0, parseInt($('#vila_guest_children').val(), 10) || 0);
    var infants = Math.max(0, parseInt($('#vila_guest_infants').val(), 10) || 0);
    var pets = Math.max(0, parseInt($('#vila_guest_pets').val(), 10) || 0);
    var nightWord = nights === 1 ? S.nightSingular : S.nightPlural;

    var rows = [];
    var total = 0;
    var nightlyAccom = 0;

    var unit = stayMode && P.stayPerNight ? P.stayPerNight : P.adultPerNight;
    var pitchValue = 0;
    var pitchTitle = '';
    if (stayMode) {
      var pitchNightly = unit;
      nightlyAccom += pitchNightly;
      pitchValue = pitchNightly * nights;
      pitchTitle = vilaSprintf(S.stayRateFlat || S.plotRateFlat, vilaFormatPricePlain(unit), nights, nightWord);
    } else {
      var solo = !!(disc && disc.solo_tent);
      var noElec = !!(disc && disc.no_electricity);
      var tierRows = campsiteTierBreakdownRows(nights, P, solo, noElec, S);
      tierRows.forEach(function (row) {
        rows.push(liveBillRow(row.title, row.value));
        total += row.value;
      });
      pitchValue = disc.total || campsiteTierTotal(nights, P, solo, noElec);
      nightlyAccom = pitchValue / Math.max(1, nights);
    }

    var weekdayNights = getWeekdayNights();
    var mealSlugs = [];
    var mealTotal = 0;
    if (P.mealFees) {
      $('input[name="vila_meal_options[]"]:checked').each(function () {
        var slug = this.value;
        var info = P.mealFees[slug];
        if (!info) {
          return;
        }
        var days = info.dayMode === 'weekdays' ? weekdayNights : nights;
        if (days < 1) {
          return;
        }
        var lineValue =
          (adults * (info.adultFee || 0) + children * (info.childFee || 0) + infants * (info.infantFee || 0)) * days;
        if (lineValue <= 0) {
          return;
        }
        mealSlugs.push(slug);
        mealTotal += lineValue;
      });
    }
    var bundleTitle = vilaMealBundleLabel(mealSlugs, S);
    if (stayMode && (pitchValue > 0 || mealTotal > 0)) {
      if (bundleTitle && mealTotal > 0) {
        rows.push(liveBillRow(bundleTitle, pitchValue + mealTotal));
        total += pitchValue + mealTotal;
      } else {
        if (pitchValue > 0) {
          rows.push(liveBillRow(pitchTitle, pitchValue));
          total += pitchValue;
        }
        if (mealTotal > 0) {
          rows.push(liveBillRow('Meals included', mealTotal));
          total += mealTotal;
        }
      }
    } else if (!stayMode && mealTotal > 0) {
      rows.push(liveBillRow(bundleTitle || 'Meals included', mealTotal));
      total += mealTotal;
    }

    if (!stayMode && !P.extrasInformationalOnly && supplementNights > 0) {
      $('input[name="vila_vehicle_types[]"]:checked').each(function () {
        var slug = this.value;
        if (slug === 'tent') {
          return;
        }
        var info = P.vehicleFees[slug];
        if (!info || info.fee <= 0) {
          return;
        }
        var vehicleNightly = info.fee * (P.vehicleByGuests ? adults : 1);
        nightlyAccom += vehicleNightly;
        var lineValue = vehicleNightly * supplementNights;
        total += lineValue;
        var title = P.vehicleByGuests
          ? vilaSprintf(S.vehicleByGuests, info.label, vilaFormatPricePlain(info.fee), adults, supplementNights, nightWord)
          : vilaSprintf(S.vehicleFlat, info.label, vilaFormatPricePlain(info.fee), supplementNights, nightWord);
        rows.push(liveBillRow(title, lineValue));
      });
    }

    if (!P.extrasInformationalOnly && supplementNights > 0 && children > 0 && P.guestRates.children > 0) {
      nightlyAccom += children * P.guestRates.children;
      var chVal = children * P.guestRates.children * supplementNights;
      total += chVal;
      rows.push(
        liveBillRow(
          vilaSprintf(S.children, children, vilaFormatPricePlain(P.guestRates.children), supplementNights, nightWord),
          chVal
        )
      );
    }
    if (!P.extrasInformationalOnly && supplementNights > 0 && infants > 0 && P.guestRates.infants > 0) {
      nightlyAccom += infants * P.guestRates.infants;
      var infVal = infants * P.guestRates.infants * supplementNights;
      total += infVal;
      rows.push(
        liveBillRow(
          vilaSprintf(S.infants, infants, vilaFormatPricePlain(P.guestRates.infants), supplementNights, nightWord),
          infVal
        )
      );
    }
    if (!P.extrasInformationalOnly && supplementNights > 0 && pets > 0 && P.guestRates.pets > 0) {
      nightlyAccom += pets * P.guestRates.pets;
      var petVal = pets * P.guestRates.pets * supplementNights;
      total += petVal;
      rows.push(
        liveBillRow(vilaSprintf(S.pets, pets, vilaFormatPricePlain(P.guestRates.pets), supplementNights, nightWord), petVal)
      );
    }

    if (disc.free > 0 && nightlyAccom > 0) {
      var discountFmt = S.discountApplied || 'Stay %1$d, pay %2$d — %3$d %4$s free';
      var discountValue = nightlyAccom * disc.free;
      var freeWord = disc.free === 1 ? S.nightSingular : S.nightPlural;
      rows.push(
        liveBillRow(
          vilaSprintf(discountFmt, disc.stay, disc.billable, disc.free, freeWord),
          -discountValue,
          'vila-live-bill__discount'
        )
      );
      total -= discountValue;
    }

    var html =
      '<div class="mbfw-total-listing-single-page__wrapper-parent">' +
      rows.join('') +
      '<div class="mbfw-total-listing-single-page__wrapper mbfw-total-cost__wrapper">' +
      '<div class="mbfw-total-listing-single-page">' + vilaEscapeHtml(S.total) + '</div>' +
      '<div class="mbfw-total-listing-single-page">' + vilaFormatPriceHtml(total) + '</div>' +
      '</div></div>';
    $bill.html(html);
    updateStayDiscountUpsellRow(disc, nightlyAccom, S);
  }

  // ── "Finalising your booking" overlay ────────────────────────────────────
  // Add-to-cart runs a slow AJAX add then redirects to checkout (can take
  // several seconds). Without feedback the page just sits there. Show a
  // full-screen loader on submit so the user knows it's working.
  var vilaSubmitOverlaySafety = null;

  function showBookingSubmitOverlay() {
    if ($('.vila-booking-submit-overlay').length) {
      return;
    }
    var d = vilaBookingData();
    var s = (d && d.strings) || {};
    var title = s.finalising || 'Finalising your booking…';
    var sub = s.finalisingSub || 'Preparing checkout. This only takes a few seconds.';
    var $overlay = $(
      '<div class="vila-booking-submit-overlay" role="alert" aria-live="assertive" aria-busy="true">' +
        '<div class="vila-booking-submit-overlay__spinner" aria-hidden="true"></div>' +
        '<p class="vila-booking-submit-overlay__title"></p>' +
        '<p class="vila-booking-submit-overlay__sub"></p>' +
      '</div>'
    );
    $overlay.find('.vila-booking-submit-overlay__title').text(title);
    $overlay.find('.vila-booking-submit-overlay__sub').text(sub);
    $('body').append($overlay).addClass('vila-booking-submitting');
    // Safety net: if add-to-cart errors without navigating, don't trap the user.
    clearTimeout(vilaSubmitOverlaySafety);
    vilaSubmitOverlaySafety = setTimeout(hideBookingSubmitOverlay, 30000);
  }

  function hideBookingSubmitOverlay() {
    clearTimeout(vilaSubmitOverlaySafety);
    $('.vila-booking-submit-overlay').remove();
    $('body').removeClass('vila-booking-submitting');
  }

  function teardownRangePickerUi() {
    var el = document.getElementById('vila-akoya-booking-range');
    if (el && el._flatpickr) {
      el._flatpickr.destroy();
    }
    $('.vila-airbnb-booking--dates.vila-booking-range-ui').remove();
  }

  /**
   * Browser back after Book Now often restores a frozen page (overlay still up,
   * Flatpickr instance dead, MWB hidden date fields cleared). Rebuild the stay
   * UI from whatever dates are still visible.
   */
  function restoreBookingFormAfterBack() {
    hideBookingSubmitOverlay();
    if (!document.getElementById('mwb-mbfw-booking-from-time')) {
      return;
    }
    var saved = getStayDatePair();
    if (saved) {
      $('#mwb-mbfw-booking-from-time').val(formatDMY(saved[0]));
      $('#mwb-mbfw-booking-to-time').val(formatDMY(saved[1]));
    }
    teardownRangePickerUi();
    initRangePicker();
    vilaRangeUnavailable = false;
    syncBookingTotalsAndCtaState();
    renderLiveBill();
    scheduleStayRangeAvailabilityCheck();
  }

  // Live bill is the source of truth. Do not trigger MWB's change→admin-ajax
  // recalculation: that request re-renders the form, which used to fire
  // ajaxComplete → another change → hundreds of admin-ajax.php calls.
  function nudgeMwbState() {}

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
    // The live total renders synchronously, so there's no "loading" gate here —
    // the button is blocked only when there is no valid/available stay.
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

  function stayRangeHasUnavailableNight(stay) {
    var list = window.vilaMwbUnavailableList || [];
    if (!stay || !stay.check_in || !stay.check_out || !list.length) {
      return false;
    }
    var cur = new Date(stay.check_in + 'T00:00:00');
    var end = new Date(stay.check_out + 'T00:00:00');
    if (isNaN(cur.getTime()) || isNaN(end.getTime()) || end <= cur) {
      return false;
    }
    while (cur < end) {
      var y = ymdFromDate(cur);
      if (list.indexOf(y) !== -1) {
        return true;
      }
      cur.setDate(cur.getDate() + 1);
    }
    return false;
  }

  function runStayRangeAvailabilityCheck() {
    var stay = getStayYmdFromMwbInputs();
    if (!stay) {
      clearAvailabilityWarning();
      return;
    }
    if (typeof vilaAkoyaBooking !== 'undefined' && vilaAkoyaBooking.stayMode) {
      if (stayRangeHasUnavailableNight(stay)) {
        var stayMsg =
          vilaAkoyaBooking.strings && vilaAkoyaBooking.strings.rangeUnavailable
            ? vilaAkoyaBooking.strings.rangeUnavailable
            : 'This stay is not available for those dates.';
        showAvailabilityWarning(stayMsg);
      } else {
        clearAvailabilityWarning();
      }
      return;
    }
    var base = getRestUrlForRangeCheck();
    if (!base) {
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
   * Desktop: Guests + Your dates side by side; Your setup; Meals; then Notes full width.
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
      var $meals = $form.find('.vila-airbnb-booking--meals').first();

      if (!$datesWrap.length || !$who.length) {
        return;
      }

      if (!$who.closest('.vila-booking-dates-who').length) {
        var $row = $('<div class="vila-booking-dates-who"></div>');
        $datesWrap.before($row);
        var stayMode = typeof vilaAkoyaBooking !== 'undefined' && vilaAkoyaBooking.stayMode;
        if (stayMode) {
          $row.append($datesWrap);
          $row.append($who);
        } else {
          $row.append($who);
          $row.append($datesWrap);
        }
      }
      ensureStayDiscountUpsellRow();

      if ($meals.length && $setup.length) {
        $meals.insertAfter($setup);
      }
      if ($notes.length) {
        if ($meals.length) {
          $notes.insertAfter($meals);
        } else if ($setup.length) {
          $notes.insertAfter($setup);
        }
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
    if (booking && booking.stayMode && booking.plotUnavailableYmd) {
      var sid = String(booking.defaultPlotProductId || '');
      window.vilaMwbUnavailableList = booking.plotUnavailableYmd[sid] || [];
      redrawRangePickerIfAny();
      return;
    }
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

  function occupancyTotal(s, $root) {
    var mode = $root && $root.attr ? ($root.attr('data-vila-occupancy') || '') : '';
    if (mode === 'beds') {
      return s.adults + s.children;
    }
    return s.adults + s.children + s.infants;
  }

  function guestSummaryString(a, c, i, p) {
    var parts = [];
    var gs = typeof vilaAkoyaBooking !== 'undefined' && vilaAkoyaBooking.guestStrings ? vilaAkoyaBooking.guestStrings : null;
    function counted(n, one, many, fallbackOne, fallbackMany) {
      if (n < 1) {
        return;
      }
      if (n === 1) {
        parts.push(gs && gs[one] ? gs[one] : fallbackOne);
        return;
      }
      var tpl = gs && gs[many] ? gs[many] : fallbackMany;
      parts.push(tpl.replace('%d', String(n)));
    }
    counted(a, 'oneAdult', 'nAdults', '1 adult', '%d adults');
    counted(c, 'oneChild', 'nChildren', '1 child', '%d children');
    counted(i, 'oneBaby', 'nBabies', '1 baby', '%d babies');
    counted(p, 'onePet', 'nPets', '1 pet', '%d pets');
    return parts.length ? parts.join(' · ') : '1 adult';
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
    var maxInf = parseInt($root.attr('data-vila-max-infants'), 10);
    if (isNaN(maxInf)) {
      maxInf = 5;
    }
    var beds = ($root.attr('data-vila-occupancy') || '') === 'beds';
    s.adults = Math.max(minA, s.adults);
    s.children = Math.max(0, s.children);
    s.infants = Math.max(0, Math.min(maxInf, s.infants));
    s.pets = Math.max(0, Math.min(maxPet, s.pets));
    var occ = occupancyTotal(s, $root);
    if (occ > maxP) {
      var over = occ - maxP;
      while (over > 0 && s.children > 0) {
        s.children--;
        over--;
      }
      if (!beds) {
        while (over > 0 && s.infants > 0) {
          s.infants--;
          over--;
        }
      }
      while (over > 0 && s.adults > minA) {
        s.adults--;
        over--;
      }
    }
    $root.find('#mwb_mbfw_people_number').val(String(s.adults));
    $root.find('#vila_guest_children, [name="stay-children"]').val(String(s.children));
    $root.find('#vila_guest_infants, [name="stay-infants"]').val(String(s.infants));
    $root.find('#vila_guest_pets, [name="stay-pets"]').val(String(s.pets));
    $root.find('[name="stay-adults"]').val(String(s.adults));
    $root.find('.vila-guest-picker__summary').text(guestSummaryString(s.adults, s.children, s.infants, s.pets, beds));
    $root.find('[data-vila-value-for]').each(function () {
      var slug = $(this).attr('data-vila-value-for');
      if (slug && s[slug] !== undefined) {
        $(this).text(String(s[slug]));
      }
    });
    updateGuestStepperUi($root, s, minA, maxP, maxPet);
    updateVehicleHint();
    renderLiveBill();
  }

  function updateGuestStepperUi($root, s, minA, maxP, maxPet) {
    var beds = ($root.attr('data-vila-occupancy') || '') === 'beds';
    var occ = occupancyTotal(s, $root);
    var maxInf = parseInt($root.attr('data-vila-max-infants'), 10);
    if (isNaN(maxInf)) {
      maxInf = 5;
    }
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
        if (delta > 0 && occ >= maxP) {
          dis = true;
        }
        if (delta > 0 && next > maxP - s.children - (beds ? 0 : s.infants)) {
          dis = true;
        }
      } else if (slug === 'children') {
        if (delta < 0 && next < 0) {
          dis = true;
        }
        if (delta > 0 && occ >= maxP) {
          dis = true;
        }
      } else if (slug === 'infants') {
        if (delta < 0 && next < 0) {
          dis = true;
        }
        if (delta > 0 && next > maxInf) {
          dis = true;
        }
        if (!beds && delta > 0 && occ >= maxP) {
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
    var stayMode = typeof vilaAkoyaBooking !== 'undefined' && vilaAkoyaBooking.stayMode;
    if (!stayMode && mwb_mbfw_public_obj.wps_cal_type !== 'dual_cal') {
      initPlotSelector();
      scheduleStayRangeAvailabilityCheck();
      layoutBookingFormSections();
      return;
    }
    if (!stayMode && mwb_mbfw_public_obj.booking_unit === 'hour') {
      return;
    }
    if (!stayMode && mwb_mbfw_public_obj.mwb_mbfw_show_date_with_time === 'yes') {
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

    var existing = document.getElementById('vila-akoya-booking-range');
    if (existing && existing._flatpickr) {
      syncInputsFromVisibleRange();
      updateVehicleHint();
      initPlotSelector();
      scheduleStayRangeAvailabilityCheck();
      layoutBookingFormSections();
      renderLiveBill();
      return;
    }
    if (existing) {
      $(existing).closest('.vila-airbnb-booking--dates').remove();
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
        '<p class="vila-airbnb-booking__legend">' +
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
          // Populate BOTH date inputs, then render instantly from the range.
          $(fromEl).val(formatDMY(selectedDates[0]));
          $(toEl).val(formatDMY(selectedDates[1]));
          renderLiveBill();
          nudgeMwbState();
        }
        if (selectedDates.length === 0) {
          vilaUserPickedDates = false;
          $(fromEl).val('');
          $(toEl).val('');
          renderLiveBill();
          nudgeMwbState();
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
    renderLiveBill();
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
      fp._flatpickr.setDate([da, db], false);
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
    // Set values only — do not trigger change. MWB AJAX on change during
    // Book Now races the add-to-cart POST and can reload the booking page.
    fromEl.value = fromVal;
    toEl.value = toVal;
    return true;
  }

  function initBookingReserveModeTabs() {
    var $card = $('.va-booking-reserve-card').first();
    if (!$card.length || !$card.find('[data-va-booking-tabs]').length) {
      return;
    }

    function accommodationModeFromHash() {
      var hash = String(window.location.hash || '').toLowerCase();
      if (/^#accommodation$/.test(hash) || /^#village$/.test(hash)) {
        return 'accommodation';
      }
      return '';
    }

    function normalizeBookingMode(mode) {
      return mode === 'village' ? 'accommodation' : mode;
    }

    function activate(mode) {
      mode = normalizeBookingMode(mode || '');
      $card.find('[data-va-booking-tab]').each(function () {
        var sel = normalizeBookingMode($(this).attr('data-va-booking-tab')) === mode;
        $(this).toggleClass('is-active', sel);
        $(this).attr('aria-selected', sel ? 'true' : 'false');
        $(this).attr('tabindex', sel ? '0' : '-1');
      });
      $card.find('[data-va-booking-panel]').each(function () {
        var show = normalizeBookingMode($(this).attr('data-va-booking-panel')) === mode;
        $(this).prop('hidden', !show);
        $(this).attr('aria-hidden', show ? 'false' : 'true');
      });
    }

    $card.on('click', '[data-va-booking-tab]', function (e) {
      e.preventDefault();
      var mode = normalizeBookingMode($(this).attr('data-va-booking-tab'));
      if (!mode) {
        return;
      }
      activate(mode);
      if (window.history && typeof window.history.replaceState === 'function') {
        var path = window.location.pathname + window.location.search;
        var hash = mode === 'accommodation' ? '#accommodation' : '';
        window.history.replaceState(null, '', path + hash);
      }
    });

    $(window).on('hashchange.vilaBookingReserveTabs', function () {
      var mode = accommodationModeFromHash();
      activate(mode || 'campsite');
    });

    var initialMode = accommodationModeFromHash();
    activate(initialMode || 'campsite');
  }

  $(function () {
    $('.vila-stay-discount-date-upsell, .vila-airbnb-booking__deals-hint, .vila-live-bill__upsell').remove();
    setTimeout(bootstrapRangePicker, 120);
    initGuestPicker();
    layoutBookingFormSections();
    initBookingMapLightbox();
    initBookingReserveModeTabs();
    setTimeout(layoutBookingFormSections, 400);
    setTimeout(layoutBookingFormSections, 1000);

    // Take ownership of the bill display: create our container and flag the
    // body so CSS hides MWB's own (now redundant) total area. If pricing data
    // is missing (e.g. stale cache), we skip this and MWB's AJAX bill remains
    // the visible fallback.
    if (vilaPricingReady()) {
      $('body').addClass('vila-live-bill-active');
      ensureLiveBillContainer();
      renderLiveBill();
    }

    $(document).on(
      'change input',
      'input[name="vila_vehicle_types[]"], #mwb-mbfw-booking-from-time, #mwb-mbfw-booking-to-time, #mwb_mbfw_people_number',
      function () {
        updateVehicleHint();
      }
    );

    // Single source of truth: re-render the bill instantly whenever any pricing
    // input changes (dates, guests, extras). No AJAX, no races, no flicker.
    $(document).on(
      'change input',
      '#vila_campsite_electricity_included, #vila_campsite_no_electricity, input[name="vila_meal_options[]"], #mwb-mbfw-booking-from-time, #mwb-mbfw-booking-to-time, #mwb_mbfw_people_number, #vila_guest_children, #vila_guest_infants, #vila_guest_pets',
      function () {
        renderLiveBill();
      }
    );

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
    $(document).on('change', '#vila_campsite_electricity_included', syncCampsiteElectricityFields);

    $(document).on('click', 'form.cart .single_add_to_cart_button, form.cart button[name="add-to-cart"], form.cart input[name="add-to-cart"]', function () {
      syncInputsFromVisibleRange();
      syncCampsiteElectricityFields();
      var $btn = $(this);
      var blocked = $btn.is(':disabled') || $btn.attr('aria-disabled') === 'true' || $btn.hasClass('disabled');
      if (!blocked) {
        showBookingSubmitOverlay();
      }
    });
    $(document).on('submit', 'form.cart', function () {
      syncInputsFromVisibleRange();
      syncCampsiteElectricityFields();
      showBookingSubmitOverlay();
    });

    // Clear the overlay if add-to-cart fails (so the user isn't trapped) and
    // when returning to the page via the browser back/forward cache.
    if (!window.vilaMwbAjaxPrefilterBound) {
      window.vilaMwbAjaxPrefilterBound = true;
      $.ajaxPrefilter(function (options, originalOptions, jqXHR) {
        var data = options.data || '';
        var isMwbCost = false;
        var isAddToCart = false;

        if (typeof data === 'string') {
          isMwbCost =
            data.indexOf('mwb_mbfw') !== -1 ||
            data.indexOf('mbfw_') !== -1 ||
            data.indexOf('wps_mbfw') !== -1;
          isAddToCart =
            data.indexOf('add-to-cart') !== -1 ||
            data.indexOf('add_to_cart') !== -1;
        } else if (typeof FormData !== 'undefined' && data instanceof FormData) {
          try {
            var action = data.get('action') || '';
            isMwbCost = action.indexOf('mbfw') !== -1;
          } catch (e) {}
        }

        if (!isMwbCost) {
          return;
        }
        if (isAddToCart) {
          return;
        }
        jqXHR.abort();
      });
    }
    $(window).on('pagehide.vilaBookingRestore', function () {
      hideBookingSubmitOverlay();
    });
    $(window).on('pageshow.vilaBookingRestore', function (e) {
      var persisted = !!(e.originalEvent && e.originalEvent.persisted);
      var hadOverlay =
        $('.vila-booking-submit-overlay').length > 0 || $('body').hasClass('vila-booking-submitting');
      hideBookingSubmitOverlay();
      if (persisted || hadOverlay) {
        restoreBookingFormAfterBack();
      }
    });

    setTimeout(scheduleStayRangeAvailabilityCheck, 600);
    setTimeout(function () {
      syncBookingTotalsAndCtaState();
      renderLiveBill();
    }, 150);
  });
})(jQuery);
