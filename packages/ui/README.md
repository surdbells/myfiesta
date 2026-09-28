# @myfiesta/ui

The components the site (`apps/web`) and the console (`apps/organizer-web`)
are built from. Consumed as source: each app compiles it against its own
Angular, which is why the package declares no dependencies. Colours, spacing
and type come from `packages/tokens` and nowhere else.

Its specs run with the console's: `apps/organizer-web/src/app/core/ui-*.spec.ts`.

## Asking before an action

Every action that changes something asks first, through one dialog: never
`window.confirm`, and never a modal of a screen's own. A screen awaits the
answer instead of keeping an open flag, a busy flag and an error for each
action. Nothing goes in a template — the service draws the dialog, one
question at a time, and gives focus back to whatever asked.

```ts
private readonly confirmDialog = inject(ConfirmDialog);

// Yes or no. With `run`, true only once the action has gone through.
const refunded = await this.confirmDialog.confirm({
  title: 'Refund this order?',
  body: 'Ada gets $40.00 back on the card she paid with.',
  consequences: ['Her two tickets stop working at the door.', 'The booking fee is not returned.'],
  confirmLabel: 'Refund $40.00',     // names the action, never "OK"
  busyLabel: 'Refunding…',
  tone: 'danger',                    // red button, and focus starts on Cancel
  run: () => this.api.refund(order.id),
  failure: (error) => messageFor(error, 'The refund did not go through.'),
});

// When a written reason has to come back.
const { confirmed, reason } = await this.confirmDialog.decide({
  title: 'Take this event off sale?',
  body: 'The page stays up, and nobody can buy until you put it back.',
  confirmLabel: 'Take it off sale',
  tone: 'danger',
  requireText: 'OFF SALE',           // type-to-confirm, for the irreversible only
  reason: { label: 'Why', required: true, minLength: 10, maxLength: 500 },
});
```

| Field | What it does |
| --- | --- |
| `title`, `body` | The question and what will actually happen. `body` is required: "are you sure?" is not a question. |
| `consequences` | The knock-on effects, one per line. Read out with the body. |
| `confirmLabel` | The action, named: "Submit for review", "Refund $40.00". It wraps at phone width rather than being cut short. |
| `cancelLabel` | Defaults to "Cancel". |
| `tone` | `'danger'` for anything that destroys or takes back: a red button, and focus starts on Cancel. |
| `requireText` | The button stays off until this is typed (case and spaces round it aside), and Enter in the box confirms. Only for the irreversible — friction everywhere trains people past it. |
| `reason` | A box to write in: `{ label, required?, minLength?, maxLength?, hint?, placeholder? }`. What was written comes back trimmed, from `decide()`, and is handed to `run`. |
| `run` | The action, as a promise or an observable. The dialog stays open and busy while it runs — Escape, the backdrop and Cancel do nothing — closes when it succeeds, and shows the failure inline when it does not, so the person can try again or cancel. |
| `failure` | Turns a failed `run` into words. Pass the app's own error reader; without one the dialog says "That did not work. Try again." and never the server's detail. |

What it does without being asked:

- It is a native `<dialog>` opened with `showModal()`, with `role="alertdialog"`,
  named by the title and described by the body and the consequences.
- Tab stays inside it. Escape and a click on the backdrop answer no.
- Focus starts on Cancel for `danger`; otherwise in the first box to fill, and
  failing that on the action.
- While `run` is working the action button is `aria-busy`, not `disabled`, so
  focus is not dropped onto the page.
- It animates in only when the reader has not asked for less motion.
- On the server it draws nothing and answers no: nothing is done without
  asking.

`<ui-confirm>` is the same dialog placed in a template by hand, with `open`,
`busy`, `error`, `(confirmed)` and `(cancelled)`, for a screen that already
owns the flags. New code uses the service.

The phone has the same call — `Dialogs.confirm()` in `apps/mobile/src/app/ui`,
drawn as a bottom sheet — so a screen reads the same on both.
