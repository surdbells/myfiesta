/**
 * When a code works, said from its own From and Until.
 *
 * The question before a code is made or saved is where an organizer decides
 * when to post it. A presale code that opens on Friday, described as working
 * "as soon as it is made", gets posted today and refused at checkout until
 * Friday — so the sentence is built from the window the same form sets, and
 * "straight away" is said only when it is true.
 *
 * Shared so the console and the phone app ask the same question in the same
 * words; each writes the moments its own way (`format`), in the event's zone.
 */
export interface CodeWindow {
  /** Instants (ISO 8601), or null when the form leaves them empty. */
  startsAt: string | null;
  endsAt: string | null;
  /** A moment as the screen writes it, in the event's own zone. */
  format: (iso: string) => string;
  /** A batch: "they", and handed to people rather than simply had. */
  plural?: boolean;
  /** Saving changes to a code that already exists, rather than making one. */
  editing?: boolean;
  now?: Date;
}

export function whenCodeWorks(window: CodeWindow): string {
  const now = (window.now ?? new Date()).getTime();
  const at = (iso: string) => new Date(iso).getTime();
  const subject = window.plural ? 'They' : 'It';

  // An Until already behind it is a code that never works. Saying "until"
  // a moment that has passed would read as a promise.
  if (window.endsAt && at(window.endsAt) <= now) {
    return `${subject} will not work: the Until time, ${window.format(window.endsAt)}, has already passed.`;
  }

  const from =
    window.startsAt && at(window.startsAt) > now
      ? `from ${window.format(window.startsAt)}`
      : window.editing
        ? 'straight away'
        : window.plural
          ? 'as soon as they are made'
          : 'as soon as it is made';

  // A batch's second line already says the unused ones can be turned off.
  const until = window.endsAt
    ? `, until ${window.format(window.endsAt)}`
    : window.plural
      ? ''
      : ', until you turn it off';

  const who = window.plural ? 'for anybody you give them to' : 'for anybody who has it';

  return `${subject} ${window.plural ? 'work' : 'works'} ${from}${until}, ${who}.`;
}
