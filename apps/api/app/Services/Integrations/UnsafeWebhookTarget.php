<?php

namespace App\Services\Integrations;

use RuntimeException;

/** A URL we will not send anything to, with the reason written for the organizer. */
class UnsafeWebhookTarget extends RuntimeException {}
