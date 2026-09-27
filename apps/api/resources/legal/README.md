# What buyers were shown

One directory per terms version (`config/terms.php`), holding the words a
buyer agreed to under that version, as they were:

- `refunds.md` — the refund policy page (`/refunds` on the public site).
- `refund-summary.txt` — the one sentence shown beside the terms box at
  checkout and beside the pay button on Stripe's page.

A dispute is judged on what the buyer was shown when they paid, and an order
keeps which version that was (`orders.terms_version`). These files are how
that version is turned back into words months later.

**Never edit a directory once its version has been in force.** Changing the
words means a new version: bump `config/terms.php`, add a directory named for
it with the new words, and add its checksums to `LegalCopiesTest`. The test
fails if an existing file changes, if the version in force has no directory,
or if the public site's refund page and checkout say something different from
the copy here.
