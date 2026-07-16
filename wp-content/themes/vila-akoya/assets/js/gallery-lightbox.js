(() => {
  const GALLERY_SELECTOR = ".va-campsite-gallery";
  const ITEM_SELECTOR = ".va-campsite-gallery__item, .va-campsite-gallery a[href]";

  let lightbox;
  let lightboxImage;
  let lightboxCaption;
  let lightboxCounter;
  let btnPrev;
  let btnNext;
  let items = [];
  let currentIndex = 0;
  let touchStartX = 0;

  function buildLightbox() {
    if (lightbox) return;

    lightbox = document.createElement("div");
    lightbox.className = "va-gallery-lightbox hidden";
    lightbox.setAttribute("aria-hidden", "true");
    lightbox.setAttribute("role", "dialog");
    lightbox.setAttribute("aria-modal", "true");
    lightbox.setAttribute("aria-label", "Gallery image viewer");
    lightbox.innerHTML =
      '<button type="button" class="va-gallery-lightbox__backdrop" aria-label="Close gallery viewer"></button>' +
      '<button type="button" class="va-gallery-lightbox__close" aria-label="Close gallery viewer">' +
      '<span aria-hidden="true">&times;</span>' +
      "</button>" +
      '<button type="button" class="va-gallery-lightbox__nav va-gallery-lightbox__nav--prev" aria-label="Previous image">&#8249;</button>' +
      '<button type="button" class="va-gallery-lightbox__nav va-gallery-lightbox__nav--next" aria-label="Next image">&#8250;</button>' +
      '<div class="va-gallery-lightbox__shell">' +
      '<div class="va-gallery-lightbox__content">' +
      '<img class="va-gallery-lightbox__image" alt="" />' +
      '<div class="va-gallery-lightbox__meta">' +
      '<p class="va-gallery-lightbox__caption"></p>' +
      '<p class="va-gallery-lightbox__counter" aria-live="polite"></p>' +
      "</div>" +
      "</div>" +
      "</div>";

    document.body.appendChild(lightbox);

    lightboxImage = lightbox.querySelector(".va-gallery-lightbox__image");
    lightboxCaption = lightbox.querySelector(".va-gallery-lightbox__caption");
    lightboxCounter = lightbox.querySelector(".va-gallery-lightbox__counter");
    btnPrev = lightbox.querySelector(".va-gallery-lightbox__nav--prev");
    btnNext = lightbox.querySelector(".va-gallery-lightbox__nav--next");
    const btnClose = lightbox.querySelector(".va-gallery-lightbox__close");
    const backdrop = lightbox.querySelector(".va-gallery-lightbox__backdrop");

    btnClose.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();
      closeLightbox();
    });

    backdrop.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();
      closeLightbox();
    });

    btnPrev.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();
      showIndex(currentIndex - 1);
    });

    btnNext.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();
      showIndex(currentIndex + 1);
    });

    lightbox.addEventListener(
      "touchstart",
      (event) => {
        if (!event.changedTouches || !event.changedTouches.length) return;
        touchStartX = event.changedTouches[0].screenX;
      },
      { passive: true }
    );

    lightbox.addEventListener(
      "touchend",
      (event) => {
        if (!event.changedTouches || !event.changedTouches.length) return;
        const delta = event.changedTouches[0].screenX - touchStartX;
        if (Math.abs(delta) < 50) return;
        if (delta > 0) showIndex(currentIndex - 1);
        else showIndex(currentIndex + 1);
      },
      { passive: true }
    );
  }

  function collectItems(gallery) {
    return Array.from(gallery.querySelectorAll(ITEM_SELECTOR))
      .map((el) => {
        const img = el.querySelector("img");
        const src =
          el.getAttribute("data-gallery-src") ||
          el.getAttribute("href") ||
          (img && (img.currentSrc || img.src)) ||
          "";
        const alt =
          el.getAttribute("data-gallery-alt") || (img ? img.alt : "") || "";
        return src ? { src, alt, el } : null;
      })
      .filter(Boolean);
  }

  function showIndex(index) {
    if (!items.length) return;
    currentIndex = (index + items.length) % items.length;
    const item = items[currentIndex];

    lightboxImage.src = item.src;
    lightboxImage.alt = item.alt || "";
    lightboxCaption.textContent = item.alt || "";
    lightboxCaption.hidden = !item.alt;
    lightboxCounter.textContent = `${currentIndex + 1} / ${items.length}`;

    const multi = items.length > 1;
    btnPrev.hidden = !multi;
    btnNext.hidden = !multi;
    lightboxCounter.hidden = !multi;
  }

  function openLightbox(gallery, startEl) {
    buildLightbox();
    items = collectItems(gallery);
    if (!items.length) return;

    const startIndex = items.findIndex((item) => item.el === startEl);
    currentIndex = startIndex >= 0 ? startIndex : 0;

    showIndex(currentIndex);
    lightbox.classList.remove("hidden");
    lightbox.setAttribute("aria-hidden", "false");
    document.body.classList.add("va-gallery-lightbox-open");

    const focusTarget =
      items.length > 1
        ? btnNext
        : lightbox.querySelector(".va-gallery-lightbox__close");
    if (focusTarget && !focusTarget.hidden) {
      focusTarget.focus({ preventScroll: true });
    }
  }

  function closeLightbox() {
    if (!lightbox) return;
    lightbox.classList.add("hidden");
    lightbox.setAttribute("aria-hidden", "true");
    document.body.classList.remove("va-gallery-lightbox-open");
    items = [];
  }

  function isOpen() {
    return lightbox && !lightbox.classList.contains("hidden");
  }

  document.addEventListener("keydown", (event) => {
    if (!isOpen()) return;
    if (event.key === "Escape") {
      closeLightbox();
      return;
    }
    if (event.key === "ArrowLeft") {
      event.preventDefault();
      showIndex(currentIndex - 1);
    } else if (event.key === "ArrowRight") {
      event.preventDefault();
      showIndex(currentIndex + 1);
    }
  });

  document.addEventListener("click", (event) => {
    const item = event.target.closest(ITEM_SELECTOR);
    if (!item) return;
    const gallery = item.closest(GALLERY_SELECTOR);
    if (!gallery) return;

    event.preventDefault();
    openLightbox(gallery, item);
  });
})();
