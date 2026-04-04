(function () {
  document.addEventListener(
    'wpcf7mailsent',
    function () {
      if (typeof vilaAkoyaCf7 !== 'undefined' && vilaAkoyaCf7.thankYouUrl) {
        window.location.href = vilaAkoyaCf7.thankYouUrl;
      }
    },
    false
  );
})();
