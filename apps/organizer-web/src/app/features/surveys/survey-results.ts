import { Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { UiStat } from '@myfiesta/ui';
import type { SurveyInsight, SurveyQuestionResult, SurveyResults } from '../../core/api.types';

/**
 * What the people who came said about one night: how many answered, the
 * recommend score, what to do about it, and each question's figures.
 *
 * Never who said it. The API sends counts and what was written, and holds
 * every figure back until enough people have answered that nobody's answer
 * can be picked out of it; this only draws what arrives, and says why a
 * figure is missing rather than showing a zero.
 */
@Component({
  selector: 'app-survey-results',
  imports: [RouterLink, UiStat],
  template: `
    @let r = results();

    <div class="stats mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
      <ui-stat label="Asked" [value]="r.invited" />
      <ui-stat label="Answered" [value]="r.responded" />
      <ui-stat label="Response rate" [value]="r.response_rate === null ? '—' : r.response_rate + '%'" />
      <ui-stat
        label="Recommend score"
        [value]="r.nps ? signed(r.nps.score) : '—'"
        [hint]="r.nps ? r.nps.promoters + ' would, ' + r.nps.detractors + ' would not' : 'From ' + r.minimum + ' answers'"
      />
    </div>

    @if (!r.enough) {
      <p class="few mb-6 max-w-[640px] rounded-md bg-surface-inset p-4 text-sm">
        @if (r.responded === 0) {
          Nobody has answered yet. Most answers come in the first day.
        } @else {
          {{ r.responded }} {{ r.responded === 1 ? 'person has' : 'people have' }} answered. The figures appear once
          {{ r.minimum }} have — with fewer, you could tell whose answer was whose.
        }
      </p>
    }

    @if (r.insights.length > 0) {
      <h2 class="section mb-3 text-xs font-semibold uppercase tracking-[0.08em] text-text-subtle">What to do next</h2>
      <ul class="insights m-0 mb-8 grid max-w-[760px] list-none gap-3 p-0">
        @for (insight of r.insights; track $index) {
          <li
            class="insight grid gap-1 rounded-(--radius-card) border border-border-subtle border-l-4 bg-surface-raised p-4"
            [class.border-l-warning]="insight.tone === 'warning'"
            [class.border-l-success]="insight.tone === 'good'"
            [class.border-l-border-strong]="insight.tone === 'neutral'"
          >
            <p class="m-0 font-semibold">{{ insight.title }}</p>
            <p class="m-0 text-sm text-text-muted">{{ insight.action }}</p>
            @if (insight.tab; as tab) {
              <a class="text-sm font-medium text-primary-text underline underline-offset-2" [routerLink]="['/events', eventId(), tab]">{{ tabLabel(insight) }}</a>
            }
          </li>
        }
      </ul>
    }

    <h2 class="section mb-3 text-xs font-semibold uppercase tracking-[0.08em] text-text-subtle">Question by question</h2>
    <ol class="questions m-0 grid max-w-[760px] list-none gap-3 p-0">
      @for (q of r.questions; track q.id) {
        <li class="question grid gap-3 rounded-(--radius-card) border border-border-subtle bg-surface-raised p-4 shadow-(--shadow-card)">
          <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <p class="m-0 min-w-0 flex-1 font-semibold [overflow-wrap:anywhere]">{{ q.label }}</p>
            <p class="m-0 text-xs text-text-subtle tabular-nums">{{ q.answered }} answered</p>
          </div>

          @if (!q.shown) {
            <p class="hidden-figure m-0 text-sm text-text-muted">Shown once {{ r.minimum }} people have answered this one.</p>
          } @else {
            @if (q.average !== null) {
              <p class="m-0 text-sm">
                Average <strong class="text-lg tabular-nums">{{ q.average.toFixed(1) }}</strong>
                <span class="text-text-muted"> out of {{ q.type === 'nps' ? 10 : 5 }}</span>
              </p>
            }

            @if (q.distribution; as spread) {
              <ul class="spread m-0 grid list-none gap-1 p-0" [attr.aria-label]="'How the answers to “' + q.label + '” spread'">
                @for (bar of spread; track bar.label) {
                  <li class="grid grid-cols-[minmax(2rem,10rem)_1fr_2.5rem] items-center gap-3 text-sm">
                    <span class="min-w-0 truncate" [title]="bar.label">{{ bar.label }}</span>
                    <span class="block h-2 overflow-hidden rounded-full bg-surface-inset">
                      <span class="block h-full rounded-full bg-primary" [style.width.%]="share(bar.count, q)"></span>
                    </span>
                    <span class="text-right text-text-muted tabular-nums">{{ bar.count }}</span>
                  </li>
                }
              </ul>
            }

            @if (q.texts; as texts) {
              <ul class="texts m-0 grid max-h-96 list-none gap-2 overflow-y-auto p-0">
                @for (text of texts; track $index) {
                  <li class="whitespace-pre-line rounded-md bg-surface-inset px-3 py-2 text-sm [overflow-wrap:anywhere]">{{ text }}</li>
                }
              </ul>
            }
          }
        </li>
      }
    </ol>
  `,
})
export class SurveyResultsView {
  readonly results = input.required<SurveyResults>();
  readonly eventId = input.required<string>();

  /** A recommend score reads as a change from zero: +32, -8. */
  signed(score: number): string {
    return score > 0 ? `+${score}` : String(score);
  }

  /** The bar's length against the most common answer, so the shape reads at a glance. */
  share(count: number, question: SurveyQuestionResult): number {
    const most = Math.max(...(question.distribution ?? []).map((bar) => bar.count), 0);
    return most === 0 ? 0 : (count / most) * 100;
  }

  tabLabel(insight: SurveyInsight): string {
    return insight.tab === 'door' ? 'Open the door settings' : insight.tab === 'tickets' ? 'Open the tickets' : 'Open it';
  }
}
