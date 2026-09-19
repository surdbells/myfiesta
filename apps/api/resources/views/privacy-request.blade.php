<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex" />
    <title>Your data — {{ config('app.name') }}</title>
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
        font: 16px/1.6 system-ui, -apple-system, 'Segoe UI', sans-serif;
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
      ul {
        margin: 0 0 16px;
        padding-left: 1.25rem;
        text-align: left;
        color: var(--ink-2);
        font-size: 0.9375rem;
      }
      li {
        margin-bottom: 6px;
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
      a.download {
        text-decoration: none;
      }
    </style>
  </head>
  <body>
    <main>
      @if ($state === 'confirm')
        @if ($request->kind === 'erasure')
          <h1>Erase everything about you?</h1>
          <p>
            Your account is closed, and your name and address are removed from everything we can remove them
            from. Orders, tickets and the entries that record money moving stay for seven years because the
            law requires it — with nobody's name on them.
          </p>
          <p>This cannot be undone, and nobody will ask you why.</p>
        @else
          <h1>Send you everything held about you?</h1>
          <p>
            One file, ready in a moment and yours to download for a week. Ticket codes are left out: those
            open doors, and your tickets are always in the link from your confirmation email.
          </p>
        @endif

        {{-- A POST, never a GET: mail scanners follow links, and an erasure
             that ran on a prefetch would erase somebody who never clicked. --}}
        <form method="post" action="{{ route('privacy.confirm', $token) }}">
          @csrf
          <button type="submit">{{ $request->kind === 'erasure' ? 'Erase me' : 'Send it to me' }}</button>
        </form>
      @elseif ($state === 'done')
        @if ($request->status === 'refused')
          <h1>Not yet</h1>
          <p>{{ $request->outcome['refused'] ?? 'We could not do that.' }}</p>
          <p>Nothing has been erased. Ask again once that is sorted.</p>
        @elseif ($request->kind === 'erasure')
          <h1>Done</h1>
          <p>You have been erased. What is left is below, with nobody's name attached to it.</p>
          <ul>
            @foreach ($request->outcome['erased'] ?? [] as $table => $row)
              @if (($row['action'] ?? '') !== 'deleted' && $table !== 'account')
                <li>
                  <strong>{{ str_replace('_', ' ', $table) }}</strong> — {{ $row['action'] }}@if (! empty($row['why'])):
                  {{ $row['why'] }}@endif
                </li>
              @endif
            @endforeach
          </ul>
        @elseif ($request->isDownloadable())
          <h1>Here it is</h1>
          <p>Yours for a week, then the file is deleted. The link is in your inbox as well.</p>
          <a class="download" href="{{ route('privacy.download', $token) }}"><button type="button">Download your data</button></a>
        @else
          <h1>That has been dealt with</h1>
          <p>This request is already done. If the file has expired, ask for a new copy.</p>
        @endif
      @else
        {{-- Deliberately vague. Saying "no such request" would make this a way
             to test whether an address is on the platform. --}}
        <h1>That link has expired</h1>
        <p>
          A link stops working after a day, and an export is kept for a week. Ask again and a new link will
          come to the same address.
        </p>
      @endif
    </main>
  </body>
</html>
