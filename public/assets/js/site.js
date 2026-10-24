'use strict';
(() => {
  const menu = document.querySelector('.menu-toggle');
  const nav = document.querySelector('#main-nav');
  if (menu && nav) {
    menu.hidden = false;
    document.documentElement.classList.add('js-menu');
    const closeMenu = (restoreFocus = false) => {
      nav.classList.remove('is-open');
      menu.setAttribute('aria-expanded', 'false');
      if (restoreFocus) menu.focus();
    };
    menu.addEventListener('click', () => {
      const open = menu.getAttribute('aria-expanded') !== 'true';
      menu.setAttribute('aria-expanded', String(open));
      nav.classList.toggle('is-open', open);
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && menu.getAttribute('aria-expanded') === 'true') closeMenu(true);
    });
    document.addEventListener('click', event => {
      if (!event.target.closest('.site-header')) closeMenu();
    });
    nav.addEventListener('click', event => { if (event.target.closest('a')) closeMenu(); });
    matchMedia('(min-width: 761px)').addEventListener('change', () => closeMenu());
  }
  const status = document.querySelector('#status-message');
  let toastTimer;
  function announce(message) {
    status.textContent = message;
    status.classList.add('is-visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => status.classList.remove('is-visible'), 5000);
  }
  document.querySelectorAll('[data-copy-email]').forEach(button => {
    button.hidden = false;
    button.addEventListener('click', async () => {
      try {
        if (!navigator.clipboard || !window.isSecureContext) throw new Error('Clipboard unavailable');
        await navigator.clipboard.writeText(button.dataset.copyEmail);
        announce('Email address copied.');
      } catch {
        const address = document.querySelector('.email-address, .email-link');
        if (address) {
          const range = document.createRange();
          range.selectNodeContents(address);
          const selection = window.getSelection();
          selection.removeAllRanges(); selection.addRange(range);
          address.focus();
        }
        announce('Copy was unavailable. Select the email address and copy it manually.');
      }
    });
  });
  document.querySelectorAll('[data-print]').forEach(button => {
    button.hidden = false;
    button.addEventListener('click', () => window.print());
  });
  // Scroll work only while the hero is visible; no continuous render loop.
  const art = document.querySelector('.wave-art');
  const hero = document.querySelector('.hero');
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  let heroVisible = false, pending = false;
  if (art && hero && 'IntersectionObserver' in window) {
    new IntersectionObserver(entries => { heroVisible = entries[0].isIntersecting; }).observe(hero);
    window.addEventListener('scroll', () => {
      if (reduced.matches || !heroVisible || pending) return;
      pending = true;
      requestAnimationFrame(() => {
        art.style.setProperty('--parallax', `${Math.min(window.scrollY * .035, 20)}px`);
        pending = false;
      });
    }, { passive: true });
    reduced.addEventListener('change', () => art.style.removeProperty('--parallax'));
  }
})();
