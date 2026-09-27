<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex" />
    <title>{{ $kind === 'sign-up' ? 'Your account' : 'Your email address' }} — {{ config('app.name') }}</title>
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
      dl {
        margin: 0 auto 20px;
        padding: 12px 16px;
        max-width: 26rem;
        text-align: left;
        border: 1px solid var(--line);
        border-radius: 8px;
      }
      dt {
        font-size: 0.8125rem;
        color: var(--ink-2);
      }
      dd {
        margin: 0 0 8px;
        overflow-wrap: anywhere;
      }
      dd:last-child {
        margin-bottom: 0;
      }
      button,
      a.button {
        display: inline-block;
        padding: 12px 24px;
        font: inherit;
        font-weight: 600;
        color: #fff;
        background: var(--accent);
        border: 0;
        border-radius: 8px;
        cursor: pointer;
        text-decoration: none;
      }
      button:focus-visible,
      a:focus-visible {
        outline: 2px solid var(--accent);
        outline-offset: 2px;
      }
      a {
        color: var(--accent);
      }
      small {
        display: block;
        margin-top: 20px;
        color: var(--ink-2);
      }
      form {
        margin: 0 auto;
        max-width: 26rem;
        text-align: left;
      }
      label {
        display: block;
        margin-bottom: 4px;
        font-size: 0.875rem;
        font-weight: 600;
      }
      input[type='password'] {
        box-sizing: border-box;
        width: 100%;
        margin-bottom: 16px;
        padding: 10px 12px;
        font: inherit;
        color: var(--ink);
        background: var(--ground);
        border: 1px solid var(--line);
        border-radius: 8px;
      }
      input[type='password']:focus-visible {
        outline: 2px solid var(--accent);
        outline-offset: 1px;
      }
      form button {
        width: 100%;
      }
      .error {
        margin: 0 0 12px;
        color: var(--accent);
        font-weight: 600;
      }
    </style>
  </head>
  <body>
    <main>
      @if ($kind === 'sign-up' && $state === 'confirm')
        <h1>Make your myFiesta account?</h1>
        <p>A myFiesta account was asked for with this address. If that was you, type the password you chose to finish. Nothing is made until you do.</p>
        {{-- The address and nothing else the form was given. The address is
             the one thing the reader can check. The name and the events page
             were typed by whoever filled in the form, and anybody can fill it
             in with anybody's address: shown here, just above the password
             box, "Your password is Blue-Sky-4471" would be a stranger telling
             the inbox's owner what to type, on our own page, and the account
             it made would be the stranger's. --}}
        <dl>
          <dt>Email address</dt>
          <dd>{{ $pending->email }}</dd>
        </dl>
        {{-- A POST, never a GET: mail scanners follow links, and an account
             made on a prefetch would be made for whoever sent the email. The
             password is what shows the person reading this inbox is the one
             who filled in the form, not somebody using their address. --}}
        <form method="post" action="{{ $action }}">
          @if ($error)
            <p class="error" role="alert">{{ $error }}</p>
          @endif
          <label for="password">The password you chose</label>
          <input id="password" name="password" type="password" autocomplete="current-password" required autofocus />
          <button type="submit">Make my account</button>
        </form>
        <small>Not you? Close this page. Nothing is made.</small>
      @elseif ($kind === 'sign-up' && $state === 'done')
        <h1>Your account is ready</h1>
        <p>Sign in with {{ $user->email }} and the password you chose.</p>
        @if ($organizer)
          <a class="button" href="{{ $console }}/sign-in">Sign in</a>
          <small>Or open the myFiesta app and sign in there.</small>
        @else
          <p>Open the myFiesta app and sign in — any tickets bought with this address are already there.</p>
        @endif
      @elseif ($kind === 'sign-up' && $state === 'taken')
        <h1>This address already has an account</h1>
        <p>Nothing new was made. Sign in with it as usual, or set a new password if you have forgotten it.</p>
        <a class="button" href="{{ $console }}/sign-in">Sign in</a>
        <small><a href="{{ $console }}/forgot-password">Set a new password</a></small>
      @elseif ($kind === 'verify' && $state === 'confirm')
        <h1>Confirm this address?</h1>
        <p>{{ $email }} is the address on your myFiesta account. Confirming it lets the account publish events and manage payouts.</p>
        <form method="post" action="{{ $action }}">
          @if ($error)
            <p class="error" role="alert">{{ $error }}</p>
          @endif
          <label for="password">Your myFiesta password</label>
          <input id="password" name="password" type="password" autocomplete="current-password" required autofocus />
          <button type="submit">Confirm this address</button>
        </form>
        <small>No myFiesta account, or not expecting this? Close this page. Nothing changes.</small>
      @elseif ($kind === 'verify' && $state === 'verified')
        <h1>Address confirmed</h1>
        <p>{{ $email }} is confirmed. Go back to what you were doing and try it again.</p>
      @else
        {{-- One answer for expired, used, altered or never real: none of them
             can be put right from here, and saying which would say more than
             this page needs to. --}}
        <h1>That link has expired</h1>
        @if ($kind === 'sign-up')
          <p>A sign-up link works for a day, and once. Sign up again and a new one will come to the same address.</p>
        @else
          <p>A link works for a day, and only for the address it was sent to. Ask for a new one from your account.</p>
        @endif
      @endif
    </main>
  </body>
</html>
