import {
  Briefcase,
  Drama,
  GraduationCap,
  Laugh,
  MicVocal,
  MonitorPlay,
  Moon,
  Music,
  PartyPopper,
  Sparkles,
  Tent,
  Trophy,
  Users,
  UtensilsCrossed,
  type LucideIconData,
} from 'lucide-angular';

/**
 * What a category looks like when nobody has given it a picture.
 *
 * A category card leads with a real poster from that category wherever one
 * exists (the API sends it). Where none does, it is drawn: one of a small set
 * of grounds from the brand's own ramps, and the category's mark large and
 * faint across it. A set, cycled by name rather than at random, so the same
 * category is the same colour on every visit and a row of them reads as
 * designed rather than as placeholders.
 *
 * Every colour is a token. The grounds use primitives on purpose — they are a
 * picture, painted the same in both themes the way a poster would be, with
 * white type on them that is checked against the darkest end of each.
 */
const GROUNDS = [
  // Deep brand green.
  'radial-gradient(120% 90% at 15% 0%, color-mix(in srgb, var(--color-brand-400) 70%, transparent), transparent 60%), linear-gradient(160deg, var(--color-brand-700), var(--color-brand-900))',
  // Gold into green.
  'radial-gradient(110% 90% at 85% 10%, color-mix(in srgb, var(--color-gold-300) 75%, transparent), transparent 55%), linear-gradient(150deg, var(--color-brand-600), var(--color-neutral-950))',
  // Night blue.
  'radial-gradient(120% 90% at 20% 10%, color-mix(in srgb, var(--color-semantic-info-light) 55%, transparent), transparent 60%), linear-gradient(160deg, var(--color-semantic-info), var(--color-neutral-950))',
  // Warm amber into red.
  'radial-gradient(110% 90% at 80% 0%, color-mix(in srgb, var(--color-semantic-warning-light) 80%, transparent), transparent 55%), linear-gradient(155deg, var(--color-semantic-warning), var(--color-semantic-danger) 60%, var(--color-neutral-950))',
  // Green light on black.
  'radial-gradient(90% 70% at 30% 20%, color-mix(in srgb, var(--color-brand-300) 55%, transparent), transparent 60%), linear-gradient(170deg, var(--color-neutral-800), var(--color-neutral-950))',
  // Gold into amber.
  'radial-gradient(100% 80% at 20% 0%, color-mix(in srgb, var(--color-gold-200) 80%, transparent), transparent 55%), linear-gradient(150deg, var(--color-gold-500), var(--color-semantic-warning) 55%, var(--color-neutral-900))',
];

const ART: Record<string, { icon: LucideIconData; ground: number }> = {
  Nightlife: { icon: Moon, ground: 2 },
  Party: { icon: PartyPopper, ground: 3 },
  Music: { icon: Music, ground: 0 },
  Concert: { icon: MicVocal, ground: 4 },
  Festival: { icon: Tent, ground: 5 },
  Comedy: { icon: Laugh, ground: 1 },
  'Performing arts': { icon: Drama, ground: 3 },
  'Food & drink': { icon: UtensilsCrossed, ground: 5 },
  'Community & culture': { icon: Users, ground: 0 },
  'Classes & workshops': { icon: GraduationCap, ground: 2 },
  'Networking & business': { icon: Briefcase, ground: 4 },
  Sports: { icon: Trophy, ground: 1 },
  Online: { icon: MonitorPlay, ground: 2 },
};

/** The same number for the same words, every time. */
function hash(text: string): number {
  let value = 0;

  for (const character of text) value = (value * 31 + character.charCodeAt(0)) >>> 0;

  return value;
}

/** The mark for a category, or a spark for one this list has not heard of. */
export function categoryIcon(category: string | null | undefined): LucideIconData {
  return (category && ART[category]?.icon) || Sparkles;
}

/** A painted ground for a category — or for any name, a city included. */
export function categoryGround(name: string | null | undefined): string {
  const known = name ? ART[name]?.ground : undefined;

  return GROUNDS[known ?? hash(name ?? '') % GROUNDS.length];
}
