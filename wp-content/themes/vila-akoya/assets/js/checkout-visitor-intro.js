/**
 * Block checkout: WC additional fields only support type "text". Upgrade to a textarea for the visitor intro.
 *
 * Do not dispatch bubbling input/change on mount. Checkout recaptcha plugins listen on document and call
 * grecaptcha.execute before the API is ready, which throws and can remount the checkout (refresh loop).
 */
(function () {
  'use strict';

  function findIntroInput() {
    return (
      document.querySelector('#contact-vila-akoya-visitor-intro') ||
      document.querySelector('input[id*="visitor-intro"]')
    );
  }

  function writeValue(input, value) {
    var desc = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
    if (desc && desc.set) {
      desc.set.call(input, value);
    } else {
      input.value = value;
    }
  }

  function syncToInput(textarea, input) {
    writeValue(input, textarea.value);
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function enhance() {
    var input = findIntroInput();
    if (!input || input.dataset.vilaIntroEnhanced === '1') {
      return;
    }

    var wrap = input.closest('.wc-block-components-text-input, .wc-block-components-form-group');
    if (!wrap) {
      return;
    }
    if (wrap.querySelector('.vila-checkout-visitor-intro__textarea')) {
      input.dataset.vilaIntroEnhanced = '1';
      return;
    }

    var floatingLabel = wrap.querySelector('label');
    var labelText = floatingLabel ? floatingLabel.textContent.replace(/\s*\(optional\)\s*/gi, '').trim() : '';
    var placeholder = input.getAttribute('data-vila-placeholder') || '';

    var textarea = document.createElement('textarea');
    textarea.id = input.id ? input.id + '-textarea' : 'vila-visitor-intro-textarea';
    textarea.className =
      'vila-checkout-visitor-intro__textarea wc-block-components-textarea w-full min-h-[7rem] rounded-md border border-outline-variant/30 px-3 py-2 font-body text-base';
    textarea.rows = 5;
    textarea.maxLength = 2000;
    textarea.placeholder = placeholder;
    textarea.value = input.value || '';
    textarea.setAttribute(
      'aria-label',
      labelText || input.getAttribute('aria-label') || 'About your group'
    );

    wrap.classList.add('vila-checkout-visitor-intro--enhanced');
    if (floatingLabel) {
      floatingLabel.hidden = true;
      floatingLabel.setAttribute('aria-hidden', 'true');
    }
    input.removeAttribute('placeholder');

    input.type = 'hidden';
    input.dataset.vilaIntroEnhanced = '1';
    input.setAttribute('aria-hidden', 'true');
    input.tabIndex = -1;

    wrap.appendChild(textarea);
    textarea.addEventListener('input', function () {
      syncToInput(textarea, input);
    });
  }

  function boot() {
    enhance();
    var root = document.querySelector('.wc-block-checkout');
    if (!root || typeof MutationObserver === 'undefined') {
      return;
    }
    var timer;
    new MutationObserver(function () {
      clearTimeout(timer);
      timer = setTimeout(enhance, 80);
    }).observe(root, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
