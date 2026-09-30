const { readFileSync, writeFileSync, mkdirSync } = require('node:fs');
const { join, dirname, relative } = require('node:path');
const zlib = require('node:zlib');

/**
 * The phone app's icons and launch image, drawn from the brand mark.
 *
 *   npm run assets --workspace mobile
 *
 * Every size either store asks for comes out of `brand/mark-source.png`, the
 * 3200px master, so nothing here is a resize of a resize. Run it again after
 * the mark changes and commit what it writes; the files are release assets,
 * not build output, and a build never runs this.
 *
 * Why this and not `@capacitor/assets`, which does the same job: that tool
 * pins `@capacitor/cli` 5 and `sharp` 0.32, and installing it into this
 * workspace brought a critical advisory (node-tar, through the old CLI) and a
 * high one with no fix (libvips, through sharp) into a lockfile every app
 * shares. For drawing a picture onto a coloured square that is a poor trade,
 * so this reads and writes PNG with nothing but Node.
 *
 * What it draws:
 *
 * - The microphone alone. The master runs the cable off its right edge, which
 *   is a composition for a page; in a square the size of a fingertip the
 *   cable is a stray line and the mic is what reads. Everything above the gap
 *   between the handle and the plug is the mic, found from the pixels rather
 *   than written in as coordinates.
 * - Icons on white, the ground the brand is shown on. iOS icons carry no
 *   alpha channel at all, because App Store Connect rejects one that does,
 *   even when every pixel is opaque.
 * - Android's adaptive foreground with the mark inside the 66dp circle every
 *   launcher mask keeps, so no phone crops the flash off.
 * - The 512px icon the Play listing asks for, into resources/store/, since
 *   it is uploaded by hand rather than built into the app.
 * - The iPhone launch image on #0b0f0c, the colour the WebView paints before
 *   the app does (capacitor.config.ts).
 * - Android's launch icon: the same mic, on nothing. Android has no launch
 *   image — since Android 12 the system draws the splash itself from two
 *   values in the theme, a colour and an icon, and the compat library draws
 *   the same on older phones — so the ground is res/values/styles.xml's
 *   #0b0f0c and the picture is only the mark. Both phones open on the same
 *   thing: the mic on the app's dark ground.
 */

const MOBILE = join(__dirname, '..');
const ROOT = join(MOBILE, '../..');
const SOURCE = join(ROOT, 'brand/mark-source.png');
const ANDROID_RES = join(MOBILE, 'android/app/src/main/res');
const IOS_ASSETS = join(MOBILE, 'ios/App/App/Assets.xcassets');

/** The icon's ground. res/values/ic_launcher_background.xml says the same. */
const ICON_GROUND = '#ffffff';

/** Behind the launch image. capacitor.config.ts and styles.xml say the same. */
const SPLASH_GROUND = '#0b0f0c';

// --- PNG in -----------------------------------------------------------------

/**
 * Reads an 8-bit, non-interlaced RGB or RGBA PNG into premultiplied floats.
 * That is every PNG this is ever given; anything else is refused by name
 * rather than drawn wrong.
 */
function readPng(file) {
  const buffer = readFileSync(file);
  const chunks = [];
  let header = null;

  for (let at = 8; at < buffer.length; ) {
    const length = buffer.readUInt32BE(at);
    const type = buffer.toString('ascii', at + 4, at + 8);
    const data = buffer.subarray(at + 8, at + 8 + length);

    if (type === 'IHDR') {
      header = { width: data.readUInt32BE(0), height: data.readUInt32BE(4), depth: data[8], color: data[9], interlace: data[12] };
    }

    if (type === 'IDAT') chunks.push(data);

    at += 12 + length;
  }

  if (!header || header.depth !== 8 || ![2, 6].includes(header.color) || header.interlace !== 0) {
    throw new Error(`${relative(ROOT, file)}: expected an 8-bit, non-interlaced RGB or RGBA PNG`);
  }

  const { width, height } = header;
  const channels = header.color === 6 ? 4 : 3;
  const stride = width * channels;
  const raw = zlib.inflateSync(Buffer.concat(chunks));
  const bytes = Buffer.alloc(stride * height);
  let previous = Buffer.alloc(stride);

  for (let y = 0; y < height; y++) {
    const filter = raw[y * (stride + 1)];
    const line = raw.subarray(y * (stride + 1) + 1, (y + 1) * (stride + 1));
    const row = bytes.subarray(y * stride, (y + 1) * stride);

    for (let i = 0; i < stride; i++) {
      const left = i >= channels ? row[i - channels] : 0;
      const up = previous[i];
      const corner = i >= channels ? previous[i - channels] : 0;

      row[i] = (line[i] + unfilter(filter, left, up, corner)) & 255;
    }

    previous = row;
  }

  const pixels = new Float32Array(width * height * 4);

  for (let p = 0; p < width * height; p++) {
    const alpha = channels === 4 ? bytes[p * 4 + 3] / 255 : 1;

    for (let c = 0; c < 3; c++) pixels[p * 4 + c] = (bytes[p * channels + c] / 255) * alpha;
    pixels[p * 4 + 3] = alpha;
  }

  return { width, height, pixels };
}

