<?php

namespace App\Services\Codes;

use App\Models\Code;
use App\Models\CodeBatch;
use App\Models\Event;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Makes a batch of single-use codes.
 *
 * Each code is PREFIX-XXXXXX: six characters from an alphabet with nothing
 * that reads as something else — no 0/O, 1/I/L — because these are read off
 * a printed card or a phone screen and typed by somebody at a checkout. Thirty
 * one characters to the sixth is close to nine hundred million, so a guess at
 * somebody's code is a guess in nine hundred million, against an endpoint
 * that is throttled.
 *
 * Every code is an ordinary row with a limit of one use. Nothing at checkout
 * knows batches exist, which is the point: the rules that hold for one code
 * hold for two thousand.
 */
class CodeBatchGenerator
{
    public const MAX_PER_BATCH = 1000;

    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LENGTH = 6;

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  array{name: string, prefix: string, quantity: int, discount_type?: ?string, discount_value?: ?int, ticket_type_ids?: list<string>, unlock_ticket_type_ids?: list<string>, min_quantity?: ?int, starts_at?: ?string, ends_at?: ?string}  $data
     */
    public function generate(Event $event, User $by, array $data): CodeBatch
    {
        return DB::transaction(function () use ($event, $by, $data) {
            $batch = CodeBatch::create([
                'organization_id' => $event->organization_id,
                'event_id' => $event->id,
                'created_by' => $by->id,
                'name' => $data['name'],
                'prefix' => $data['prefix'],
                'quantity' => $data['quantity'],
            ]);

            $now = now();
            $unlocks = $data['unlock_ticket_type_ids'] ?? [];
            $targets = $data['ticket_type_ids'] ?? [];

            foreach (array_chunk($this->uniqueCodes($event, $data['prefix'], $data['quantity']), 500) as $chunk) {
                $rows = array_map(fn (string $value) => [
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $event->organization_id,
                    'event_id' => $event->id,
                    'batch_id' => $batch->id,
                    'code' => $value,
                    'label' => $data['name'],
                    'discount_type' => $data['discount_type'] ?? null,
                    'discount_value' => $data['discount_value'] ?? null,
                    'discount_currency' => ($data['discount_type'] ?? null) === 'fixed' ? $event->currency : null,
                    'unlocks_tickets' => $unlocks !== [],
                    // Single use is the whole idea: one person, one go.
                    'max_redemptions' => 1,
                    'min_quantity' => $data['min_quantity'] ?? null,
                    'redemption_count' => 0,
                    'starts_at' => $data['starts_at'] ?? null,
                    'ends_at' => $data['ends_at'] ?? null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk);

                DB::table('codes')->insert($rows);

                $ids = array_column($rows, 'id');

                if ($targets !== []) {
                    DB::table('code_ticket_type')->insert($this->pairs($ids, $targets));
                }

                if ($unlocks !== []) {
                    DB::table('code_unlocks')->insert($this->pairs($ids, $unlocks));
                }
            }

            $this->auditor->record('code_batch.created', $event, $by, $event->organization_id, [
                'batch_id' => $batch->id,
                'name' => $batch->name,
                'quantity' => $batch->quantity,
            ]);

            return $batch;
        });
    }

    /**
     * New codes, none already used anywhere in the organization.
     *
     * Checked against the table in one query per round rather than one per
     * code. A collision is astronomically rare, but the unique index would
     * turn one into a failed batch, so any that do collide are replaced.
     *
     * @return list<string>
     */
    private function uniqueCodes(Event $event, string $prefix, int $quantity): array
    {
        $codes = [];

        while (count($codes) < $quantity) {
            $candidates = [];

            while (count($candidates) < ($quantity - count($codes))) {
                $candidate = $prefix.'-'.$this->random();
                $candidates[$candidate] = true;
            }

            $taken = Code::withTrashed()
                ->where('organization_id', $event->organization_id)
                ->whereIn(DB::raw('upper(code)'), array_keys($candidates))
                ->pluck('code')
                ->map(fn (string $c) => strtoupper($c))
                ->flip();

            foreach (array_keys($candidates) as $candidate) {
                if (! isset($taken[$candidate]) && ! isset($codes[$candidate])) {
                    $codes[$candidate] = true;
                }
            }
        }

        return array_slice(array_keys($codes), 0, $quantity);
    }

    private function random(): string
    {
        $out = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $out;
    }

    /**
     * @param  list<string>  $codeIds
     * @param  list<string>  $ticketTypeIds
     * @return list<array{code_id: string, ticket_type_id: string}>
     */
    private function pairs(array $codeIds, array $ticketTypeIds): array
    {
        $pairs = [];

        foreach ($codeIds as $codeId) {
            foreach ($ticketTypeIds as $typeId) {
                $pairs[] = ['code_id' => $codeId, 'ticket_type_id' => $typeId];
            }
        }

        return $pairs;
    }
}
