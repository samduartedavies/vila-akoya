/**
 * After successful CF7 AJAX submit, redirect to thank-you page.
 */
document.addEventListener(
  "wpcf7mailsent",
  function (event) {
    var config = window.vilaAkoyaCf7 || {};
    var url = config.thankYouUrl;
    var redirectFormIds = Array.isArray(config.redirectFormIds)
      ? config.redirectFormIds
      : [];
    var formId =
      event && event.detail && event.detail.contactFormId
        ? String(event.detail.contactFormId)
        : "";
    var shouldRedirect =
      !redirectFormIds.length || redirectFormIds.indexOf(formId) !== -1;

    if (url && shouldRedirect) {
      window.location.href = url;
    }
  },
  false
);
