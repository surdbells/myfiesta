<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Which terms are in force
    |--------------------------------------------------------------------------
    |
    | One version for the three pages somebody agrees to together: the terms,
    | the privacy policy and the refund policy (/terms, /privacy and /refunds
    | on the public site). A sign-up and an order record the version that was
    | in force when the box was ticked, so either can be explained later
    | against the words that applied to it rather than the words there are now.
    |
    | Change it whenever any of the three pages changes in substance — in the
    | same change as the new words, since they are only a deploy apart. A
    | signed-in person who accepted an older version is asked again at their
    | next checkout, and at the top of the console and the phone's organizer
    | screens; nothing else is held back from them.
    |
    | Written here rather than read from the environment on purpose. The words
    | live in the code of the public site, so the version naming them belongs
    | beside them; an environment variable could be left behind on a server
    | and go on naming words that are no longer on the page.
    |
    | Each version also has a copy of its refund policy, and of the sentence
    | shown by the pay button, in resources/legal/<version>: a dispute is
    | judged on what the buyer was shown, and the page itself only ever says
    | what it says now. A new version gets a new directory (see its README).
    |
    | 2026-09-27.2: the privacy page says the order keeps the address it came
    | from and the browser, and the ticket history and payment record kept to
    | answer a disputed payment, until 18 months after the event. The last
    | version told people neither was kept, so it is not something they can be
    | taken to have agreed to already. It also says what is sent to Stripe or
    | Paystack for the bank when a disputed payment is answered — written
    | before this version was ever in force, so it needs no version of its own.
    | So is a narrowing, also before it was in force: the page you return to
    | after paying is no longer noted, and a ticket passed on is noted in its
    | new holder's app without their address. Both keep less than the words
    | first said, so what anybody agreed to still covers what is done.
    |
    */

    'version' => '2026-09-27.2',

];
