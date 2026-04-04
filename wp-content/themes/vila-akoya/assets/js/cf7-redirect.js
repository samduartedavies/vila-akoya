/**
 * After successful CF7 AJAX submit, redirect to thank-you page.
 */
document.addEventListener(
  "wpcf7mailsent",
  function (event) {
    var url = window.vilaAkoyaCf7 && window.vilaAkoyaCf7.thankYouUrl;
    if (url) {
      window.location.href = url;
    }
  },
  false
);