function unfilter(type, left, up, corner) {
  switch (type) {
    case 0:
      return 0;
    case 1:
      return left;
    case 2:
      return up;
    case 3:
      return (left + up) >> 1;
    case 4:
      return paeth(left, up, corner);
    default:
      throw new Error(`unknown PNG filter ${type}`);
  }
}

function paeth(left, up, corner) {
  const estimate = left + up - corner;
  const toLeft = Math.abs(estimate - left);
  const toUp = Math.abs(estimate - up);
  const toCorner = Math.abs(estimate - corner);

  if (toLeft <= toUp && toLeft <= toCorner) return left;

  return toUp <= toCorner ? up : corner;
}

// --- PNG out ----------------------------------------------------------------

const CRC_TABLE = Array.from({ length: 256 }, (_, n) => {
  let c = n;
  for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
  return c >>> 0;
});

function crc32(buffer) {
  let c = 0xffffffff;
  for (const byte of buffer) c = CRC_TABLE[(c ^ byte) & 255] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}

function chunk(type, data) {
  const out = Buffer.alloc(12 + data.length);

  out.writeUInt32BE(data.length, 0);
  out.write(type, 4, 'ascii');
  data.copy(out, 8);
  out.writeUInt32BE(crc32(out.subarray(4, 8 + data.length)), 8 + data.length);

  return out;
}

/**
 * Writes an image as PNG: RGB when `opaque`, RGBA otherwise.
 *
 * Deterministic — the same mark gives the same bytes — so running this with
 * nothing changed leaves git with nothing to show.
 */
function writePng(file, image, { opaque }) {
  const { width, height, pixels } = image;
  const channels = opaque ? 3 : 4;
  const stride = width * channels;
  const bytes = Buffer.alloc(stride * height);

  for (let p = 0; p < width * height; p++) {
    const alpha = pixels[p * 4 + 3];

    for (let c = 0; c < 3; c++) {
      const value = opaque ? pixels[p * 4 + c] : alpha > 0 ? pixels[p * 4 + c] / alpha : 0;
      bytes[p * channels + c] = Math.round(Math.min(1, Math.max(0, value)) * 255);
    }

    if (!opaque) bytes[p * 4 + 3] = Math.round(alpha * 255);
  }

  // Each row gets whichever filter leaves it smallest: the usual heuristic,
  // and the difference between a 40KB launch image and a 400KB one.
  const filtered = Buffer.alloc((stride + 1) * height);
  let previous = Buffer.alloc(stride);

  for (let y = 0; y < height; y++) {
    const row = bytes.subarray(y * stride, (y + 1) * stride);
    let best = null;

    for (let type = 0; type <= 4; type++) {
      const line = Buffer.alloc(stride);
      let cost = 0;

      for (let i = 0; i < stride; i++) {
        const left = i >= channels ? row[i - channels] : 0;
        const corner = i >= channels ? previous[i - channels] : 0;

        line[i] = (row[i] - unfilter(type, left, previous[i], corner)) & 255;
        cost += line[i] < 128 ? line[i] : 256 - line[i];
      }

      if (!best || cost < best.cost) best = { type, line, cost };
    }

    filtered[y * (stride + 1)] = best.type;
    best.line.copy(filtered, y * (stride + 1) + 1);
    previous = row;
  }

  const header = Buffer.alloc(13);
  header.writeUInt32BE(width, 0);
  header.writeUInt32BE(height, 4);
  header[8] = 8;
  header[9] = opaque ? 2 : 6;

  mkdirSync(dirname(file), { recursive: true });
  writeFileSync(
    file,
    Buffer.concat([
      Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]),
      chunk('IHDR', header),
      chunk('IDAT', zlib.deflateSync(filtered, { level: 9 })),
      chunk('IEND', Buffer.alloc(0)),
    ]),
  );

  console.log(`  ${relative(MOBILE, file).replace(/\\/g, '/')}  ${width}x${height}${opaque ? '' : ' (alpha)'}`);
}

