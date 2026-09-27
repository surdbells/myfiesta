<?php

namespace App\Http\Requests;

use App\Models\Order;
use App\Services\Accounts\Terms;

class CreateOrderRequest extends QuoteRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'buyer.name' => ['required', 'string', 'max:120'],
            'buyer.email' => ['required', 'email:rfc', 'max:255'],
            'buyer.phone' => ['nullable', 'string', 'max:32'],

            /*
             * What the organizer asked.
             *
             * Shape only, here. Which questions exist, which must be answered
             * and what an answer may say are decided against the database in
             * App\Services\Checkout\Answers — a form is markup the client
             * controls, and validating against what it claims to have rendered
             * would be validating against the sender.
             *
             * Answers for the order, keyed by question id.
             */
            'answers' => ['nullable', 'array', 'max:50'],

            // And one set for each person, in the order they were filled in.
            // 1000 is the largest order this API will take: 20 line types at
            // 50 each.
            'attendees' => ['nullable', 'array', 'max:1000'],
            // Bought inside an organizer's own site. Only ever a label on the
            // order for their reports; it changes nothing about the sale.
            'embedded' => ['sometimes', 'boolean'],
            'attendees.*.ticket_type_id' => ['required', 'uuid'],
            'attendees.*.answers' => ['nullable', 'array', 'max:50'],

            /*
             * The box by the pay button: the terms, the privacy policy and the
             * refund policy, agreed to.
             *
             * Asked of every buyer today. This route reads no sign-in — no app
             * checks out signed in, and a token sent here is not looked at —
             * so $this->user() is nobody, as it is for the order itself. The
             * account half is for a checkout that knows its buyer: one whose
             * account agreed to the words in force is not asked again, and
             * one whose account has not is asked once. It arrives with the
             * order being placed for that account, resolved the same way, or
             * the agreement would land on an account the order is not on.
             *
             * Door sales never come through here, and are not asked. The
             * person paying at the door hands over cash or a card to the
             * organizer's own staff, is let in on the spot, and has no screen
             * of ours in front of them to read three pages on — and the sale
             * is made by an organizer who agreed to the terms when they signed
             * up. An order with no agreement on it is how a door sale reads.
             */
            'accept_terms' => app(Terms::class)->acceptedBy($this->user()) ? ['nullable', 'boolean'] : ['accepted'],
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'accept_terms.accepted' => Terms::REFUSAL,
        ];
    }

    /**
     * Keep with the order what its buyer agreed to.
     *
     * The same user the order itself is placed for, resolved the same way, so
     * the agreement lands on the account the order does and no other.
     */
    public function recordAcceptance(Order $order): void
    {
        app(Terms::class)->recordOn($order, $this->user(), $this->boolean('accept_terms'));
    }

    /**
     * Answers for the order as a whole.
     *
     * @return array<string, mixed>
     */
    public function orderAnswers(): array
    {
        $answers = $this->input('answers', []);

        return is_array($answers) ? $answers : [];
    }

    /**
     * One entry per ticket, in the order the buyer filled them in.
     *
     * @return list<array{ticket_type_id: string, answers?: array<string, mixed>}>
     */
    public function attendees(): array
    {
        $attendees = $this->input('attendees', []);

        return is_array($attendees) ? array_values($attendees) : [];
    }
}
