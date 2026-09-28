import { Component, computed, input, model } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AnswerValue, Question } from '../../core/api.types';

/**
 * One question an organizer asked, rendered the way it is answered.
 *
 * Four shapes from one row: words, one of a list, several of a list, or yes
 * and no. A component rather than four blocks in the checkout template,
 * because the same four appear again beside every ticket on an order — six
 * people at a table is six copies of whatever the organizer asks each guest.
 *
 * The value goes out as the server wants to store it: a string, a list of
 * strings, or a boolean. Nothing here decides whether an answer is acceptable
 * — that is read off the database on the way in, where a client cannot argue
 * with it.
 */
@Component({
  selector: 'app-question-field',
  imports: [FormsModule],
  template: `
    @let q = question();

    @switch (q.type) {
      @case ('boolean') {
        <label class="flex cursor-pointer items-start gap-3 text-sm font-normal leading-[1.35]">
          <input
            class="check mt-[2px]"
            type="checkbox"
            [attr.name]="name()"
            [name]="name()"
            [ngModel]="value() === true"
            (ngModelChange)="value.set($event)"
          />
          <span>
            {{ q.label }}
            @if (!q.required) {
              <span class="optional">(optional)</span>
            }
          </span>
        </label>
      }

      @case ('choice') {
        <fieldset class="field">
          <legend class="text-sm font-semibold">
            {{ q.label }}
            @if (!q.required) {
              <span class="optional">(optional)</span>
            }
          </legend>
          @for (option of q.options; track option) {
            <label class="flex cursor-pointer items-center gap-2 text-sm font-normal">
              <input
                class="radio"
                type="radio"
                [attr.name]="name()"
                [name]="name()"
                [value]="option"
                [ngModel]="value()"
                (ngModelChange)="value.set($event)"
              />
              {{ option }}
            </label>
          }
        </fieldset>
      }

      @case ('multi_choice') {
        <fieldset class="field">
          <legend class="text-sm font-semibold">
            {{ q.label }}
            @if (!q.required) {
              <span class="optional">(optional)</span>
            }
          </legend>
          <!-- Each box is its own control with a shared answer: ngModel on a
               checkbox holds a boolean, and what the server stores is the list
               of the ones that are true. -->
          @for (option of q.options; track option) {
            <label class="flex cursor-pointer items-center gap-2 text-sm font-normal">
              <input
                class="check"
                type="checkbox"
                [attr.name]="name()"
                [name]="name() + ':' + option"
                [ngModel]="chosen().includes(option)"
                (ngModelChange)="toggle(option, $event)"
              />
              {{ option }}
            </label>
          }
        </fieldset>
      }

      @default {
        <div class="field">
          <label [for]="name()">
            {{ q.label }}
            @if (!q.required) {
              <span class="optional">(optional)</span>
            }
          </label>
          <input
            [id]="name()"
            [attr.name]="name()"
            [name]="name()"
            type="text"
            maxlength="500"
            [required]="q.required"
            [ngModel]="value() ?? ''"
            (ngModelChange)="value.set($event)"
          />
        </div>
      }
    }
  `,
})
export class QuestionField {
  readonly question = input.required<Question>();

  /**
   * Which copy of this question it is.
   *
   * The same question is asked about every person on the order, so a name
   * unique to the answer — not to the question — is what keeps two radio
   * groups from becoming one, and two labels from pointing at the same box.
   */
  readonly slot = input('');

  readonly value = model<AnswerValue | null>(null);

  /*
   * Why every control below binds `name` twice.
   *
   * `[name]` is claimed by ngModel: the directive declares its own name input,
   * so the binding registers the control inside the surrounding form and never
   * reaches the element. Angular's radio registry groups by that name, so the
   * answers behave — but the DOM is left with no name at all, and a browser
   * with no name has no radio group: arrow keys stop moving between options
   * and a screen reader announces each one as "1 of 1".
   */

    /**
   * The id and the group name for this copy of the question.
   *
   * Separated, because two ids pasted together are one id somebody has to
   * squint at — and, with no separator, two different pairs could in
   * principle produce the same string.
   */
  protected readonly name = computed(() =>
    this.slot() === '' ? `q-${this.question().id}` : `q-${this.slot()}-${this.question().id}`,
  );

  /** The list form, for the boxes to read. An unanswered question is none of them. */
  protected readonly chosen = computed(() => {
    const value = this.value();

    return Array.isArray(value) ? (value as string[]) : [];
  });

  protected toggle(option: string, on: boolean): void {
    const chosen = this.chosen().filter((one) => one !== option);

    // Kept in the organizer's order rather than the order they were ticked:
    // the export reads better, and so does the answer on a door screen.
    const next = on
      ? this.question().options.filter((one) => one === option || chosen.includes(one))
      : chosen;

    this.value.set(next.length > 0 ? next : null);
  }
}
