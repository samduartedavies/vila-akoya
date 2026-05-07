(() => {
  const GALLERY_SELECTOR = ".va-campsite-gallery";
  const IMAGE_LINK_SELECTOR = ".va-campsite-gallery a[href]";

  let lightbox;
  let lightboxImage;
  let lightboxCaption;

  function buildLightbox() {
    if (lightbox) return;

    lightbox = document.createElement("div");
    lightbox.className = "va-gallery-lightbox hidden";
    lightbox.setAttribute("aria-hidden", "true");
    lightbox.setAttribute("role", "dialog");
    lightbox.setAttribute("aria-modal", "true");
    lightbox.setAttribute("aria-label", "Expanded gallery image");
    lightbox.innerHTML =
      '<button type="button" class="va-gallery-lightbox__backdrop" aria-label="Close image viewer"></button>' +
      '<div class="va-gallery-lightbox__content">' +
      '<button type="button" class="va-gallery-lightbox__close" aria-label="Close image viewer">&times;</button>' +
      '<img class="va-gallery-lightbox__image" alt="" />' +
      '<p class="va-gallery-lightbox__caption"></p>' +
      "</div>";

    document.body.appendChild(lightbox);

    lightboxImage = lightbox.querySelector(".va-gallery-lightbox__image");
    lightboxCaption = lightbox.querySelector(".va-gallery-lightbox__caption");

    lightbox.addEventListener("click", (event) => {
      if (
        event.target.closest(".va-gallery-lightbox__close") ||
        event.target.closest(".va-gallery-lightbox__backdrop")
      ) {
        closeLightbox();
      }
    });
  }

  function openLightbox(src, alt) {
    buildLightbox();
    lightboxImage.src = src;
    lightboxImage.alt = alt || "";
    lightboxCaption.textContent = alt || "";
    lightbox.classList.remove("hidden");
    lightbox.setAttribute("aria-hidden", "false");
    document.body.classList.add("va-gallery-lightbox-open");
  }

  function closeLightbox() {
    if (!lightbox) return;
    lightbox.classList.add("hidden");
    lightbox.setAttribute("aria-hidden", "true");
    document.body.classList.remove("va-gallery-lightbox-open");
  }

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") closeLightbox();
  });

  document.addEventListener("click", (event) => {
    const link = event.target.closest(IMAGE_LINK_SELECTOR);
    if (!link || !link.closest(GALLERY_SELECTOR)) return;

    event.preventDefault();
    const image = link.querySelector("img");
    openLightbox(link.href, image ? image.alt : "");
  });
})();
