(function () {
  function formsubmitAjaxUrl(action) {
    if (!action || typeof action !== "string") return null;
    if (/formsubmit\.co\/ajax\//i.test(action)) return action;
    var m = action.match(/^https?:\/\/formsubmit\.co\/(.+)$/i);
    if (!m) return null;
    return "https://formsubmit.co/ajax/" + m[1];
  }

  var SPINNER =
    '<svg class="animate-spin h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';

  document.addEventListener(
    "submit",
    function (e) {
      var form = e.target;
      if (!form || form.tagName !== "FORM") return;

      var action = form.getAttribute("action");
      var ajaxUrl = formsubmitAjaxUrl(action);
      if (!ajaxUrl) return;

      e.preventDefault();

      if (form.dataset.formsubmitSending === "1") return;
      form.dataset.formsubmitSending = "1";

      var payload = {};
      new FormData(form).forEach(function (value, key) {
        payload[key] = value;
      });

      var fields = form.querySelectorAll("input, textarea, select, button");
      var i;
      for (i = 0; i < fields.length; i++) {
        fields[i].disabled = true;
      }

      var submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
      var btnOriginal = null;
      if (submitBtn) {
        if (submitBtn.tagName === "BUTTON") {
          btnOriginal = submitBtn.innerHTML;
          submitBtn.setAttribute("aria-busy", "true");
          submitBtn.innerHTML =
            '<span class="inline-flex items-center justify-center gap-2 w-full">' +
            SPINNER +
            "<span>Sending…</span></span>";
        } else {
          btnOriginal = submitBtn.value;
          submitBtn.setAttribute("aria-busy", "true");
          submitBtn.value = "Sending…";
        }
      }

      function recover() {
        delete form.dataset.formsubmitSending;
        for (i = 0; i < fields.length; i++) {
          fields[i].disabled = false;
        }
        if (submitBtn) {
          submitBtn.removeAttribute("aria-busy");
          if (submitBtn.tagName === "BUTTON" && btnOriginal !== null) {
            submitBtn.innerHTML = btnOriginal;
          } else if (submitBtn.tagName === "INPUT" && btnOriginal !== null) {
            submitBtn.value = btnOriginal;
          }
        }
      }

      fetch(ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify(payload),
      })
        .then(function (res) {
          if (!res.ok) throw new Error("bad_status");
          return res.json().catch(function () {
            return {};
          });
        })
        .then(function (data) {
          if (
            data &&
            (data.success === false ||
              data.success === "false")
          ) {
            throw new Error(
              data.message ||
                "The form could not be sent. Make sure this page is opened from your live site (not a local file)."
            );
          }
          var nextInput = form.querySelector('input[name="_next"]');
          var next =
            nextInput && nextInput.value
              ? nextInput.value
              : window.location.origin + "/thank-you";
          window.location.href = next;
        })
        .catch(function (err) {
          recover();
          var fallback =
            "Something went wrong sending your message. Please try again or email us directly.";
          var msg =
            err && err.message && err.message !== "bad_status"
              ? err.message
              : fallback;
          window.alert(msg);
        });
    },
    false
  );
})();
