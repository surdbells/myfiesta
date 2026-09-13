<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex" />
    <title>Waitlist — {{ config('app.name') }}</title>
    <style>
      :root { color-scheme: light dark; --ground: #ffffff; --ink: #14161c; --ink-2: #5c6373; --accent: #0f7a3e; }
      @media (prefers-color-scheme: dark) { :root { --ground: #121419; --ink: #e9eaee; --ink-2: #a3a9b7; --accent: #4cc27f; } }
      body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: var(--ground); color: var(--ink); font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
      main { max-width: 34rem; text-align: center; }
      h1 { margin: 0 0 12px; font-size: 1.5rem; letter-spacing: -0.02em; }
      p { margin: 0 0 16px; color: var(--ink-2); }
      button { padding: 12px 24px; font: inherit; font-weight: 600; color: #fff; background: var(--accent); border: 0; border-radius: 8px; cursor: pointer; }
      button:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
    </style>
  </head>
  <body>
    <main>
      @if ($state === 'confirm')
        <h1>Leave the waitlist for {{ $entry->event->title }}?</h1>
        <p>We will not email you if tickets come up.</p>
        <form method="post" action="{{ route('waitlist.leave.confirm', $token) }}">
          @csrf
          <button type="submit">Leave the waitlist</button>
        </form>
      @elseif ($state === 'done')
        <h1>You have left the waitlist</h1>
        <p>We will not email you about tickets for {{ $entry->event->title }}.</p>
      @else
        {{-- Vague on purpose: no telling whether an address was ever on a list. --}}
        <h1>That link has expired</h1>
        <p>If you are still getting emails you do not want, use the link in the most recent one.</p>
      @endif
    </main>
  </body>
</html>
