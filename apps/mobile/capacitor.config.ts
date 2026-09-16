import type { CapacitorConfig } from '@capacitor/cli';

/**
 * The native shell.
 *
 * The bundle id is the one the live app already ships under, so the store
 * listing, its reviews and the forced-update gate carry over rather than every
 * user having to find and install something new.
 *
 * `server.androidScheme` stays https: a WebView on http is treated as insecure,
 * which costs the app crypto.subtle and IndexedDB — the two things the door's
 * offline list needs.
 */
const config: CapacitorConfig = {
  appId: 'myfiesta.os.ca',
  appName: 'myFiesta',
  webDir: 'dist/mobile/browser',
  server: {
    androidScheme: 'https',
  },
  android: {
    // The scanner is the reason: a white flash between splash and camera at a
    // dark door is somebody's eyes adjusting for ten seconds.
    backgroundColor: '#0b0f0c',
  },
  ios: {
    backgroundColor: '#0b0f0c',
    contentInset: 'never',
  },
  plugins: {
    Keyboard: {
      // The app moves its own content; the WebView resizing under it fights
      // the bottom sheets.
      resize: 'none' as never,
    },
  },
};

export default config;
