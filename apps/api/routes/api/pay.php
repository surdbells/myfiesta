<?php

// Paying later (Klarna and Affirm through Stripe) and payments that settle after checkout.
//
// The organizer's opt-in for a night is a route of its own here
// (PUT /organizer/events/{event}/pay-later), not a field of
// Organizer\EventController::update, which belongs to scheduling.
