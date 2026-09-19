# The public site

Angular 21 with SSR, Tailwind v4, and `@myfiesta/ui`. This is the sales channel,
not a brochure: organizers sell through links they share, so a page that unfurls
without a title, an image or a price reads as broken — which is why this app
runs a server at all.

## Running it

```bash
npm install                    # from the repository root: one lockfile
npm start --workspace web      # http://localhost:4320
```

The API address is read at runtime from `<meta name="api-base">` in
`src/index.html`. Empty in development, where it falls back to
`http://127.0.0.1:8000` — and that origin has to be in the API's
`CORS_ALLOWED_ORIGINS`.

## What it is

Browsing and buying, with no account anywhere. Guest checkout is the primary
path on this platform: most people holding a ticket have never signed in, and
the random token in the link emailed to them is the whole credential.

| Screen | Route | What it is |
| ------ | ----- | ---------- |
| What's on | `/` | featured and upcoming nights |
| Find something | `/events` | search, with city, category and price filters |
| An event | `/:slug` | the page a shared link opens: poster, when, where, tiers, gallery, organizer |
| An organizer | `/o/:slug` | who they are, what is on, what has been — the link a promoter puts in a bio |
| Tickets | `/:slug/tickets` | choosing tiers, anything sold beside them, and a code if there is one |
| Checkout | `/:slug/checkout` | who the tickets are for, anything the organizer asks, and the bill |
| An order | `/order/:reference` | what was bought, after paying |
| A ticket | `/tickets/:token` | the QR a guest shows at the door, no account needed |
| Find my tickets | `/tickets` | asking for the link again, by email |
| Framed | `/embed/:slug`, `/embed/:slug/checkout`, `/embed/order/:reference` | the same buying steps inside an organizer's own website |
| Help, terms, privacy, contact | `/help`, `/terms`, `/privacy`, `/contact` | the ordinary pages |

Event slugs sit at the root — `myfiesta.ca/{slug}` — because those links are in
bios, printed QR codes and shared messages, and cannot be edited. The organizer
paths (`/sign-in`, `/register`, `/login`) are matched *before* the event
wildcard and redirect to the console; getting that order wrong once told
intending organizers "Event not found", which `app.spec.ts` now guards. The API
refuses to give a new event any slug the site answers to itself
(`Event::RESERVED_SLUGS`); keep the two lists in step.

## On an organizer's own site

`public/embed.js` is the one script an organizer pastes into their website. It
turns `<div data-myfiesta-event="slug">` into the tickets, in the page, and
`<a … data-myfiesta-mode="button">` into a button that opens them over it —
still an ordinary link to the event if the script never loads. The console's
event overview hands out both snippets.

The frame shows the ordinary buying steps under `/embed/`, and `core/embed.ts`
changes what is around them: no site header or footer, the height reported to
the page outside so the frame fits, links that would leave the flow opened in a
new tab. Paying happens in a tab of its own — processors refuse to be framed —
opened on the buyer's click so it is not blocked as a popup, while the frame
waits on the order and says `paid` to the page around it when it lands.

Only `/embed/` may be framed, by anyone. Everything else sends
`frame-ancestors 'self'` (`src/framing.ts`), so nobody can lay our buttons
under theirs. `embed.js` is cached for an hour, not a year: its address is in
other people's HTML and a fix has to reach them.

## Rendering

Event pages and the listing are rendered per request, never prerendered: a
build-time snapshot means a link shared an hour after publishing unfurls as a
404. The static pages are prerendered.

`core/seo.ts` writes the meta tags, and a schema.org `Event` or `Organization` for the page it is on — one block, replaced on each navigation rather than added to. Two
rules it exists to keep: the description in a tag is the plain-text version, so
a WhatsApp group is not shown the markup it is made of; and the structured data
says the event is offline and names its organizer, because a warned rich result
is one that may not be shown.

## Saving

The heart on an event page is a bookmark in that browser, not a synced
favourite — there is no account on this site for one to live in. The phone app's
saved list is a different thing with the same name, and is tied to an account.

## Tests

```bash
npm test --workspace web -- --watch=false
```
