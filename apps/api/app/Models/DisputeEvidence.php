<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The answer to one dispute: what will be sent, what was found, what went.
 *
 * Put together from the records when the dispute opens (EvidenceDraft), edited
 * by staff, and sent by DisputeDesk. Nothing in it is typed from memory: every
 * field starts as a sentence built from a record, and the checklist says which
 * records were there and which were not.
 *
 * It names the buyer, so it is deleted with the rest of the evidence 18 months
 * after the night, once the dispute has closed (EvidenceRetention).
 *
 * @property string $kind
 * @property array<string, mixed>|null $processor
 * @property array<string, string> $fields
 * @property list<string> $files
 * @property list<array{key: string, label: string, found: bool, detail: string|null}> $checklist
 * @property list<string>|null $cautions
 * @property array<string, mixed>|null $compelling_evidence
 * @property array<string, array<string, mixed>>|null $uploads
 * @property array<string, mixed>|null $sent
 * @property int $answer_round
 */
class DisputeEvidence extends Model
{
    use HasUuids;

    protected $table = 'dispute_evidence';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'processor' => 'array',
            'fields' => 'array',
            'files' => 'array',
            'checklist' => 'array',
            'cautions' => 'array',
            'compelling_evidence' => 'array',
            'uploads' => 'array',
            'sent' => 'array',
            'answer_round' => 'integer',
            'built_at' => UtcDateTime::class,
            'edited_at' => UtcDateTime::class,
            'submitted_at' => UtcDateTime::class,
        ];
    }

    /** @return BelongsTo<Dispute, $this> */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    /** How many of the checklist's items the records hold. */
    public function found(): int
    {
        return count(array_filter($this->checklist ?? [], fn (array $item) => $item['found']));
    }
}
