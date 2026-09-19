/*
 * myFiesta tickets, on your own site.
 *
 *   <script src="https://myfiesta.ca/embed.js" async></script>
 *
 * Tickets in the page, where the element is:
 *
 *   <div data-myfiesta-event="your-event"></div>
 *
 * Or a button that opens them over the page. It is an ordinary link to the
 * event, so it still works if this script never loads:
 *
 *   <a href="https://myfiesta.ca/your-event" data-myfiesta-event="your-event"
 *      data-myfiesta-mode="button">Buy tickets</a>
 *
 * When an order is paid the element fires a `myfiesta:paid` event that
 * bubbles, with { event, tickets } in its detail — for a thank-you, or a
 * conversion in your own analytics.
 *
 * Plain, dependency-free and old-browser-safe on purpose: it runs on pages we
 * do not control, next to scripts we have never seen.
 */
(function () {
  'use strict';

  var script = document.currentScript;
  if (!script || !script.src) return;

  var origin = new URL(script.src).origin;
  var frames = [];

  function frameFor(slug) {
    var frame = document.createElement('iframe');
    frame.src = origin + '/embed/' + encodeURIComponent(slug);
    frame.title = 'Tickets';
    frame.loading = 'lazy';
    frame.setAttribute('allow', 'clipboard-write');
    frame.style.cssText = 'display:block;width:100%;min-height:480px;border:0;background:transparent;';

    return frame;
  }

  function inline(el, slug) {
    var frame = frameFor(slug);
    frames.push({ frame: frame, el: el });
    el.innerHTML = '';
    el.appendChild(frame);
  }

  function button(el, slug) {
    el.addEventListener('click', function (e) {
      // New tab, middle click, a modifier: the buyer asked for the link.
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      e.preventDefault();
      open(el, slug);
    });
  }

  function open(el, slug) {
    var returnFocus = document.activeElement;
    var overflow = document.documentElement.style.overflow;

    var backdrop = document.createElement('div');
    backdrop.style.cssText =
      'position:fixed;inset:0;z-index:2147483646;background:rgba(15,15,20,.6);' +
      'display:flex;align-items:flex-start;justify-content:center;overflow-y:auto;padding:24px 12px;';

    var sheet = document.createElement('div');
    sheet.setAttribute('role', 'dialog');
    sheet.setAttribute('aria-modal', 'true');
    sheet.setAttribute('aria-label', 'Tickets');
    sheet.style.cssText =
      'position:relative;width:100%;max-width:1040px;border-radius:12px;overflow:hidden;' +
      'background:#fff;box-shadow:0 24px 64px rgba(0,0,0,.35);';

    var close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('aria-label', 'Close tickets');
    close.textContent = '×';
    close.style.cssText =
      'position:absolute;top:8px;right:8px;z-index:1;width:40px;height:40px;border:0;border-radius:999px;' +
      'background:rgba(0,0,0,.06);color:#111;font:400 26px/1 system-ui,sans-serif;cursor:pointer;';

    var frame = frameFor(slug);
    frame.loading = 'eager';
    var entry = { frame: frame, el: el };
    frames.push(entry);

    function dismiss() {
      document.removeEventListener('keydown', onKey, true);
      frames.splice(frames.indexOf(entry), 1);
      backdrop.parentNode && backdrop.parentNode.removeChild(backdrop);
      document.documentElement.style.overflow = overflow;
      if (returnFocus && returnFocus.focus) returnFocus.focus();
    }

    function onKey(e) {
      if (e.key === 'Escape') dismiss();
    }

    close.addEventListener('click', dismiss);
    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) dismiss();
    });
    document.addEventListener('keydown', onKey, true);

    sheet.appendChild(close);
    sheet.appendChild(frame);
    backdrop.appendChild(sheet);
    document.body.appendChild(backdrop);
    document.documentElement.style.overflow = 'hidden';
    close.focus();
  }

  // Only from our own frames, and only the two things they say.
  window.addEventListener('message', function (e) {
    if (e.origin !== origin || !e.data || e.data.source !== 'myfiesta') return;

    for (var i = 0; i < frames.length; i++) {
      if (frames[i].frame.contentWindow !== e.source) continue;

      if (e.data.type === 'resize' && typeof e.data.height === 'number') {
        frames[i].frame.style.height = Math.max(200, Math.min(e.data.height, 20000)) + 'px';
      } else if (e.data.type === 'paid') {
        var detail = { event: String(e.data.event || ''), tickets: Number(e.data.tickets) || 0 };
        var event;
        try {
          event = new CustomEvent('myfiesta:paid', { bubbles: true, detail: detail });
        } catch (err) {
          event = document.createEvent('CustomEvent');
          event.initCustomEvent('myfiesta:paid', true, false, detail);
        }
        frames[i].el.dispatchEvent(event);
      }
    }
  });

  function mount() {
    var found = document.querySelectorAll('[data-myfiesta-event]');

    for (var i = 0; i < found.length; i++) {
      var el = found[i];
      var slug = el.getAttribute('data-myfiesta-event');

      if (!slug || el.getAttribute('data-myfiesta-mounted')) continue;
      el.setAttribute('data-myfiesta-mounted', '1');

      if (el.getAttribute('data-myfiesta-mode') === 'button') button(el, slug);
      else inline(el, slug);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
  else mount();

  // For a page that adds the element after load.
  window.myFiesta = { mount: mount };
})();
