/**
 * Range calendar + pitch supplement hint for Bookings for WooCommerce (MWB).
 * Guest count for the core nightly rate is handled by MWB (#mwb_mbfw_people_number + AJAX totals).
 */
(function ($) {
  'use strict';

  var vilaGuestPickerDocBound = false;
  var vilaPlotSelectorBound = false;

  function applySelectedPlotUnavailableDates() {
    if (typeof vilaAkoyaBooking === 'undefined' || !vilaAkoyaBooking.plotUnavailableYmd) {
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
      fp._flatpickr.redraw();
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

  function pad2(n) {
    return String(n).padStart(2, '0');
  }

  function formatDMY(d) {
    return pad2(d.getDate()) + '-' + pad2(d.getMonth() + 1) + '-' + d.getFullYear();
  }

  function parseDMY(s) {
    if (!s || typeof s !== 'string') {
      return null;
    }
    var p = s.split(/[-./]/);
    if (p.length !== 3) {
      return null;
    }
    var day = parseInt(p[0], 10);
    var month = parseInt(p[1], 10) - 1;
    var year = parseInt(p[2], 10);
    if (isNaN(day) || isNaN(month) || isNaN(year)) {
      return null;
    }
    return new Date(year, month, day);
  }

  function nightsBetween(fromStr, toStr) {
    var a = parseDMY(fromStr);
    var b = parseDMY(toStr);
    if (!a || !b) {
      return 1;
    }
    var ms = b.getTime() - a.getTime();
    var days = Math.round(ms / 86400000);
    return days > 0 ? days : 1;
  }

  function getPeopleCount() {
    var $p = $('#mwb_mbfw_people_number');
    if (!$p.length) {
      return 1;
    }
    var n = parseInt($p.val(), 10);
    return !isNaN(n) && n > 0 ? n : 1;
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
    updateGuestEstimate();
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

  function updateGuestEstimate() {
    var $el = $('.vila-guest-picker__estimate');
    if (!$el.length || typeof vilaAkoyaBooking === 'undefined') {
      return;
    }
    var rates = vilaAkoyaBooking.guestCategoryRates;
    if (!rates) {
      return;
    }
    var $root = $('.vila-guest-picker').first();
    var s = getGuestState($root);
    var nights = nightsBetween(
      $('#mwb-mbfw-booking-from-time').val(),
      $('#mwb-mbfw-booking-to-time').val()
    );
    var perNight =
      s.children * (parseFloat(rates.children) || 0) +
      s.infants * (parseFloat(rates.infants) || 0) +
      s.pets * (parseFloat(rates.pets) || 0);
    var total = perNight * nights;
    var sym = vilaAkoyaBooking.currencySymbol || '€';
    if (total > 0 && vilaAkoyaBooking.strings && vilaAkoyaBooking.strings.guestSupplementEstimate) {
      $el.text(
        vilaAkoyaBooking.strings.guestSupplementEstimate +
          ': ' +
          sym +
          total.toFixed(2) +
          ' (' +
          sym +
          perNight.toFixed(2) +
          ' × ' +
          nights +
          ' ' +
          (nights === 1
            ? vilaAkoyaBooking.strings.nightSingular || 'night'
            : vilaAkoyaBooking.strings.nightPlural || 'nights') +
          '). ' +
          (vilaAkoyaBooking.strings.finalAtCheckout || '')
      );
    } else {
      $el.text('');
    }
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

      $r.find('.vila-guest-picker__clear').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        setGuestState($r, {
          adults: defA,
          children: 0,
          infants: 0,
          pets: 0,
        });
        $r.find('.vila-guest-picker__panel').prop('hidden', true);
        $r.find('.vila-guest-picker__trigger').attr('aria-expanded', 'false');
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
    var $r = $('input[name="vila_vehicle_type"]:checked');
    if (!$r.length) {
      return;
    }
    var slug = $r.val();
    var rate = parseFloat($r.attr('data-vila-fee'), 10);
    if (isNaN(rate)) {
      rate =
        vilaAkoyaBooking.vehicleRates && vilaAkoyaBooking.vehicleRates[slug]
          ? parseFloat(vilaAkoyaBooking.vehicleRates[slug])
          : 0;
    }
    var nights = nightsBetween(
      $('#mwb-mbfw-booking-from-time').val(),
      $('#mwb-mbfw-booking-to-time').val()
    );
    var peopleMult = vilaAkoyaBooking.pitchSupplementPerGuest ? getPeopleCount() : 1;
    var sym = vilaAkoyaBooking.currencySymbol || '€';
    var total = rate * nights * peopleMult;
    var nLabel =
      nights === 1
        ? vilaAkoyaBooking.strings.nightSingular
        : vilaAkoyaBooking.strings.nightPlural;
    var parts = [];
    if (vilaAkoyaBooking.strings.guestCountAffectsRate && !$('.vila-guest-picker').length) {
      parts.push(vilaAkoyaBooking.strings.guestCountAffectsRate);
    }
    if (total > 0) {
      parts.push(
        vilaAkoyaBooking.strings.supplement +
          ': ' +
          sym +
          total.toFixed(2) +
          ' (' +
          sym +
          rate.toFixed(0) +
          ' × ' +
          nights +
          ' ' +
          nLabel +
          (vilaAkoyaBooking.pitchSupplementPerGuest ? ' × ' + getPeopleCount() + ' guests' : '') +
          '). ' +
          (vilaAkoyaBooking.strings.finalAtCheckout ? vilaAkoyaBooking.strings.finalAtCheckout : '')
      );
      $hint.text(parts.join(' '));
    } else {
      $hint.text(parts.join(' '));
    }
  }

  function initRangePicker() {
    if (typeof mwb_mbfw_public_obj === 'undefined' || typeof flatpickr === 'undefined') {
      return;
    }
    applySelectedPlotUnavailableDates();
    if (mwb_mbfw_public_obj.wps_cal_type !== 'dual_cal') {
      initPlotSelector();
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
      '<div class="vila-booking-range-ui mb-6">' +
        '<p class="font-label text-xs uppercase tracking-widest text-on-surface-variant mb-2">' +
        (vilaAkoyaBooking && vilaAkoyaBooking.strings ? vilaAkoyaBooking.strings.stayDates : 'Stay dates') +
        '</p>' +
        '<input type="text" id="vila-akoya-booking-range" class="w-full rounded-lg border-0 bg-surface-container-low px-4 py-3 font-body text-center text-on-surface cursor-pointer" readonly tabindex="0" />' +
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
          $(fromEl)
            .val(formatDMY(selectedDates[0]))
            .trigger('change');
          $(toEl).val(formatDMY(selectedDates[1])).trigger('change');
        }
        if (selectedDates.length === 0) {
          $(fromEl).val('').trigger('change');
          $(toEl).val('').trigger('change');
        }
        updateVehicleHint();
      },
      onClose: function () {
        updateVehicleHint();
      },
    });

    syncRangeFromInputs();
    updateVehicleHint();
    initPlotSelector();
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
  }

  $(function () {
    setTimeout(initRangePicker, 120);
    initGuestPicker();

    $(document).on(
      'change input',
      'input[name="vila_vehicle_type"], #mwb-mbfw-booking-from-time, #mwb-mbfw-booking-to-time, #mwb_mbfw_people_number',
      function () {
        updateVehicleHint();
        updateGuestEstimate();
      }
    );
  });
})(jQuery);
