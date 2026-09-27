<?php

namespace App\Services\Disputes;

use App\Contracts\Payments\AnswersDisputes;
use App\Contracts\Payments\EvidenceFile;
use App\Contracts\Payments\EvidencePackage;
use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Contracts\Payments\ProcessorDispute;
use App\Enums\PlatformRole;
use App\Mail\DisputeDueSoon;
use App\Mail\DisputeOpened;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\StaffSupport\StaffActionRefused;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Where a dispute is answered: put together when it opens, read and corrected
 * by staff, and sent — or conceded — before the deadline.
 *
 * Nothing is contested automatically. The platform puts the answer together
 * the moment the processor says a dispute exists, and tells Admin and Finance;
 * a person then reads it, with anything that suggests the buyer is right set
 * out above it, and decides. Some disputes are fair, and sending evidence
 * against a night that was cancelled and never refunded is a loss with a fee
 * on top.
 *
 * Admin and Finance answer; Support can read the page but not send anything,
 * the same split as refunds (StaffAction). Sending and accepting each happen
 * once: under a lock, against the dispute as it is now, and an answer already
 * given is reported rather than given again. Every step is in the audit
 * trail — who, when, which fields and files went — but not the words or the
 * files themselves, which name the buyer and the audit trail outlives them.
 * The words and the files are kept on the dispute's evidence (and the private
 * disk) until that is pruned with the rest.
 *
 * The organizer hears about a dispute the way they already did: the
 * order.disputed webhook from DisputeService, for anybody who asked for it.
 * Nothing here writes to the buyer.
 */
