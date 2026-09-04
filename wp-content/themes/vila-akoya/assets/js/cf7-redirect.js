/**
 * CF7 success: hide fields and show a branded animated confirmation.
 */
(function () {
  "use strict";

  var COPY = {
    newsletter: {
      title: "You're on the list",
      body: "Thanks for joining — we'll share openings, stories and news from Vila Akoya when there's something lovely to tell.",
    },
    booking: {
      title: "We've got your message",
      body: "Thank you for reaching out about the village. We'll read what you've shared and get back to you with options and next steps.",
    },
    campus: {
      title: "Inquiry received",
      body: "Thanks for sharing your workshop or retreat idea. We'll be in touch soon about hosting with us on campus.",
    },
    contact: {
      title: "Message sent",
      body: "Thank you for writing — we've received your note and will reply as soon as we can.",
    },
    stay: {
      title: "Request sent",
      body: "We'll check those dates and get back to you.",
    },
    default: {
      title: "Message sent",
      body: "Thank you — we've received your message and will get back to you soon.",
    },
  };

  function detectCopy(form) {
    var className = (form && form.className) || "";
    if (className.indexOf("vila-cf7--newsletter") !== -1) {
      return COPY.newsletter;
    }
    if (className.indexOf("vila-cf7--booking") !== -1) {
      return COPY.booking;
    }
    if (className.indexOf("vila-cf7--campus") !== -1) {
      return COPY.campus;
    }
    if (className.indexOf("vila-cf7--contact") !== -1) {
      return COPY.contact;
    }
    if (className.indexOf("vila-cf7--stay") !== -1) {
      return COPY.stay;
    }
    return COPY.default;
  }

  function ensureSuccessPanel(form, copy) {
    var existing = form.querySelector(".vila-cf7-success");
    if (existing) {
      return existing;
    }

    var panel = document.createElement("div");
    panel.className = "vila-cf7-success";
    panel.setAttribute("role", "status");
    panel.setAttribute("aria-live", "polite");
    panel.innerHTML =
      '<div class="vila-cf7-success__icon" aria-hidden="true">' +
      '<span class="material-symbols-outlined">mark_email_read</span>' +
      "</div>" +
      '<p class="vila-cf7-success__eyebrow">Sent</p>' +
      '<h3 class="vila-cf7-success__title"></h3>' +
      '<p class="vila-cf7-success__body"></p>';

    panel.querySelector(".vila-cf7-success__title").textContent = copy.title;
    panel.querySelector(".vila-cf7-success__body").textContent = copy.body;
    form.appendChild(panel);
    return panel;
  }

  function vilaAkoyaCf7ShowSuccess(event) {
    var detail = event && event.detail ? event.detail : {};
    var root = null;

    if (event.target && event.target.classList && event.target.classList.contains("wpcf7")) {
      root = event.target;
    } else if (event.target && event.target.closest) {
      root = event.target.closest(".wpcf7");
    }

    if (!root && detail.unitTag) {
      root = document.getElementById(detail.unitTag);
    }

    if (!root) {
      return;
    }

    var form = root.querySelector("form.wpcf7-form");
    if (!form) {
      return;
    }

    var copy = detectCopy(form);
    var panel = ensureSuccessPanel(form, copy);
    var output = form.querySelector(".wpcf7-response-output");

    root.classList.add("vila-cf7--success");
    form.classList.add("vila-cf7--success");

    if (output) {
      output.setAttribute("aria-hidden", "true");
      output.setAttribute("hidden", "");
    }

    // Restart animation if the form is submitted again in the same session.
    panel.classList.remove("is-visible");
    void panel.offsetWidth;
    panel.classList.add("is-visible");

    try {
      panel.scrollIntoView({ behavior: "smooth", block: "nearest" });
    } catch (e) {
      /* ignore */
    }
  }

  document.addEventListener("wpcf7mailsent", vilaAkoyaCf7ShowSuccess, false);
  document.addEventListener(
    "wpcf7mailsent",
    function (event) {
      window.requestAnimationFrame(function () {
        vilaAkoyaCf7ShowSuccess(event);
      });
    },
    false
  );
})();
