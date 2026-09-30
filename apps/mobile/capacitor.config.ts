import type { CapacitorConfig } from '@capacitor/cli';

/**
 * The native shell.
 *
 * The bundle id is the one the live app already ships under, so the store
 * listing, its reviews and the forced-update gate carry over rather than every
 * user having to find and install something new.
 *
 * That holds on Google Play, where the live app is myfiesta.os.ca. The live
 * iPhone app is listed under com.myfiestaos.myfiesta — Apple's own lookup
 * says so — and an App Store update has to carry the bundle id of the app it
 * updates. Which one the iOS project uses is the operator's decision, and
 * docs/STORE.md is where it is waiting to be made.
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
    /*
     * The launch screen stays up until the app's first screen has rendered,
     * rather than for a guessed half second: taken down early it shows the
     * WebView's empty ground, and kept up late it is a phone that looks hung.
     * core/launch-screen.ts takes it down then, and after four seconds
     * whatever happened.
     *
     * The plugin's own timer stays on as well, set later than that, for the
     * start where none of the app's code runs at all — a WebView too old to
     * read the bundle. Everything in launch-screen.ts is that code, so
     * without the timer that start is a splash that never leaves, over a
     * screen that takes no touches. When the app has taken the splash down
     * already, the timer finds nothing left to do.
     *
     * What it shows is native: res/values/styles.xml on Android, and
     * LaunchScreen.storyboard on iPhone, which the plugin lays over the
     * WebView so the hand-over from the operating system's launch screen to
     * the plugin's is the same picture.
     */
    SplashScreen: {
      launchAutoHide: true,
      // The backstop, not a wait: longer than launch-screen.ts's own limit
      // (LAUNCH_SCREEN_LIMIT_MS), so on a start where the app runs at all,
      // the app's first screen or its limit gets there first.
      launchShowDuration: 6000,
      launchFadeOutDuration: 200,
      backgroundColor: '#0b0f0c',
      showSpinner: false,
    },
    Keyboard: {
      // The app moves its own content; the WebView resizing under it fights
      // the bottom sheets.
      resize: 'none' as never,
    },
  },
};

export default config;