// --- drawing ----------------------------------------------------------------

function blank(width, height, colour = null) {
  const pixels = new Float32Array(width * height * 4);

  if (colour) {
    const [r, g, b] = rgb(colour);
    for (let p = 0; p < width * height; p++) pixels.set([r, g, b, 1], p * 4);
  }

  return { width, height, pixels };
}

function rgb(hex) {
  const value = parseInt(hex.slice(1), 16);

  return [(value >> 16) / 255, ((value >> 8) & 255) / 255, (value & 255) / 255];
}

function crop(image, x0, y0, x1, y1) {
  const width = x1 - x0;
  const height = y1 - y0;
  const out = blank(width, height);

  for (let y = 0; y < height; y++) {
    const from = ((y0 + y) * image.width + x0) * 4;
    out.pixels.set(image.pixels.subarray(from, from + width * 4), y * width * 4);
  }

  return out;
}

/**
 * Area-average downscale: each new pixel is the mean of exactly the source it
 * covers. Every size drawn here is smaller than the master, and for shrinking
 * this is as good as resampling gets, with no ringing around the flash.
 */
function resize(image, width, height) {
  const across = resample(image.pixels, image.width, image.height, width, true);

  return { width, height, pixels: resample(across, width, image.height, height, false) };
}

function resample(pixels, width, height, size, horizontal) {
  const from = horizontal ? width : height;
  const scale = from / size;
  const out = new Float32Array((horizontal ? size * height : width * size) * 4);
  const lines = horizontal ? height : width;

  for (let n = 0; n < size; n++) {
    const start = n * scale;
    const end = start + scale;
    const taps = [];

    for (let i = Math.floor(start); i < Math.min(Math.ceil(end), from); i++) {
      taps.push([i, (Math.min(i + 1, end) - Math.max(i, start)) / scale]);
    }

    for (let line = 0; line < lines; line++) {
      const target = horizontal ? (line * size + n) * 4 : (n * width + line) * 4;

      for (const [i, weight] of taps) {
        const source = horizontal ? (line * width + i) * 4 : (i * width + line) * 4;
        for (let c = 0; c < 4; c++) out[target + c] += pixels[source + c] * weight;
      }
    }
  }

  return out;
}

/** Lays `top` over `base` with its top-left corner at (x, y). */
function over(base, top, x, y) {
  for (let row = 0; row < top.height; row++) {
    for (let col = 0; col < top.width; col++) {
      const bx = x + col;
      const by = y + row;
      if (bx < 0 || by < 0 || bx >= base.width || by >= base.height) continue;

      const t = (row * top.width + col) * 4;
      const b = (by * base.width + bx) * 4;
      const keep = 1 - top.pixels[t + 3];

      for (let c = 0; c < 4; c++) base.pixels[b + c] = top.pixels[t + c] + base.pixels[b + c] * keep;
    }
  }

  return base;
}

/**
 * A filled shape, antialiased by sampling each pixel sixteen times. Only the
 * legacy Android icons use it — every launcher since Android 8 cuts its own
 * shape out of the adaptive layers.
 */
