/**
 * Guest stepper for stay inquiry (and any .vila-guest-picker on the page).
 * Sleeps occupancy is adults + children when data-vila-occupancy="beds".
 */
(function () {
  "use strict";

  function closestRoot(el) {
    return el && el.closest ? el.closest(".vila-guest-picker") : null;
  }

  function attrInt(root, name, fallback) {
    var n = parseInt(root.getAttribute(name) || "", 10);
    return isNaN(n) ? fallback : n;
  }

  function isBeds(root) {
    return (root.getAttribute("data-vila-occupancy") || "") === "beds";
  }

  function field(root, selectors) {
    var found = root.querySelector(selectors);
    if (found) return found;
    var form = root.closest("form");
    return form ? form.querySelector(selectors) : null;
  }

  function occupancy(s, root) {
    return isBeds(root) ? s.adults + s.children : s.adults + s.children + s.infants;
  }

  function summary(a, c, i, p, beds) {
    var people = beds ? a + c : a + c + i;
    var parts = [];
    parts.push(people === 1 ? "1 guest" : people + " guests");
    if (beds && i > 0) {
      parts.push(i === 1 ? "1 baby" : i + " babies");
    }
    if (p > 0) {
      parts.push(p === 1 ? "1 pet" : p + " pets");
    }
    return parts.join(" · ");
  }

  function getState(root) {
    function val(sel) {
      var el = field(root, sel);
      return el ? parseInt(el.value, 10) || 0 : 0;
    }
    return {
      adults: val('#mwb_mbfw_people_number, [name="stay-adults"]'),
      children: val('#vila_guest_children, [name="stay-children"]'),
      infants: val('#vila_guest_infants, [name="stay-infants"]'),
      pets: val('#vila_guest_pets, [name="stay-pets"]'),
    };
  }

  function setVal(root, selectors, value) {
    var el = field(root, selectors);
    if (el) {
      el.value = String(value);
    }
  }

  function setState(root, s) {
    var minA = attrInt(root, "data-vila-min-adults", 1);
    var maxP = attrInt(root, "data-vila-max-people", 8);
    var maxPet = attrInt(root, "data-vila-max-pets", 0);
    var maxInf = attrInt(root, "data-vila-max-infants", 5);
    var beds = isBeds(root);
    s.adults = Math.max(minA, s.adults);
    s.children = Math.max(0, s.children);
    s.infants = Math.max(0, Math.min(maxInf, s.infants));
    s.pets = Math.max(0, Math.min(maxPet, s.pets));
    var occ = occupancy(s, root);
    var over = occ - maxP;
    while (over > 0 && s.children > 0) {
      s.children -= 1;
      over -= 1;
    }
    if (!beds) {
      while (over > 0 && s.infants > 0) {
        s.infants -= 1;
        over -= 1;
      }
    }
    while (over > 0 && s.adults > minA) {
      s.adults -= 1;
      over -= 1;
    }
    setVal(root, '#mwb_mbfw_people_number, [name="stay-adults"]', s.adults);
    setVal(root, '#vila_guest_children, [name="stay-children"]', s.children);
    setVal(root, '#vila_guest_infants, [name="stay-infants"]', s.infants);
    setVal(root, '#vila_guest_pets, [name="stay-pets"]', s.pets);
    var sum = root.querySelector(".vila-guest-picker__summary");
    if (sum) {
      sum.textContent = summary(s.adults, s.children, s.infants, s.pets, beds);
    }
    root.querySelectorAll("[data-vila-value-for]").forEach(function (el) {
      var slug = el.getAttribute("data-vila-value-for");
      if (slug && s[slug] !== undefined) {
        el.textContent = String(s[slug]);
      }
    });
    updateButtons(root, s, minA, maxP, maxPet, maxInf);
    updateStayEstimate(root.closest("form"));
  }

  function updateButtons(root, s, minA, maxP, maxPet, maxInf) {
    var beds = isBeds(root);
    var occ = occupancy(s, root);
    root.querySelectorAll(".vila-guest-picker__btn").forEach(function (btn) {
      var slug = btn.getAttribute("data-vila-slug");
      var delta = parseInt(btn.getAttribute("data-vila-delta") || "", 10);
      if (!slug || isNaN(delta)) return;
      var next = s[slug] + delta;
      var dis = false;
      if (slug === "adults") {
        if (delta < 0 && next < minA) dis = true;
        if (delta > 0 && occ >= maxP) dis = true;
        if (delta > 0 && next > maxP - s.children - (beds ? 0 : s.infants)) dis = true;
      } else if (slug === "children") {
        if (delta < 0 && next < 0) dis = true;
        if (delta > 0 && occ >= maxP) dis = true;
      } else if (slug === "infants") {
        if (delta < 0 && next < 0) dis = true;
        if (delta > 0 && next > maxInf) dis = true;
        if (!beds && delta > 0 && occ >= maxP) dis = true;
      } else if (slug === "pets") {
        if (delta < 0 && next < 0) dis = true;
        if (delta > 0 && next > maxPet) dis = true;
      }
      btn.disabled = dis;
    });
  }

  function closeAll(except) {
    document.querySelectorAll(".vila-guest-picker").forEach(function (root) {
      if (except && root === except) return;
      var panel = root.querySelector(".vila-guest-picker__panel");
      var trigger = root.querySelector(".vila-guest-picker__trigger");
      if (panel) panel.hidden = true;
      if (trigger) trigger.setAttribute("aria-expanded", "false");
    });
  }

  function initRoot(root) {
    if (root.getAttribute("data-vila-guest-ready")) return;
    root.setAttribute("data-vila-guest-ready", "1");
    var s = getState(root);
    if (!s.adults) {
      s.adults = attrInt(root, "data-vila-default-adults", 1);
    }
    setState(root, s);

    var trigger = root.querySelector(".vila-guest-picker__trigger");
    var panel = root.querySelector(".vila-guest-picker__panel");
    if (trigger && panel) {
      trigger.addEventListener("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        var open = panel.hidden === false;
        closeAll(open ? null : root);
        panel.hidden = open;
        trigger.setAttribute("aria-expanded", open ? "false" : "true");
      });
    }

    root.querySelectorAll(".vila-guest-picker__btn").forEach(function (btn) {
      btn.addEventListener("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (btn.disabled) return;
        var slug = btn.getAttribute("data-vila-slug");
        var delta = parseInt(btn.getAttribute("data-vila-delta") || "", 10);
        if (!slug || isNaN(delta)) return;
        var st = getState(root);
        if (st[slug] === undefined) return;
        st[slug] += delta;
        setState(root, st);
      });
    });

    root.addEventListener("click", function (e) {
      e.stopPropagation();
    });
  }

  function formatEuro(amount) {
    var n = Math.round(amount * 100) / 100;
    var neg = n < 0;
    n = Math.abs(n);
    var text;
    if (Math.abs(n - Math.round(n)) < 0.001) {
      text = "€" + String(Math.round(n));
    } else {
      text = "€" + n.toFixed(2).replace(".", ",");
    }
    return (neg ? "−" : "") + text;
  }

  function vilaSprintf(fmt) {
    var args = Array.prototype.slice.call(arguments, 1);
    return String(fmt || "").replace(/%(\d+)\$[ds]/g, function (_m, i) {
      var v = args[parseInt(i, 10) - 1];
      return v === undefined || v === null ? "" : String(v);
    });
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function billableNights(stay) {
    stay = Math.max(0, stay);
    if (stay < 1) return 0;
    var weekly = stay - Math.floor(stay / 7);
    var monthly = Math.floor(stay / 30) * 20 + (stay % 30);
    return Math.min(weekly, monthly);
  }

  function discountInfo(stay) {
    stay = Math.max(0, stay);
    var billed = billableNights(stay);
    var free = Math.max(0, stay - billed);
    var target = stay < 7 ? 7 : stay < 30 ? 30 : (Math.floor(stay / 30) + 1) * 30;
    var targetBillable = billableNights(target);
    var targetFree = Math.max(0, target - targetBillable);
    var saveNights = Math.max(0, targetFree - free);
    return {
      stay: stay,
      billable: billed,
      free: free,
      next: saveNights > 0 && target > stay ? { stay: target, pay: targetBillable, saveNights: saveNights } : null,
      moreNights: saveNights > 0 && target > stay ? target - stay : 0,
    };
  }

  function nightsBetween(fromVal, toVal) {
    if (!fromVal || !toVal) return 0;
    var from = new Date(fromVal + "T00:00:00");
    var to = new Date(toVal + "T00:00:00");
    if (isNaN(from.getTime()) || isNaN(to.getTime())) return 0;
    var days = Math.round((to - from) / 86400000);
    return days > 0 ? days : 0;
  }

  function pad2(n) {
    return n < 10 ? "0" + n : String(n);
  }

  function ymdPlusDays(ymd, days) {
    var d = new Date(ymd + "T00:00:00");
    if (isNaN(d.getTime())) return "";
    d.setDate(d.getDate() + days);
    return d.getFullYear() + "-" + pad2(d.getMonth() + 1) + "-" + pad2(d.getDate());
  }

  function syncDateMins(form) {
    var fromEl = form.querySelector('[name="stay-from"]');
    var toEl = form.querySelector('[name="stay-to"]');
    if (!fromEl || !toEl) return;
    if (fromEl.value) {
      toEl.min = ymdPlusDays(fromEl.value, 1);
      if (toEl.value && toEl.value <= fromEl.value) {
        toEl.value = "";
      }
    }
  }

  function billRow(title, value, extraClass) {
    var wrap = "mbfw-total-listing-single-page__wrapper";
    if (extraClass) wrap += " " + extraClass;
    return (
      '<div class="' +
      wrap +
      '">' +
      '<div class="mbfw-total-listing-single-page">' +
      escapeHtml(title) +
      "</div>" +
      '<div class="mbfw-total-listing-single-page">' +
      escapeHtml(formatEuro(value)) +
      "</div>" +
      "</div>"
    );
  }

  function updateStayEstimate(form) {
    if (!form) return;
    var box = form.querySelector("[data-vila-stay-estimate]");
    if (!box) return;
    var cfg = typeof vilaStayInquiry !== "undefined" ? vilaStayInquiry : {};
    var strings = cfg.strings || {};
    var nightly = parseFloat(cfg.nightly) || 0;
    var minN = parseInt(cfg.minNights, 10) || 1;
    var fromEl = form.querySelector('[name="stay-from"]');
    var toEl = form.querySelector('[name="stay-to"]');
    var fromVal = fromEl ? fromEl.value : "";
    var toVal = toEl ? toEl.value : "";
    var nights = nightsBetween(fromVal, toVal);
    var billEl = box.querySelector("[data-vila-stay-bill]");
    var upsellEl = box.querySelector("[data-vila-stay-upsell]");
    var ratesEl = box.querySelector("[data-vila-stay-rates]");
    var totalInput = form.querySelector('[name="stay-total"]');

    if (nights < 1 || nightly <= 0) {
      if (billEl) {
        billEl.innerHTML = "";
        billEl.hidden = true;
      }
      if (upsellEl) {
        upsellEl.hidden = true;
        upsellEl.textContent = "";
      }
      if (ratesEl) {
        ratesEl.hidden = true;
        ratesEl.textContent = "";
      }
      if (totalInput) totalInput.value = "";
      form.classList.remove("vila-stay-inquiry--dates");
      return;
    }

    form.classList.add("vila-stay-inquiry--dates");
    if (billEl) billEl.hidden = false;
    var disc = discountInfo(nights);
    var nightWord = nights === 1 ? strings.night || "night" : strings.nights || "nights";
    var pitchValue = nightly * nights;
    var total = nightly * disc.billable;
    var rows = [
      billRow(
        vilaSprintf(strings.stayRateFlat || "Stay — %1$s/night × %2$d %3$s", formatEuro(nightly), nights, nightWord),
        pitchValue
      ),
    ];
    if (disc.free > 0) {
      var freeWord = disc.free === 1 ? strings.night || "night" : strings.nights || "nights";
      rows.push(
        billRow(
          vilaSprintf(
            strings.discountApplied || "Stay %1$d, pay %2$d — %3$d %4$s free",
            disc.stay,
            disc.billable,
            disc.free,
            freeWord
          ),
          -nightly * disc.free,
          "vila-live-bill__discount"
        )
      );
    }
    if (nights < minN) {
      rows.push(
        '<div class="mbfw-total-listing-single-page__wrapper vila-live-bill__hint">' +
          '<div class="mbfw-total-listing-single-page">' +
          escapeHtml(vilaSprintf(strings.minStay || "Minimum stay is %1$d nights.", minN)) +
          "</div></div>"
      );
    }
    rows.push(
      '<div class="mbfw-total-listing-single-page__wrapper mbfw-total-cost__wrapper">' +
        '<div class="mbfw-total-listing-single-page">' +
        escapeHtml(strings.total || "Total") +
        "</div>" +
        '<div class="mbfw-total-listing-single-page">' +
        escapeHtml(formatEuro(total)) +
        "</div>" +
        "</div>"
    );
    if (billEl) {
      billEl.innerHTML = '<div class="mbfw-total-listing-single-page__wrapper-parent">' + rows.join("") + "</div>";
    }
    if (ratesEl) {
      var effective = nights > 0 ? total / nights : nightly;
      var bits = [vilaSprintf(strings.perNight || "%s / night", formatEuro(effective))];
      if (nights >= 30) {
        var monthBlocks = Math.floor(nights / 30);
        var monthShare = nightly * 20 * monthBlocks;
        bits.push(vilaSprintf(strings.perMonth || "%s / month", formatEuro(monthShare / monthBlocks)));
      } else {
        bits.push(vilaSprintf(strings.perMonth || "%s / month", formatEuro(nightly * 20)));
      }
      ratesEl.hidden = false;
      ratesEl.textContent = bits.join(" · ");
    }
    if (upsellEl) {
      if (disc.next && disc.moreNights > 0) {
        var moreWord = disc.moreNights === 1 ? strings.night || "night" : strings.nights || "nights";
        upsellEl.textContent = vilaSprintf(
          strings.upsell || "Only %1$d more %2$s and you unlock stay %3$d, pay %4$d (%5$d extra %6$s free).",
          disc.moreNights,
          moreWord,
          disc.next.stay,
          disc.next.pay,
          disc.next.saveNights,
          disc.next.saveNights === 1 ? strings.night || "night" : strings.nights || "nights"
        );
        upsellEl.hidden = false;
      } else {
        upsellEl.hidden = true;
        upsellEl.textContent = "";
      }
    }
    if (totalInput) totalInput.value = formatEuro(total);
  }

  function initStayEstimate() {
    document.querySelectorAll("[data-vila-stay-estimate]").forEach(function (box) {
      var form = box.closest("form");
      if (!form || form.getAttribute("data-vila-estimate-ready")) return;
      form.setAttribute("data-vila-estimate-ready", "1");
      form.addEventListener("change", function () {
        syncDateMins(form);
        updateStayEstimate(form);
      });
      form.addEventListener("input", function () {
        updateStayEstimate(form);
      });
      syncDateMins(form);
      updateStayEstimate(form);
    });
  }

  function init() {
    document.querySelectorAll(".vila-guest-picker").forEach(initRoot);
    initStayEstimate();
  }

  document.addEventListener("click", function (e) {
    if (!closestRoot(e.target)) closeAll();
  });
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") closeAll();
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
