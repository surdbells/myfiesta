<?php

namespace App\Http\Requests;

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
        ]);
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
