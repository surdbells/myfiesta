/*
 * The theme, before the first paint.
 *
 * Somebody who chose dark would otherwise see the page light until Angular
 * started and set it. Kept tiny, synchronous and external (the Content
 * Security Policy allows no inline script). ThemeStore in packages/ui/src/theme.ts
 * reads and writes the same key and takes over once the app is running.
 */
(function () {
  try {
    var mode = localStorage.getItem('myfiesta.theme');
    if (mode === 'light' || mode === 'dark') {
      document.documentElement.setAttribute('data-theme', mode);
    }
  } catch (e) {
    // No storage: follow the device.
  }
})();
