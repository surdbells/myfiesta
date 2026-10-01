import { COMMON_ZONES, describeZone } from '@myfiesta/shared/zoned-time';
import type { MfOption } from '../../ui';

/**
 * The zones the phone knows, by their IANA names.
 *
 * Intl.supportedValuesOf is missing on WebViews older than iOS 15.4 and
 * Chrome 99. The zones the event form offers stand in there: a short list
 * beats none.
 */
function knownZones(): string[] {
  try {
    const zones = Intl.supportedValuesOf('timeZone');

    if (zones.length > 0) return zones;
  } catch {
    // An older WebView.
  }

  return [...COMMON_ZONES];
}

/**
 * The choices for "Time zone" in Your details.
 *
 * Said as the event form says them — "Vancouver — PDT" — with the IANA name
 * beneath, so a search for "toronto" or "america" finds it. First of all,
 * "This phone's zone": null on the server, which is what an account that
 * never chose has, and which follows the phone when it travels.
 *
 * The zone already saved is always offered, even when this phone's list does
 * not have it (some leave out "UTC"), so opening the form never loses it.
 */
export function zoneOptions(device: string, saved: string | null, now = new Date()): MfOption[] {
  const zones = new Set(knownZones());

  if (saved) zones.add(saved);
  zones.add(device);

  const options = [...zones]
    .map((zone) => ({ value: zone, label: describeZone(zone, now), hint: zone }))
    .sort((a, b) => a.label.localeCompare(b.label));

  return [{ value: '', label: 'This phone’s zone', hint: `${describeZone(device, now)}, wherever the phone goes` }, ...options];
}
