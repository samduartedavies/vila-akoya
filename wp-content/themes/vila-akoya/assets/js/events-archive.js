(function () {
  var root = document.querySelector("[data-vila-events]");
  if (!root) return;

  var buttons = root.querySelectorAll("[data-events-view]");
  var listPanel = root.querySelector('[data-events-panel="list"]');
  var calPanel = root.querySelector('[data-events-panel="calendar"]');
  var dataEl = document.getElementById("vila-events-cal-data");
  var payload = { startOfWeek: 1, weekdays: [], months: [], days: {}, today: "" };

  if (dataEl) {
    try {
      payload = JSON.parse(dataEl.textContent || "{}");
    } catch (err) {
      payload = { startOfWeek: 1, weekdays: [], months: [], days: {}, today: "" };
    }
  }

  function setView(view) {
    var next = view === "calendar" ? "calendar" : "list";
    root.setAttribute("data-view", next);
    buttons.forEach(function (btn) {
      var on = btn.getAttribute("data-events-view") === next;
      btn.setAttribute("aria-selected", on ? "true" : "false");
    });
    try {
      var url = new URL(window.location.href);
      if (next === "list") url.searchParams.delete("view");
      else url.searchParams.set("view", "calendar");
      window.history.replaceState({}, "", url);
    } catch (err) {
      /* ignore */
    }
  }

  buttons.forEach(function (btn) {
    btn.addEventListener("click", function () {
      setView(btn.getAttribute("data-events-view"));
    });
  });

  var grid = root.querySelector("[data-cal-grid]");
  var label = root.querySelector("[data-cal-label]");
  var prevBtn = root.querySelector("[data-cal-prev]");
  var nextBtn = root.querySelector("[data-cal-next]");
  if (!grid || !label) return;

  var today = payload.today ? new Date(payload.today + "T12:00:00") : new Date();
  var year = today.getFullYear();
  var month = today.getMonth();
  var startOfWeek = Number(payload.startOfWeek || 1);

  function esc(s) {
    return String(s || "").replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function pad(n) {
    return n < 10 ? "0" + n : String(n);
  }

  function ymd(y, m, d) {
    return y + "-" + pad(m + 1) + "-" + pad(d);
  }

  function weekdayIndex(jsDay) {
    return (jsDay - startOfWeek + 7) % 7;
  }

  function asItems(value) {
    if (!value) return [];
    if (Array.isArray(value)) return value;
    if (typeof value === "object") {
      return Object.keys(value).map(function (k) {
        return value[k];
      });
    }
    return [];
  }

  function render() {
    var monthName = (payload.months && payload.months[month]) || "";
    label.textContent = monthName ? monthName + " " + year : year + "-" + pad(month + 1);

    var first = new Date(year, month, 1);
    var daysInMonth = new Date(year, month + 1, 0).getDate();
    var leading = weekdayIndex(first.getDay());

    var html = '<div class="vila-events-cal__weekdays">';
    (payload.weekdays || []).forEach(function (name) {
    html += '<span class="vila-events-cal__wd">' + esc(name) + "</span>";
    });
    html += "</div><div class='vila-events-cal__days'>";

    var i;
    for (i = 0; i < leading; i++) {
      html += '<div class="vila-events-cal__day is-empty"></div>';
    }

    for (var day = 1; day <= daysInMonth; day++) {
      var key = ymd(year, month, day);
      var items = asItems(payload.days && payload.days[key]);
      var isToday = key === payload.today;
      html += '<div class="vila-events-cal__day' + (isToday ? " is-today" : "") + (items.length ? " has-events" : "") + '">';
      html += '<span class="vila-events-cal__num">' + day + "</span>";
      if (items.length) {
        html += '<ul class="vila-events-cal__events">';
        items.forEach(function (item) {
          var time = item.time ? '<span class="vila-events-cal__time">' + esc(item.time) + "</span>" : "";
          var kind = item.kind === "workshop" ? "workshop" : "event";
          html +=
            '<li><a class="vila-events-cal__event is-' +
            kind +
            '" href="' +
            esc(item.url) +
            '">' +
            time +
            '<span class="vila-events-cal__event-title">' +
            esc(item.title) +
            "</span></a></li>";
        });
        html += "</ul>";
      }
      html += "</div>";
    }

    html += "</div>";
    grid.innerHTML = html;
  }

  if (prevBtn) {
    prevBtn.addEventListener("click", function () {
      month -= 1;
      if (month < 0) {
        month = 11;
        year -= 1;
      }
      render();
    });
  }

  if (nextBtn) {
    nextBtn.addEventListener("click", function () {
      month += 1;
      if (month > 11) {
        month = 0;
        year += 1;
      }
      render();
    });
  }

  render();
  if (root.getAttribute("data-view") === "calendar") setView("calendar");
})();
