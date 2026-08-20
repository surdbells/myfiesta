<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex" />
    <title>Email preferences — {{ config('app.name') }}</title>
    <style>
      :root {
        color-scheme: light dark;
        --ground: #ffffff;
        --ink: #14161c;
        --ink-2: #5c6373;
        --line: #e2e2dc;
        --accent: #b3143f;
      }
      @media (prefers-color-scheme: dark) {
        :root {
          --ground: #121419;
          --ink: #e9eaee;
          --ink-2: #a3a9b7;
          --line: #2a2e38;
          --accent: #f2698d;
        }
      }
      body {
        margin: 0;
        min-height: 100vh;
        display: grid;
        place-items: center;
        padding: 24px;
        background: var(--ground);
        color: var(--ink);
        font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif;
      }
      main {
        max-width: 34rem;
        text-align: center;
      }
      h1 {
        margin: 0 0 12px;
        font-size: 1.5rem;
        letter-spacing: -0.02em;
      }
      p {
        margin: 0 0 16px;
        color: var(--ink-2);
      }
      button {
        padding: 12px 24px;
        font: inherit;
        font-weight: 600;
        color: #fff;
        background: var(--accent);
        border: 0;
        border-radius: 8px;
        cursor: pointer;
      }
      button.quiet {
        color: var(--ink-2);
        background: none;
        border: 1px solid var(--line);
      }
      button:focus-visible {
        outline: 2px solid var(--accent);
        outline-offset: 2px;
      }
    </style>
  </head>
  <body>
    <main>
      @if ($state === 'confirm')
        <h1>Stop event reminders?</h1>
        <p>
          You will still get emails about tickets you buy and orders you place —
          those are not marketing, and turning them off would leave you without
          your ticket.
        </p>
        <form method="post" action="{{ route('unsubscribe.confirm', $token) }}">
          @csrf
          <button type="submit">Stop reminders</button>
        </form>
      @elseif ($state === 'done')
        <h1>Done</h1>
        <p>You will not get any more event reminders from us.</p>
        <form method="post" action="{{ route('unsubscribe.resubscribe', $token) }}">
          @csrf
          <button class="quiet" type="submit">Actually, turn them back on</button>
        </form>
      @elseif ($state === 'already')
        <h1>Already off</h1>
        <p>Event reminders are already turned off for this address.</p>
        <form method="post" action="{{ route('unsubscribe.resubscribe', $token) }}">
          @csrf
          <button class="quiet" type="submit">Turn them back on</button>
        </form>
      @elseif ($state === 'resubscribed')
        <h1>Turned back on</h1>
        <p>You will get reminders about events you have tickets to.</p>
      @else
        {{-- Deliberately vague. Saying "no such address" would turn this page
             into a way to test whether an address is on the platform. --}}
        <h1>That link has expired</h1>
        <p>
          If you are still getting emails you do not want, use the unsubscribe
          link in the most recent one.
        </p>
      @endif
    </main>
  </body>
</html>
