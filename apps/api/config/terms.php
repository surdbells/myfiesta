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
    */

    'version' => '2026-09-27',

];
