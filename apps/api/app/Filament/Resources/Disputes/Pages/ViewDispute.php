<?php

namespace App\Filament\Resources\Disputes\Pages;

use App\Filament\Resources\Disputes\DisputeResource;
use App\Filament\Resources\Disputes\Schemas\DisputeInfolist;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Outcome;
use App\Models\Dispute;
use App\Models\User;
use App\Services\Disputes\CaseFile;
use App\Services\Disputes\DisputeDesk;
use App\Services\Disputes\EvidenceDraft;
use App\Services\StaffSupport\StaffActionRefused;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * One chargeback: the deadline, what the bank was told, what the records
 * hold, every field that will be sent — editable until it is — and the
 * buttons that send it or concede it.
 *
 * The fields are a form of their own, saved as a draft; sending saves them
 * first, so what goes is what is on the screen. Support sees all of it and
 * changes none of it.
 *
 * @property Dispute $record
 */
class ViewDispute extends ViewRecord
{
    protected static string $resource = DisputeResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->fillEvidence();
    }

    public function getTitle(): string
    {
        return 'Chargeback on order '.($this->dispute()->order->reference ?? '—');
    }

    /** The deadline, before anything else, in red when it is close. */
    public function getSubheading(): string|Htmlable|null
    {
        $dispute = $this->dispute();
        $due = $dispute->evidence_due_at;

        $line = match (true) {
            ! $dispute->isOpen() => 'Closed. '.DisputeInfolist::outcome($dispute).'.',
            $dispute->response === Dispute::SUBMITTED => 'Evidence sent '.CaseFile::at($dispute->responded_at).'. It is for the bank to decide now.',
            $dispute->response === Dispute::ACCEPTED => 'Accepted '.CaseFile::at($dispute->responded_at).'. Waiting for the processor to close it.',
            $due === null => 'The processor gave no deadline. Check with it before relying on one.',
            default => 'Answer by '.$due->format('l j F Y, H:i').' UTC — '.DisputeInfolist::timeLeft($dispute),
        };

        return DisputeInfolist::urgency($dispute) === 'danger'
            ? new HtmlString('<span style="color: var(--danger-600); font-weight: 600">'.e($line).'</span>')
            : $line;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->submitAction(),
            $this->acceptAction(),

            Action::make('rebuild')
                ->label('Rebuild from the records')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn () => $this->mayAnswer() && $this->stillOpen())
                ->authorize(fn () => $this->mayAnswer())
                ->requiresConfirmation()
                ->modalHeading('Put the evidence together again?')
                ->modalDescription('Reads the records as they are now and replaces every field on this page. Anything you have typed is lost.')
                ->modalSubmitActionLabel('Rebuild')
                ->action(function (Action $action) {
                    Outcome::run($action, 'Evidence put together again', function (User $staff) {
                        app(DisputeDesk::class)->rebuild($this->dispute(), $staff);

                        return 'The fields now read as the records do.';
                    });

                    $this->afterwards();
                }),

            Action::make('askProcessor')
                ->label(fn () => 'Check with '.ucfirst((string) $this->dispute()->gateway))
                ->icon(Heroicon::OutlinedCloudArrowDown)
                ->color('gray')
                ->visible(fn () => $this->mayAnswer() && $this->dispute()->isOpen())
                ->authorize(fn () => $this->mayAnswer())
                ->requiresConfirmation()
                ->modalHeading(fn () => 'Ask '.ucfirst((string) $this->dispute()->gateway).' where this dispute stands?')
                ->modalDescription('Reads the processor’s answer and updates this page with it. Nothing is sent to the buyer or their bank.')
                ->modalSubmitActionLabel('Check now')
                ->action(function (Action $action) {
                    Outcome::run($action, 'Checked', function () {
                        $answer = app(DisputeDesk::class)->refresh($this->dispute());

                        return $answer === null
                            ? Notification::make()->title('The processor did not answer')->body('Nothing has changed. Try again in a few minutes.')->warning()
                            : 'It says: '.str_replace(['_', '-'], ' ', (string) ($answer->status ?? 'nothing new')).'.';
                    });

                    $this->afterwards();
                }),

            Action::make('openOrder')
                ->label('Open order')
                ->icon(Heroicon::OutlinedShoppingBag)
                ->color('gray')
                ->visible(fn () => OrderResource::canViewAny())
                ->url(fn () => OrderResource::getUrl('view', ['record' => $this->dispute()->order_id])),
        ];
    }

    private function submitAction(): Action
    {
        return Action::make('submit')
            ->label('Submit evidence')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn () => $this->mayEdit())
            ->authorize(fn () => $this->mayAnswer())
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedPaperAirplane)
            ->modalHeading(fn () => 'Send the evidence to '.ucfirst((string) $this->dispute()->gateway).'?')
            ->modalDescription(fn () => 'The fields as they are on this page, saved first, and '
                .count((array) ($this->dispute()->evidence->files ?? [])).' document(s). '
                .'This is the answer: it goes to the buyer\'s bank, and it cannot be changed or sent again afterwards.')
            ->modalSubmitActionLabel('Send it')
            ->action(function (Action $action) {
                $this->saveDraft(quietly: true);

                Outcome::run($action, 'Evidence sent', fn (User $staff) => app(DisputeDesk::class)->submit($this->dispute(), $staff));

                $this->afterwards();
            });
    }

    private function acceptAction(): Action
    {
        return Action::make('accept')
            ->label('Accept the dispute')
            ->icon(Heroicon::OutlinedHandRaised)
            ->color('danger')
            ->visible(fn () => $this->mayAnswer() && $this->stillOpen())
            ->authorize(fn () => $this->mayAnswer())
            ->modalHeading('Accept the dispute?')
            ->modalDescription(fn () => 'The buyer keeps the money: '.ucfirst((string) $this->dispute()->gateway).' returns it to them, '
                .'and the chargeback comes off the organizer\'s balance and stops the tickets when it confirms. Nothing more can be sent afterwards.')
            ->modalSubmitActionLabel('Accept')
            ->schema([
                Textarea::make('note')
                    ->label('Why')
                    ->required()
                    ->minLength(3)
                    ->maxLength(1000)
                    ->rows(3)
                    ->helperText('Kept in the audit trail. Paystack is sent it as the note with the answer.'),
            ])
            ->action(function (Action $action, array $data) {
                Outcome::run($action, 'Dispute accepted', fn (User $staff) => app(DisputeDesk::class)->accept($this->dispute(), $staff, (string) $data['note']));

                $this->afterwards();
            });
    }

    /** The fields that will be sent, one input each, editable until the dispute is answered. */
    public function form(Schema $schema): Schema
    {
        $dispute = $this->dispute();
        $fields = (array) ($dispute->evidence->fields ?? []);
        $processor = ucfirst((string) $dispute->gateway);

        return $schema
            ->statePath('data')
            ->disabled(! $this->mayEdit())
            ->columns(1)
            ->components([
                Section::make('What will be sent to '.$processor)
                    ->description($this->mayEdit()
                        ? 'Every field below goes to '.$processor.' as it reads here. Each was written from the records; correct anything that is wrong, and leave out nothing that is true. An empty field is not sent.'
                        : ($dispute->isAnswered() || ! $dispute->isOpen() ? 'As it stood when the dispute was answered or closed.' : 'Only Admin and Finance can change these.'))
                    ->schema($fields === []
                        ? [Text::make('Nothing has been put together yet.')]
                        : array_map(fn (string $name) => $this->field($name), array_keys($fields))),
            ]);
    }

    /** The overview above the fields. */
    public function infolist(Schema $schema): Schema
    {
        return $schema->components(DisputeInfolist::overview());
    }

    /** Everything below the fields. */
    public function details(Schema $schema): Schema
    {
        return $schema->record($this->dispute())->components(DisputeInfolist::details());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('infolist'),
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                // To the question, not to saveDraft(): see confirmSaveDraft().
                ->livewireSubmitHandler('confirmSaveDraft')
                ->footer([
                    Actions::make([
                        Action::make('saveDraft')
                            ->label('Save draft')
                            ->submit('confirmSaveDraft')
                            ->visible(fn () => $this->mayEdit())
                            ->keyBindings(['mod+s']),
                    ])->key('evidence-actions'),
                ]),
            EmbeddedSchema::make('details'),
        ]);
    }

    /**
     * Asked before the draft is kept, like every other write here — and
     * saying plainly that keeping it sends nothing, since the next button
     * along is the one that does.
     */
    public function confirmSaveDraft(): void
    {
        if (! $this->mayEdit()) {
            abort(403);
        }

        $this->form->validate();

        $this->mountAction('confirmSaveDraft');
    }

    public function confirmSaveDraftAction(): Action
    {
        return Action::make('confirmSaveDraft')
            ->requiresConfirmation()
            ->modalHeading('Save the evidence as a draft?')
            ->modalDescription(fn () => 'The fields are kept as they read now, for you or a colleague to finish. Nothing goes to '.ucfirst((string) $this->dispute()->gateway).' until the evidence is submitted.')
            ->modalSubmitActionLabel('Save draft')
            ->action(fn () => $this->saveDraft());
    }

    /** Keep the words as they are on the screen. */
    public function saveDraft(bool $quietly = false): void
    {
        $staff = auth()->user();

        if (! $staff instanceof User || ! $this->mayEdit()) {
            abort(403);
        }

        try {
            $changed = app(DisputeDesk::class)->saveDraft($this->dispute(), (array) $this->form->getState(), $staff);
        } catch (StaffActionRefused $refused) {
            Notification::make()->title('Not saved')->body($refused->getMessage())->danger()->send();

            return;
        }

        $this->afterwards();

        if (! $quietly) {
            Notification::make()
                ->title($changed === [] ? 'Nothing had changed' : 'Draft saved')
                ->success()
                ->send();
        }
    }

    private function field(string $name): Textarea|TextInput
    {
        $spec = EvidenceDraft::FIELDS[$name] ?? ['label' => str_replace('_', ' ', $name), 'long' => true, 'max' => 5000];

        $input = $spec['long']
            ? Textarea::make($name)->rows(4)->autosize()
            : TextInput::make($name);

        return $input
            ->label($spec['label'])
            ->maxLength($spec['max'])
            ->helperText($name === 'uncategorized_text' || $name === 'service_details'
                ? 'Short and factual, oldest first: this is what the bank reads first.'
                : null);
    }

    /** Read the dispute again after something changed it, and refill the words. */
    private function afterwards(): void
    {
        $this->dispute()->refresh()->unsetRelation('evidence');
        $this->fillEvidence();
    }

    private function fillEvidence(): void
    {
        $this->form->fill((array) ($this->dispute()->evidence()->first()->fields ?? []));
    }

    private function dispute(): Dispute
    {
        /** @var Dispute $dispute */
        $dispute = $this->getRecord();

        return $dispute;
    }

    private function mayAnswer(): bool
    {
        $user = auth()->user();

        return DisputeDesk::mayAnswer($user instanceof User ? $user : null);
    }

    private function stillOpen(): bool
    {
        return $this->dispute()->isOpen() && ! $this->dispute()->isAnswered();
    }

    /** Whether the words can still change: Admin or Finance, the dispute open and unanswered, and a draft to change. */
    private function mayEdit(): bool
    {
        return $this->mayAnswer() && $this->stillOpen() && $this->dispute()->evidence()->exists();
    }
}