class DisputeDesk
{
    /** Where the documents that were sent are kept. Never the public disk. */
    public const DISK = 'private';

    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly EvidenceDraft $drafts,
        private readonly EvidenceDocuments $documents,
        private readonly Auditor $auditor,
    ) {}

    /** Who may send evidence, accept a dispute, or download what goes with it. */
    public static function mayAnswer(?User $staff): bool
    {
        return $staff !== null
            && $staff->deleted_at === null
            && $staff->hasPlatformRole(PlatformRole::Admin, PlatformRole::Finance);
    }

    // --- when it opens -------------------------------------------------------------------

    /**
     * A dispute has opened: ask the processor about it, put the answer
     * together, and tell Admin and Finance.
     *
     * Never throws. It runs after the notice has been acknowledged
     * (PrepareDisputeAnswer), and each step that fails is reported and leaves
     * the rest to happen: a processor that will not answer still leaves a
     * draft from our own records, and staff are told either way. Heard twice,
     * it asks again but changes no draft and tells nobody twice.
     */
    public function prepare(Dispute $dispute): void
    {
        $processor = $this->refresh($dispute);
        $dispute->refresh();

        if (! $dispute->isAnswered() && ! $dispute->evidence()->exists()) {
            rescue(fn () => $this->build($dispute, $processor));
        }

        rescue(fn () => $this->tellStaff($dispute->fresh() ?? $dispute));
    }

    /**
     * Ask the processor about the dispute and keep what it says: its state,
     * the network's reason code, the deadline as it has it now.
     */
    public function refresh(Dispute $dispute): ?ProcessorDispute
    {
        $gateway = $this->gateway($dispute);

        if ($gateway === null) {
            return null;
        }

        try {
            $processor = $gateway->describeDispute($dispute);
        } catch (Throwable $e) {
            Log::warning('Could not ask the payment processor about a dispute. The evidence is put together from our own records; staff can ask again from the dispute\'s page.', [
                'dispute' => $dispute->gateway_reference,
                'gateway' => $dispute->gateway,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $dispute->forceFill(array_filter([
            'processor_status' => $processor->status,
            'network_reason_code' => $processor->networkReasonCode,
            'evidence_due_at' => $processor->dueAt,
            'reason' => $dispute->reason ?? $processor->reason,
        ], fn ($value) => $value !== null) + ['processor_checked_at' => now()])->save();

        DisputeEvidence::query()->where('dispute_id', $dispute->id)->update([
            'processor' => json_encode($processor->facts),
            'updated_at' => now(),
        ]);

        return $processor;
    }

    /**
     * Put the answer together from the records, replacing any draft.
     *
     * Staff edits go with it: this is the "start again from the records"
     * button, and the first draft.
     */
    public function build(Dispute $dispute, ?ProcessorDispute $processor = null): DisputeEvidence
    {
        $processor ??= $this->stored($dispute);
        $case = CaseFile::for($dispute);
        $draft = $this->drafts->build($case, $processor);

        return DisputeEvidence::query()->updateOrCreate(['dispute_id' => $dispute->id], [
            'event_id' => $dispute->event_id,
            'customer_email' => $case->order->buyer_email,
            'kind' => $draft['kind'],
            'processor' => $processor?->facts,
            'fields' => $draft['fields'],
            'files' => $draft['files'],
            'checklist' => $draft['checklist'],
            'cautions' => $draft['cautions'],
            'compelling_evidence' => $draft['compelling_evidence'],
            'built_at' => now(),
            'edited_at' => null,
            'edited_by' => null,
        ]);
    }

    // --- staff at the page ------------------------------------------------------------

    /** Start again from the records, on a member of staff's say. */
    public function rebuild(Dispute $dispute, User $staff): DisputeEvidence
    {
        $this->authorize($staff);
        $this->stillOpenFor($dispute, 'put together again');

        $evidence = $this->build($dispute);

        $this->auditor->record('dispute.evidence_rebuilt', $dispute, $staff, $dispute->organization_id, [
            'dispute' => $dispute->gateway_reference,
            'kind' => $evidence->kind,
        ]);

        return $evidence;
    }

    /**
     * Keep staff's corrections to the words. Only the fields the draft has;
     * a field left empty is kept empty and not sent.
     *
     * @param  array<string, mixed>  $fields
     * @return list<string> the fields that changed
     */
    public function saveDraft(Dispute $dispute, array $fields, User $staff): array
    {
        $this->authorize($staff);
        $this->stillOpenFor($dispute, 'changed');

        $evidence = $dispute->evidence()->first() ?? throw StaffActionRefused::because('There is no evidence to save yet. Put it together from the records first.');

        $before = (array) $evidence->fields;
        $after = [];

        foreach ($before as $name => $value) {
            $after[$name] = array_key_exists($name, $fields)
                ? trim(str_replace("\r\n", "\n", (string) $fields[$name]))
                : (string) $value;
        }

        $after = $this->drafts->fit($after);
        $changed = array_keys(array_filter($after, fn (string $value, string $name) => $value !== (string) ($before[$name] ?? ''), ARRAY_FILTER_USE_BOTH));

        if ($changed === []) {
            return [];
        }

        $evidence->update(['fields' => $after, 'edited_at' => now(), 'edited_by' => $staff->id]);

        $this->auditor->record('dispute.evidence_edited', $dispute, $staff, $dispute->organization_id, [
            'dispute' => $dispute->gateway_reference,
            'fields' => $changed,
        ]);

        return $changed;
    }

    /**
     * Send the evidence to the processor and ask for a decision on it.
     *
     * Once. Under a lock, so two people pressing at once send one answer; an
     * answer already sent is reported, not sent again; and a try that fails
     * part-way keeps what reached the processor, so the next try sends only
     * what is missing. A try that heard nothing back is retried under the
     * same idempotency keys, which the processor answers with whatever it did
     * the first time; one it refused is retried under new ones, since it
     * would only repeat the refusal to the old (EvidencePackage::spent).
     *
     * @return string a sentence for whoever pressed the button
     */
    public function submit(Dispute $dispute, User $staff): string
    {
        $this->authorize($staff);

        return $this->exclusively($dispute, function (Dispute $dispute) use ($staff) {
            if ($dispute->response === Dispute::SUBMITTED) {
                return 'The evidence was already sent, on '.CaseFile::at($dispute->responded_at).'. Nothing was sent again.';
            }

            $this->stillOpenFor($dispute, 'sent');

            $evidence = $dispute->evidence()->first() ?? throw StaffActionRefused::because('There is no evidence to send yet. Put it together from the records first.');
            $gateway = $this->gatewayOrRefuse($dispute);
            $fields = array_filter(array_map('strval', (array) $evidence->fields), fn (string $value) => trim($value) !== '');

            if ($dispute->gateway === 'paystack') {
                $missing = array_diff(EvidenceDraft::PAYSTACK_REQUIRED, array_keys($fields));

                if ($missing !== []) {
                    throw StaffActionRefused::because('Paystack will not take evidence without: '
                        .implode(', ', array_map(fn (string $name) => Str::lower(EvidenceDraft::FIELDS[$name]['label']), $missing)).'. Fill them in and save first.');
                }
            }

            $enhanced = $dispute->gateway === 'stripe' ? CompellingEvidence::enhanced($evidence->compelling_evidence) : [];
            $package = $this->package($dispute, $evidence, $fields, $enhanced, (array) $evidence->files, (string) ($fields['message'] ?? ''));

            try {
                $answer = $gateway->submitDisputeEvidence($dispute, $package);
            } catch (Throwable $e) {
                // What reached the processor before it refused is on the
                // record too: a document that left is audited whether or not
                // the answer it went with was taken.
                $this->auditor->record('dispute.evidence_not_accepted', $dispute, $staff, $dispute->organization_id, [
                    'dispute' => $dispute->gateway_reference,
                    'processor' => $dispute->gateway,
                    'error' => Str::limit($e->getMessage(), 300),
                    'files' => $this->filesFor($dispute, $package->handedOver()),
                ]);

                throw StaffActionRefused::because($this->processorName($dispute).' did not take the evidence. '.$e->getMessage()
                    .' Nothing is marked as sent. Anything it already has is kept, so trying again sends only what is missing.');
            }

            $evidence->refresh();
            $sentFiles = array_intersect_key((array) $evidence->uploads, array_flip([...$package->files, 'evidence_pack']));
            // Paystack's name for the evidence the answer named: given the
            // words above, on this try or an earlier one with the same words.
            $processorEvidence = in_array('paystack_evidence', $package->handedOver(), true)
                ? ($evidence->uploads['paystack_evidence']['handle'] ?? null)
                : null;

            DB::transaction(function () use ($dispute, $evidence, $staff, $fields, $enhanced, $sentFiles, $processorEvidence, $answer) {
                $evidence->update([
                    'sent' => array_filter([
                        'at' => now()->toIso8601String(),
                        'fields' => $fields,
                        'enhanced' => $enhanced,
                        'files' => $sentFiles,
                        'processor_evidence' => $processorEvidence,
                    ], fn ($value) => $value !== null),
                    'submitted_at' => now(),
                ]);

                $dispute->forceFill([
                    'response' => Dispute::SUBMITTED,
                    'responded_at' => now(),
                    'responded_by' => $staff->id,
                    'processor_status' => $answer->status ?? $dispute->processor_status,
                    'processor_checked_at' => now(),
                ])->save();
            });

            $this->auditor->record('dispute.evidence_submitted', $dispute, $staff, $dispute->organization_id, [
                'dispute' => $dispute->gateway_reference,
                'processor' => $dispute->gateway,
                'reason' => $dispute->reason,
                // Which fields and how long each was; the words themselves
                // name the buyer, and stay on the evidence until it is pruned.
                'fields' => array_map('mb_strlen', $fields),
                'fields_sha256' => hash('sha256', (string) json_encode([$fields, $enhanced])),
                'compelling_evidence' => $enhanced !== [],
                'files' => self::describeFiles($sentFiles),
                'processor_evidence' => $processorEvidence,
                'processor_status' => $answer->status,
            ]);

            return 'Sent to '.$this->processorName($dispute).'. It now reads: '.str_replace(['_', '-'], ' ', (string) ($answer->status ?? 'received')).'.';
        });
    }

    /**
     * Concede the dispute: the buyer keeps the money.
     *
     * The loss itself — the chargeback on the organizer's balance, the tickets
     * stopped — is written when the processor confirms it closed
     * (DisputeService::closed), the same way whoever decided it.
     */
    public function accept(Dispute $dispute, User $staff, string $note): string
    {
        $this->authorize($staff);
        $note = trim($note);

        if (mb_strlen($note) < 3) {
            throw StaffActionRefused::because('Say in a few words why the dispute is being accepted. It goes in the audit trail, and to Paystack as the note.');
        }

        return $this->exclusively($dispute, function (Dispute $dispute) use ($staff, $note) {
            if ($dispute->response === Dispute::ACCEPTED) {
                return 'The dispute was already accepted, on '.CaseFile::at($dispute->responded_at).'. Nothing was sent again.';
            }

            $this->stillOpenFor($dispute, 'accepted');

            $gateway = $this->gatewayOrRefuse($dispute);
            $evidence = $dispute->evidence()->first();
            $package = $this->package($dispute, $evidence, [], [], [], Str::limit($note, 1000, ''));

            try {
                $answer = $gateway->acceptDispute($dispute, $package);
            } catch (Throwable $e) {
                // Audited like a refused send: Paystack is given the receipt
                // before it is asked to resolve, so a document may have left
                // even though nothing was accepted.
                $this->auditor->record('dispute.accept_not_accepted', $dispute, $staff, $dispute->organization_id, [
                    'dispute' => $dispute->gateway_reference,
                    'processor' => $dispute->gateway,
                    'error' => Str::limit($e->getMessage(), 300),
                    'files' => $this->filesFor($dispute, $package->handedOver()),
                ]);

                throw StaffActionRefused::because($this->processorName($dispute).' did not accept it. '.$e->getMessage().' Nothing is marked as accepted.');
            }

            $dispute->forceFill([
                'response' => Dispute::ACCEPTED,
                'responded_at' => now(),
                'responded_by' => $staff->id,
                'processor_status' => $answer->status ?? $dispute->processor_status,
                'processor_checked_at' => now(),
            ])->save();

            $this->auditor->record('dispute.accepted', $dispute, $staff, $dispute->organization_id, [
                'dispute' => $dispute->gateway_reference,
                'processor' => $dispute->gateway,
                'reason' => $dispute->reason,
                'note' => Str::limit($note, 500),
                // The document that went with it, as a send lists its own.
                'files' => $this->filesFor($dispute, $package->handedOver()),
                'processor_status' => $answer->status,
            ]);

            return 'Accepted. '.$this->processorName($dispute).' returns the money to the buyer, and the chargeback comes off the organizer\'s balance when it confirms the dispute has closed.';
        });
    }

    /**
     * One of the dispute's documents: the copy that was sent, when one was,
     * or rendered now from the records.
     */
    public function file(Dispute $dispute, string $kind): EvidenceFile
    {
        $evidence = $dispute->evidence()->first();
        $allowed = array_unique([...(array) ($evidence->files ?? []), 'evidence_pack', ...array_keys(EvidenceDraft::DOCUMENTS)]);

        if (! in_array($kind, $allowed, true) || ! array_key_exists($kind, EvidenceDraft::DOCUMENTS)) {
            throw StaffActionRefused::because('There is no such document for this dispute.');
        }

        $sent = $evidence->uploads[$kind] ?? null;
        $disk = Storage::disk(self::DISK);

        if (is_array($sent) && is_string($sent['path'] ?? null) && $disk->exists($sent['path'])) {
            return new EvidenceFile($kind, (string) ($sent['name'] ?? basename($sent['path'])), (string) $disk->get($sent['path']));
        }

        return $this->documents->render(CaseFile::for($dispute), $kind);
    }

    // --- reminders -------------------------------------------------------------------

    /**
     * Remind Admin and Finance of every dispute still unanswered with five
     * days left, and again with two — each once, whatever the schedule.
     *
     * A dispute that opens with less than five days left was announced with
     * its deadline already (tellStaff), so it is not reminded of the five-day
     * mark on top.
     *
     * @return int how many reminders went
     */
    public function remindDue(): int
    {
        $sent = 0;

        Dispute::query()
            ->where('status', 'open')
            ->whereNull('response')
            ->whereNotNull('evidence_due_at')
            ->where('evidence_due_at', '>', now())
            ->where('evidence_due_at', '<=', now()->addDays(5))
            ->where(fn ($query) => $query->whereNull('reminded_five_days_at')->orWhereNull('reminded_two_days_at'))
            ->orderBy('evidence_due_at')
            ->get()
            ->each(function (Dispute $dispute) use (&$sent) {
                $mark = $dispute->evidence_due_at?->lessThanOrEqualTo(now()->addDays(2)) ? 'reminded_two_days_at' : 'reminded_five_days_at';

                $claimed = Dispute::query()
                    ->whereKey($dispute->id)
                    ->whereNull($mark)
                    ->update([$mark => now()] + ($mark === 'reminded_two_days_at'
                        ? ['reminded_five_days_at' => $dispute->reminded_five_days_at ?? now()]
                        : []));

                if ($claimed === 1) {
                    $this->tell(fn () => new DisputeDueSoon($dispute->fresh() ?? $dispute));
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * Admin and Finance, by address: the people who can answer it.
     *
     * @return Collection<int, lowercase-string>
     */
    public function staffToTell(): Collection
    {
        return User::query()
            ->whereIn('platform_role', [PlatformRole::Admin->value, PlatformRole::Finance->value])
            ->whereNotNull('email')
            ->pluck('email')
            ->map(fn (string $email) => Str::lower($email))
            ->reject(fn (string $email) => str_ends_with($email, '@erased.invalid'))
            ->unique()
            ->values();
    }

    // --- the parts ---------------------------------------------------------------------

    /**
     * Tell Admin and Finance a dispute has opened, once.
     *
     * With the deadline already inside five days, or two, that email is the
     * reminder too, and the sweep does not send it again.
     */
    private function tellStaff(Dispute $dispute): void
    {
        $due = $dispute->evidence_due_at;

        $claimed = Dispute::query()->whereKey($dispute->id)->whereNull('staff_told_at')->update(array_filter([
            'staff_told_at' => now(),
            'reminded_five_days_at' => $due !== null && $due->lessThanOrEqualTo(now()->addDays(5)) ? now() : null,
            'reminded_two_days_at' => $due !== null && $due->lessThanOrEqualTo(now()->addDays(2)) ? now() : null,
        ]));

        if ($claimed === 1) {
            $this->tell(fn () => new DisputeOpened($dispute->fresh() ?? $dispute));
        }
    }

    /** @param  Closure(): Mailable  $mail */
    private function tell(Closure $mail): void
    {
        foreach ($this->staffToTell() as $email) {
            Mail::to($email)->queue($mail());
        }
    }

    /**
     * What goes to the processor, with the documents rendered only if it asks
     * for them and each one kept on the private disk the moment it has it.
     *
     * @param  array<string, string>  $fields
     * @param  array<string, mixed>  $enhanced
     * @param  list<string>  $files
     */
    private function package(Dispute $dispute, ?DisputeEvidence $evidence, array $fields, array $enhanced, array $files, string $message): EvidencePackage
    {
        $case = null;
        $uploads = collect((array) ($evidence->uploads ?? []));
        $round = (int) ($evidence->answer_round ?? 0);

        return new EvidencePackage(
            fields: $fields,
            enhanced: $enhanced,
            files: $files,
            render: function (string $kind) use ($dispute, &$case) {
                $case ??= CaseFile::for($dispute);

                return $this->documents->render($case, $kind);
            },
            uploaded: $uploads->map(fn (array $upload) => $upload['handle'] ?? null)->filter()->all(),
            remember: fn (string $kind, string $handle, ?EvidenceFile $file, ?string $of) => $this->remember($dispute, $kind, $handle, $file, $of),
            // A round the processor refused is spent, and the next try's keys
            // are new (EvidencePackage::spent); the first round's keys are the
            // plain ones.
            keyPrefix: 'myfiesta-dispute-'.$dispute->id.($round > 0 ? '-'.$round : ''),
            message: $message,
            digests: $uploads->map(fn (array $upload) => $upload['of'] ?? null)->filter()->all(),
            spent: function () use ($dispute): void {
                $this->evidenceFor($dispute)->increment('answer_round');
            },
        );
    }

    /**
     * Keep a handle the processor gave, straight away and on its own, with a
     * copy of the file on the private disk: this is what makes a second try
     * skip it, and what the page offers as "as sent". $of is a digest of what
     * the handle stands for, when a later try has to tell whether it still
     * does.
     */
    private function remember(Dispute $dispute, string $kind, string $handle, ?EvidenceFile $file, ?string $of = null): void
    {
        $entry = array_filter(['handle' => $handle, 'at' => now()->toIso8601String(), 'of' => $of], fn ($value) => $value !== null);

        if ($file !== null) {
            $path = 'disputes/'.$dispute->id.'/'.$kind.'-'.substr($file->sha256(), 0, 12).'.pdf';
            Storage::disk(self::DISK)->put($path, $file->bytes);

            $entry += ['name' => $file->name, 'sha256' => $file->sha256(), 'bytes' => $file->size(), 'path' => $path];
        }

        $evidence = $this->evidenceFor($dispute);

        $evidence->update(['uploads' => [...(array) $evidence->uploads, $kind => $entry]]);
    }

    /** The dispute's evidence row, made bare if an answer gets ahead of the draft. */
    private function evidenceFor(Dispute $dispute): DisputeEvidence
    {
        return DisputeEvidence::query()->firstOrCreate(['dispute_id' => $dispute->id], [
            'event_id' => $dispute->event_id,
            'kind' => Reasons::kind($dispute->reason),
            'fields' => [],
            'files' => [],
            'checklist' => [],
            'built_at' => now(),
        ]);
    }

    /**
     * The documents among these kinds, as the audit trail lists them: name,
     * checksum, size and the processor's id — never the file.
     *
     * @param  list<string>  $kinds
     * @return list<array<string, mixed>>
     */
    private function filesFor(Dispute $dispute, array $kinds): array
    {
        $uploads = (array) (DisputeEvidence::query()->where('dispute_id', $dispute->id)->first(['id', 'uploads'])->uploads ?? []);

        return self::describeFiles(array_intersect_key($uploads, array_flip($kinds)));
    }

    /**
     * @param  array<string, mixed>  $uploads  kind => what was kept of the upload
     * @return list<array<string, mixed>>
     */
    private static function describeFiles(array $uploads): array
    {
        $described = [];

        foreach ($uploads as $kind => $file) {
            // A document has a checksum; Paystack's evidence id is not one.
            if (is_array($file) && isset($file['sha256'])) {
                $described[] = [
                    'kind' => (string) $kind,
                    'name' => $file['name'] ?? null,
                    'sha256' => $file['sha256'],
                    'bytes' => $file['bytes'] ?? null,
                    'processor_file' => $file['handle'] ?? null,
                ];
            }
        }

        return $described;
    }

    /**
     * Run under the dispute's lock, against the dispute as it is now.
     *
     * @template T
     *
     * @param  Closure(Dispute): T  $work
     * @return T
     */
    private function exclusively(Dispute $dispute, Closure $work): mixed
    {
        $lock = Cache::lock('dispute-answer:'.$dispute->id, 300);

        if (! $lock->get()) {
            throw StaffActionRefused::because('Somebody else is answering this dispute right now. Wait a minute and reload the page.');
        }

        try {
            return $work($dispute->refresh());
        } finally {
            $lock->release();
        }
    }

    private function authorize(?User $staff): void
    {
        if (! self::mayAnswer($staff)) {
            throw StaffActionRefused::because('Only Admin and Finance can answer a dispute. Ask one of them.');
        }
    }

    private function stillOpenFor(Dispute $dispute, string $doing): void
    {
        if ($dispute->isAnswered()) {
            throw StaffActionRefused::because('This dispute has already been '.($dispute->response === Dispute::ACCEPTED ? 'accepted' : 'answered').', so it can no longer be '.$doing.'.');
        }

        if (! $dispute->isOpen()) {
            throw StaffActionRefused::because('This dispute has closed, so it can no longer be '.$doing.'.');
        }
    }

    private function gateway(Dispute $dispute): ?AnswersDisputes
    {
        $gateway = $this->gateways->all()[$dispute->gateway] ?? null;

        return $gateway instanceof AnswersDisputes ? $gateway : null;
    }

    private function gatewayOrRefuse(Dispute $dispute): AnswersDisputes
    {
        return $this->gateway($dispute) ?? throw StaffActionRefused::because('This dispute cannot be answered from here. Answer it in the payment processor\'s own dashboard.');
    }

    private function processorName(Dispute $dispute): string
    {
        return match ($dispute->gateway) {
            'stripe' => 'Stripe',
            'paystack' => 'Paystack',
            default => 'The payment processor',
        };
    }

    /** What the processor last said, from the evidence, for a rebuild that does not ask again. */
    private function stored(Dispute $dispute): ?ProcessorDispute
    {
        $facts = $dispute->evidence()->value('processor');
        $facts = is_string($facts) ? json_decode($facts, true) : $facts;

        if (! is_array($facts) || $facts === []) {
            return null;
        }

        $due = $facts['evidence_details']['due_by'] ?? $facts['due_at'] ?? null;

        return new ProcessorDispute(
            reference: (string) ($facts['id'] ?? $dispute->gateway_reference),
            status: $facts['status'] ?? null,
            reason: $facts['reason'] ?? $facts['category'] ?? null,
            networkReasonCode: isset($facts['network_reason_code']) ? (string) $facts['network_reason_code'] : null,
            amount: isset($facts['amount']) ? (int) $facts['amount'] : null,
            currency: $facts['currency'] ?? null,
            dueAt: is_string($due) && $due !== '' ? CarbonImmutable::parse($due) : null,
            facts: $facts,
            enhancedEligibility: array_values(array_filter((array) ($facts['enhanced_eligibility_types'] ?? []), 'is_string')),
        );
    }
}
