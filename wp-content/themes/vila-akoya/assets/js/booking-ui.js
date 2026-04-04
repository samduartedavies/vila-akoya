/**
 * Airbnb-style range calendar + vehicle supplement hint for MWB booking forms.
 */
(function ($) {
  'use strict';

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
    var sym = vilaAkoyaBooking.currencySymbol || '€';
    var total = rate * nights;
    var nLabel =
      nights === 1
        ? vilaAkoyaBooking.strings.nightSingular
        : vilaAkoyaBooking.strings.nightPlural;
    if (total > 0) {
      $hint.text(
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
          '). ' +
          (vilaAkoyaBooking.strings.finalAtCheckout ? vilaAkoyaBooking.strings.finalAtCheckout : '')
      );
    } else {
      $hint.text('');
    }
  }

  function initRangePicker() {
    if (typeof mwb_mbfw_public_obj === 'undefined' || typeof flatpickr === 'undefined') {
      return;
    }
    if (mwb_mbfw_public_obj.wps_cal_type !== 'dual_cal') {
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
      return;
    }


    var unavailable = mwb_mbfw_public_obj.single_unavailable_dates || [];
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
          var y =
            date.getFullYear() +
            '-' +
            pad2(date.getMonth() + 1) +
            '-' +
            pad2(date.getDate());
          return unavailable.indexOf(y) !== -1;
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

    $(document).on(
      'change',
      'input[name="vila_vehicle_type"], #mwb-mbfw-booking-from-time, #mwb-mbfw-booking-to-time',
      updateVehicleHint
    );
  });
})(jQuery);
