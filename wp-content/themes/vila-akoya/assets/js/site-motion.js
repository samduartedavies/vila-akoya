(function () {
  var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
  var revealSelector = ".vila-reveal, .vila-reveal-stagger > *";

  function ready(fn) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", fn, { once: true });
      return;
    }
    fn();
  }

  function setStaggerIndexes() {
    document.querySelectorAll(".vila-reveal-stagger").forEach(function (group) {
      Array.prototype.forEach.call(group.children, function (child, index) {
        child.style.setProperty("--vila-reveal-i", index);
      });
    });
  }

  function addClasses(element, classNames) {
    if (!element) {
      return;
    }
    classNames.split(" ").forEach(function (className) {
      if (className) {
        element.classList.add(className);
      }
    });
  }

  function markHeroItem(element, index) {
    if (!element) {
      return;
    }
    element.setAttribute("data-vila-hero-item", "");
    element.style.setProperty("--vila-hero-i", index);
  }

  function getDirectHomeSections(home) {
    return Array.prototype.filter.call(home.querySelectorAll("section.wp-block-group"), function (section) {
      return section.parentElement === home;
    });
  }

  function enhanceLegacyHomepageBlocks() {
    var home = document.querySelector(".vila-home");

    if (!home) {
      return;
    }

    var sections = getDirectHomeSections(home);
    var hero = sections[0];

    if (hero) {
      addClasses(hero, "vila-motion-hero");
      addClasses(hero.querySelector(".editorial-shadow"), "vila-motion-hero-frame");
      markHeroItem(hero.querySelector("h1"), 0);
      markHeroItem(hero.querySelector("p"), 1);
      markHeroItem(hero.querySelector(".wp-block-buttons"), 2);
    }

    Array.prototype.forEach.call(home.querySelectorAll(".vila-split-flush"), function (split) {
      addClasses(split, "vila-reveal-stagger vila-dappled-surface");
    });

    Array.prototype.forEach.call(home.querySelectorAll(".vila-columns-stretch > .wp-block-column"), function (column) {
      addClasses(column, "vila-reveal");
    });

    Array.prototype.forEach.call(home.querySelectorAll(".who-we-portrait"), function (portrait) {
      var row = portrait.closest(".wp-block-columns");
      addClasses(row, "vila-reveal");
    });

    Array.prototype.forEach.call(sections.slice(2), function (section) {
      addClasses(section, "vila-reveal");
    });
  }

  function revealImmediately() {
    document.querySelectorAll(revealSelector).forEach(function (element) {
      element.classList.add("is-in");
    });
    document.querySelectorAll(".vila-motion-hero").forEach(function (hero) {
      hero.classList.add("is-in");
    });
  }

  function initReveals() {
    var elements = document.querySelectorAll(revealSelector);

    if (!("IntersectionObserver" in window)) {
      elements.forEach(function (element) {
        element.classList.add("is-in");
      });
      return;
    }

    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) {
            return;
          }
          entry.target.classList.add("is-in");
          observer.unobserve(entry.target);
        });
      },
      {
        rootMargin: "0px 0px -8% 0px",
        threshold: 0.12,
      }
    );

    elements.forEach(function (element) {
      observer.observe(element);
    });
  }

  function initHeroes() {
    window.requestAnimationFrame(function () {
      document.querySelectorAll(".vila-motion-hero").forEach(function (hero) {
        hero.classList.add("is-in");
      });
    });
  }

  function initNavState() {
    var nav = document.querySelector(".vila-site-nav");

    if (!nav) {
      return;
    }

    function setNavState() {
      nav.setAttribute("data-vila-scrolled", window.scrollY > 18 ? "true" : "false");
    }

    setNavState();
    window.addEventListener("scroll", setNavState, { passive: true });
  }

  ready(function () {
    enhanceLegacyHomepageBlocks();
    setStaggerIndexes();

    if (reduceMotion.matches) {
      document.documentElement.classList.add("vila-reduced-motion");
      revealImmediately();
      initNavState();
      return;
    }

    document.documentElement.classList.add("vila-site-motion-ready");
    initReveals();
    initHeroes();
    initNavState();
  });
})();
