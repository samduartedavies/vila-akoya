/**
 * Block Cart / Checkout: push stay dates from sessionStorage into the server cart via
 * wc.blocksCheckout.extensionCartUpdate (cart/extensions). Place-order payloads do not
 * include per–cart-line booking data; this fixes missing vila_cb_* on the session cart.
 */
(function () {
  'use strict';

  var KEY = 'vila_campsite_booking_stay';

  function getPayload() {
    var raw = null;
    try {
      raw = window.sessionStorage.getItem(KEY);
    } catch (e) {
      return null;
    }
    if (!raw) {
      return null;
    }
    try {
      return JSON.parse(raw);
    } catch (e) {
      return null;
    }
  }

  function tryUpdate() {
    var payload = getPayload();
    if (!payload || !payload.vila_cb_check_in || !payload.vila_cb_check_out) {
      return;
    }
    var bc = window.wc && window.wc.blocksCheckout;
    if (!bc || typeof bc.extensionCartUpdate !== 'function') {
      return;
    }
    bc
      .extensionCartUpdate({
        namespace: 'vila-campsite-booking',
        data: payload,
      })
      .then(function () {
        try {
          window.sessionStorage.removeItem(KEY);
        } catch (e) {}
      })
      .catch(function () {});
  }

  function boot() {
    tryUpdate();
    // Blocks sometimes hydrate after first paint.
    setTimeout(tryUpdate, 400);
    setTimeout(tryUpdate, 1500);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