function shape(size, colour, inside) {
  const out = blank(size, size);
  const [r, g, b] = rgb(colour);

  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      let hits = 0;

      for (let sy = 0; sy < 4; sy++) {
        for (let sx = 0; sx < 4; sx++) {
          if (inside((x + (sx + 0.5) / 4) / size, (y + (sy + 0.5) / 4) / size)) hits++;
        }
      }

      const alpha = hits / 16;
      out.pixels.set([r * alpha, g * alpha, b * alpha, alpha], (y * size + x) * 4);
    }
  }

  return out;
}

/** The mark scaled to `height` pixels tall and centred on `canvas`. */
function place(canvas, mark, height) {
  const width = Math.max(1, Math.round((mark.width / mark.height) * height));
  const scaled = resize(mark, width, height);

  return over(canvas, scaled, Math.round((canvas.width - width) / 2), Math.round((canvas.height - height) / 2));
}

// --- the mark ---------------------------------------------------------------

/**
 * The microphone out of the master: everything above the last gap before the
 * cable, trimmed to what is actually drawn.
 */
function microphone() {
  const source = readPng(SOURCE);
  const { width, height, pixels } = source;
  const drawn = (x, y) => pixels[(y * width + x) * 4 + 3] > 0;
  const rowDrawn = (y) => {
    for (let x = 0; x < width; x++) if (drawn(x, y)) return true;
    return false;
  };

  // Rows with nothing on them, as runs. The last run that has drawing on both
  // sides of it is the gap between the handle and the plug.
  const rows = Array.from({ length: height }, (_, y) => rowDrawn(y));
  const first = rows.indexOf(true);
  const last = rows.lastIndexOf(true);
  let cut = -1;

  for (let y = first; y < last; y++) {
    if (rows[y] && !rows[y + 1]) cut = y + 1;
  }

  if (cut < 0) throw new Error('brand/mark-source.png: could not find where the microphone ends and the cable begins');

  let left = width;
  let right = 0;

  for (let y = first; y < cut; y++) {
    for (let x = 0; x < width; x++) {
      if (!drawn(x, y)) continue;
      left = Math.min(left, x);
      right = Math.max(right, x);
    }
  }

  return crop(source, left, first, right + 1, cut);
}

// --- what gets written --------------------------------------------------------

const ANDROID_DENSITIES = [
  ['mdpi', 1],
  ['hdpi', 1.5],
  ['xhdpi', 2],
  ['xxhdpi', 3],
  ['xxxhdpi', 4],
];

/**
 * Every iOS icon slot, iPhone and iPad (the project targets both), plus the
 * 1024 the App Store shows. Size in points, then scale.
 */
const IOS_ICONS = [
  ['iphone', 20, 2],
  ['iphone', 20, 3],
  ['iphone', 29, 2],
  ['iphone', 29, 3],
  ['iphone', 40, 2],
  ['iphone', 40, 3],
  ['iphone', 60, 2],
  ['iphone', 60, 3],
  ['ipad', 20, 1],
  ['ipad', 20, 2],
  ['ipad', 29, 1],
  ['ipad', 29, 2],
  ['ipad', 40, 1],
  ['ipad', 40, 2],
  ['ipad', 76, 1],
  ['ipad', 76, 2],
  ['ipad', 83.5, 2],
  ['ios-marketing', 1024, 1],
];

