/**
 * On successful CF7 submit: hide form fields and show the CF7 success message inline.
 */
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

  var output = form.querySelector(".wpcf7-response-output");
  var message =
    detail.apiResponse && detail.apiResponse.message
      ? detail.apiResponse.message
      : "";

  root.classList.add("vila-cf7--success");
  form.classList.add("vila-cf7--success");

  if (!output || !message) {
    return;
  }

  output.textContent = message;
  output.removeAttribute("aria-hidden");
  output.removeAttribute("hidden");
  output.setAttribute("role", "status");
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
