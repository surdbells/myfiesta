<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\PhonePreference;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Somebody replying STOP.
 *
 * Both markets require a text to be stoppable by replying to it, so this is
 * not optional decoration — without it the feature should not be switched on
 * at all. Providers post inbound messages here; the shared secret in the URL
 * is what makes the endpoint ours rather than anybody's, since Termii and
 * Twilio sign their callbacks differently and neither well.
 *
 * Anything that is not a stop word is ignored, deliberately. This is not an
 * inbox: nobody is reading replies, and pretending otherwise by acting on
 * them would be worse than the silence.
 */
class InboundSmsController extends Controller
{
    /** What people actually type. Both markets recognise these. */
    private const STOP = ['stop', 'stopall', 'unsubscribe', 'cancel', 'end', 'quit'];

    private const START = ['start', 'unstop', 'yes'];

    public function __invoke(Request $request, string $secret): Response
    {
        $expected = config('sms.inbound_secret');

        abort_if(blank($expected) || ! hash_equals($expected, $secret), 404);

        // Termii sends `from` and `message`; Twilio sends `From` and `Body`.
        $from = (string) ($request->input('from') ?? $request->input('From') ?? '');
        $text = strtolower(trim((string) ($request->input('message') ?? $request->input('Body') ?? '')));

        if ($from === '' || $text === '') {
            return response()->noContent(202);
        }

        if (in_array($text, self::STOP, true)) {
            PhonePreference::optOut($from, 'replied '.$text);
        } elseif (in_array($text, self::START, true)) {
            PhonePreference::optIn($from);
        }

        // 200 whatever was said: a provider that gets an error retries, and
        // retrying somebody's STOP achieves nothing.
        return response()->noContent(200);
    }
}