function android(mark) {
  console.log('android');

  for (const [density, scale] of ANDROID_DENSITIES) {
    const dir = join(ANDROID_RES, `mipmap-${density}`);

    // The adaptive foreground: 108dp, of which launchers show the middle 72
    // and promise only the middle 66 as a circle. Half the layer's height
    // keeps the mic's corners inside that circle with room to spare.
    const layer = Math.round(108 * scale);
    writePng(join(dir, 'ic_launcher_foreground.png'), place(blank(layer, layer), mark, Math.round(layer * 0.5)), {
      opaque: false,
    });

    // Android 7 and older: a finished icon, 48dp, with the 2dp margin the
    // old launcher grid expects around a 44dp shape.
    const legacy = Math.round(48 * scale);
    const square = shape(legacy, ICON_GROUND, (x, y) => roundedSquare(x, y, 2 / 48, 0.2));
    const round = shape(legacy, ICON_GROUND, (x, y) => (x - 0.5) ** 2 + (y - 0.5) ** 2 <= (22 / 48) ** 2);

    writePng(join(dir, 'ic_launcher.png'), place(square, mark, Math.round(legacy * 0.6)), { opaque: false });
    writePng(join(dir, 'ic_launcher_round.png'), place(round, mark, Math.round(legacy * 0.6)), { opaque: false });
  }

  // The icon on the Play listing, uploaded by hand in the Play Console rather
  // than built into the app: 512px, a full square, Play rounds it itself.
  writePng(join(__dirname, 'store/play-icon-512.png'), place(blank(512, 512, ICON_GROUND), mark, Math.round(512 * 0.62)), {
    opaque: true,
  });

  // The launch icon (styles.xml, windowSplashScreenAnimatedIcon). A 288dp
  // square of which the system shows only the middle 192dp circle, cropping
  // whatever falls outside it; the mark is sized from its own proportions so
  // its corners sit well inside that circle. Transparent around it, because
  // the ground is the theme's colour rather than part of the picture — one
  // #0b0f0c, not two that could drift apart.
  const reach = SPLASH_CIRCLE * 0.84;
  const tall = reach / Math.hypot(1, mark.width / mark.height);

  for (const [density, scale] of ANDROID_DENSITIES) {
    const side = Math.round(288 * scale);

    writePng(join(ANDROID_RES, `drawable-${density}`, 'splash_icon.png'), place(blank(side, side), mark, Math.round(tall * scale)), {
      opaque: false,
    });
  }
}

/** The circle, in dp, that Android 12 shows of a 288dp launch icon with no icon background. */
const SPLASH_CIRCLE = 192;

/** Inside a square inset by `margin` on each side, corners rounded by `radius` of its side. */
function roundedSquare(x, y, margin, radius) {
  const side = 1 - 2 * margin;
  const r = side * radius;
  const dx = Math.max(Math.abs(x - 0.5) - (side / 2 - r), 0);
  const dy = Math.max(Math.abs(y - 0.5) - (side / 2 - r), 0);

  return Math.abs(x - 0.5) <= side / 2 && Math.abs(y - 0.5) <= side / 2 && dx * dx + dy * dy <= r * r;
}

function ios(mark) {
  console.log('ios');

  const dir = join(IOS_ASSETS, 'AppIcon.appiconset');
  const images = [];
  const drawn = new Map();

  for (const [idiom, points, scale] of IOS_ICONS) {
    const pixels = Math.round(points * scale);
    const filename = `AppIcon-${pixels}.png`;

    // Several slots share a pixel size (iPhone 40@2x and iPad 40@2x are both
    // 80px); one file serves all of them.
    if (!drawn.has(pixels)) {
      // iOS rounds the corners itself; the file is a full square. The mark
      // sits a little larger than on Android, whose masks cut deeper.
      writePng(join(dir, filename), place(blank(pixels, pixels, ICON_GROUND), mark, Math.round(pixels * 0.62)), {
        opaque: true,
      });
      drawn.set(pixels, filename);
    }

    images.push({ filename, idiom, scale: `${scale}x`, size: `${points}x${points}` });
  }

  writeJson(join(dir, 'Contents.json'), { images, info: { author: 'xcode', version: 1 } });

  // The launch image. The storyboard shows it aspect-fill, so on any screen it
  // is cropped to the middle — which is where the mark is, about 160pt tall on
  // a phone. Three identical files because the image set has three slots.
  const splash = place(blank(2732, 2732, SPLASH_GROUND), mark, 520);
  const set = join(IOS_ASSETS, 'Splash.imageset');

  for (const name of ['splash-2732x2732.png', 'splash-2732x2732-1.png', 'splash-2732x2732-2.png']) {
    writePng(join(set, name), splash, { opaque: true });
  }
}

function writeJson(file, value) {
  writeFileSync(file, JSON.stringify(value, null, 2) + '\n');
  console.log(`  ${relative(MOBILE, file).replace(/\\/g, '/')}`);
}

const mark = microphone();

console.log(`mark: ${mark.width}x${mark.height} from ${relative(ROOT, SOURCE).replace(/\\/g, '/')}`);

android(mark);
ios(mark);
