<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Models\Code;
use App\Models\CodeBatch;
use App\Models\Event;
use App\Services\Audit\Auditor;
use App\Services\Codes\CodeBatchGenerator;
use App\Support\Csv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Batches of single-use codes: making them, handing them out, stopping them.
 *
 * Kept out of the codes list. Two hundred rows of SPONSOR-7K2M9Q between an
 * organizer and their promoter codes would bury the list; a batch is one line
 * that says how many of its codes have been used.
 */
class CodeBatchController extends Controller
{
    public function __construct(
        private readonly CodeBatchGenerator $generator,
        private readonly Auditor $auditor,
    ) {}

    public function index(Request $request, Event $event): JsonResponse
    {
        $this->authorize('manageCodes', $event);

        $batches = CodeBatch::query()
            ->where('event_id', $event->id)
            ->withCount([
                'codes as used_count' => fn ($q) => $q->where('redemption_count', '>', 0),
                'codes as off_count' => fn ($q) => $q->where('is_active', false)->where('redemption_count', 0),
            ])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $batches->map(fn (CodeBatch $b) => $this->present($b))->values()]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $this->authorize('manageCodes', $event);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // Letters and digits only, so every code in the batch reads the
            // same way on a card and cannot collide with the separator.
            'prefix' => ['nullable', 'string', 'max:12', 'regex:/^[A-Za-z0-9]+$/'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CodeBatchGenerator::MAX_PER_BATCH],
            'discount_type' => ['nullable', 'in:percentage,fixed', 'required_with:discount_value'],
            'discount_value' => ['nullable', 'integer', 'min:1', 'required_with:discount_type'],
            'ticket_type_ids' => ['nullable', 'array'],
            'ticket_type_ids.*' => ['uuid', Rule::exists('ticket_types', 'id')->where('event_id', $event->id)],
            'unlock_ticket_type_ids' => ['nullable', 'array'],
            'unlock_ticket_type_ids.*' => ['uuid', Rule::exists('ticket_types', 'id')->where('event_id', $event->id)],
            'min_quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ], [
            'quantity.max' => 'A batch can hold up to '.CodeBatchGenerator::MAX_PER_BATCH.' codes. Make another for more.',
            'prefix.regex' => 'Letters and numbers only.',
        ]);

        // A single-use code that credits a promoter would credit them once;
        // batches are for taking money off or letting somebody in.
        if (blank($data['discount_type'] ?? null) && empty($data['unlock_ticket_type_ids'])) {
            return response()->json([
                'message' => 'Single-use codes need to take money off or unlock tickets.',
            ], 422);
        }

        if (($data['discount_type'] ?? null) === 'percentage' && $data['discount_value'] > 10000) {
            return response()->json(['message' => 'A percentage cannot exceed 100%.'], 422);
        }

        if ((! empty($data['ticket_type_ids']) || ! empty($data['min_quantity'])) && blank($data['discount_type'] ?? null)) {
            return response()->json([
                'message' => 'Ticket and quantity conditions only apply to a discount.',
            ], 422);
        }

        $data['prefix'] = strtoupper($data['prefix'] ?? '') ?: $this->prefixFrom($data['name']);

        $batch = $this->generator->generate($event, $request->user(), $data);

        return response()->json($this->present($batch->loadCount([
            'codes as used_count' => fn ($q) => $q->where('redemption_count', '>', 0),
            'codes as off_count' => fn ($q) => $q->where('is_active', false)->where('redemption_count', 0),
        ])), 201);
    }

    /**
     * Every code in the batch, as a spreadsheet to hand out from.
     *
     * The codes themselves are the content — this is what gets mail-merged to
     * winners — so the download is logged like any other export of something
     * that has value in the wrong hands.
     */
    public function export(Request $request, Event $event, CodeBatch $batch): StreamedResponse
    {
        $this->authorize('manageCodes', $event);

        abort_unless($batch->event_id === $event->id, 404);

        $this->auditor->record('code_batch.exported', $event, $request->user(), metadata: [
            'batch_id' => $batch->id,
            'quantity' => $batch->quantity,
        ]);

        // The paid order behind each used code, fetched once for the batch
        // rather than once per row — a thousand-code batch was a thousand queries.
        $orders = DB::table('orders')
            ->join('codes', fn ($join) => $join->on('codes.id', '=', 'orders.code_id')->orOn('codes.id', '=', 'orders.access_code_id'))
            ->where('codes.batch_id', $batch->id)
            ->whereIn('orders.status', Code::PAID_STATUSES)
            ->orderBy('orders.paid_at')
            ->get(['codes.id as code_id', 'orders.reference'])
            ->unique('code_id')
            ->pluck('reference', 'code_id');

        $rows = (function () use ($batch, $orders) {
            foreach ($batch->codes()->orderBy('code')->lazy(500) as $code) {
                $reference = $orders[$code->id] ?? null;

                yield [
                    Csv::text($code->code),
                    $reference ? 'Used' : ($code->is_active ? 'Unused' : 'Turned off'),
                    $reference,
                ];
            }
        })();

        return Csv::download(
            Str::slug($event->title).'-'.Str::slug($batch->name).'-codes.csv',
            ['Code', 'Status', 'Order reference'],
            $rows,
        );
    }

    /**
     * Stop the codes nobody has used yet.
     *
     * For a giveaway that is over, or a spreadsheet sent to the wrong list.
     * Used codes are left as they are: their orders stand.
     */
    public function deactivate(Request $request, Event $event, CodeBatch $batch): JsonResponse
    {
        $this->authorize('manageCodes', $event);

        abort_unless($batch->event_id === $event->id, 404);

        $stopped = $batch->codes()
            ->where('is_active', true)
            ->where('redemption_count', 0)
            ->update(['is_active' => false, 'updated_at' => now()]);

        $this->auditor->record('code_batch.deactivated', $event, $request->user(), metadata: [
            'batch_id' => $batch->id,
            'stopped' => $stopped,
        ]);

        return response()->json([
            'message' => $stopped === 1 ? 'Turned off 1 unused code.' : "Turned off {$stopped} unused codes.",
        ]);
    }

    /** "Sponsor giveaway" → SPONSOR: the first word, letters and digits, eight at most. */
    private function prefixFrom(string $name): string
    {
        $word = preg_replace('/[^A-Za-z0-9]/', '', Str::before(Str::ascii(trim($name)), ' '));

        return strtoupper(substr($word ?: 'CODE', 0, 8));
    }

    private function present(CodeBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'name' => $batch->name,
            'prefix' => $batch->prefix,
            'quantity' => $batch->quantity,
            'used' => (int) ($batch->used_count ?? 0),
            'turned_off' => (int) ($batch->off_count ?? 0),
            'created_at' => $batch->created_at,
        ];
    }
}
