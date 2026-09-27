<?php

namespace Tests\Concerns;

use App\Mail\SignUpConfirm;
use Illuminate\Support\Facades\Mail;

/**
 * Signing up the way a person does: the form, then the link in the email.
 *
 * Nothing is made until the link is opened, so any test that needs an account
 * made by signing up goes through both halves. Needs Mail::fake() in place
 * before the form is posted.
 */
trait FinishesSignUps
{
    /** @param  array<string, mixed>  $body */
    protected function signUpAndConfirm(array $body): void
    {
        $this->postJson('/api/auth/register', $body)
            ->assertStatus(202)
            ->assertJsonPath('pending', true);

        // The page asks for the password chosen on the form: opening the link
        // proves the inbox, the password proves it is the same person.
        $this->post($this->relative($this->signUpLink($body['email'])), ['password' => $body['password']])->assertOk();
    }

    /** The link in the most recent sign-up email to this address. */
    protected function signUpLink(string $email): string
    {
        $url = null;

        Mail::assertSent(SignUpConfirm::class, function (SignUpConfirm $mail) use ($email, &$url) {
            if (! $mail->hasTo(strtolower($email))) {
                return false;
            }

            $url = $mail->url;

            return true;
        });

        return $url;
    }

    /** The path and query of a link, which is what its signature covers. */
    protected function relative(string $url): string
    {
        $parts = parse_url($url);

        return $parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /** @return array<string, mixed> the sign-in payload */
    protected function signIn(string $email, string $password, string $device = 'web'): array
    {
        return $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => $password,
            'device' => $device,
        ])->assertOk()->json();
    }
}
