import { Component, ElementRef, booleanAttribute, input, output, viewChild } from '@angular/core';
import { ImagePlus, RefreshCw, Trash2 } from 'lucide-angular';
import { MfIcon } from './icon';

/**
 * A picture, chosen from the phone.
 *
 * A file input underneath, which on iOS and Android is already the right
 * thing: it offers the camera, the photo library and Files, with the
 * platform's own permission prompts, and nothing here has to know which was
 * used. The frame keeps its shape before and after — a poster slot is a
 * poster's proportions whether or not a poster is in it — and shows the
 * upload's progress over the picture rather than beside it.
 */
@Component({
  selector: 'mf-image-pick',
  imports: [MfIcon],
  template: `
    <div class="frame" [style.aspect-ratio]="ratio()" [class.filled]="!!src()">
      @if (src()) {
        <img [src]="src()" alt="" />
      } @else {
        <button type="button" class="empty" (click)="choose()" [disabled]="busy()">
          <mf-icon [icon]="addIcon" size="lg" />
          <span class="label">{{ label() }}</span>
          @if (hint()) {
            <span class="hint">{{ hint() }}</span>
          }
        </button>
      }

      @if (busy()) {
        <div class="progress" role="progressbar" [attr.aria-valuenow]="progress()" aria-valuemin="0" aria-valuemax="100">
          <span class="ring" [style.--mf-upload]="progress() ?? 0"></span>
          <span class="pct">{{ progress() === null ? '' : progress() + '%' }}</span>
        </div>
      }
    </div>

    @if (src() && !busy()) {
      <div class="actions">
        <button type="button" class="action" (click)="choose()">
          <mf-icon [icon]="replaceIcon" size="sm" /> Replace
        </button>
        @if (removable()) {
          <button type="button" class="action danger" (click)="removed.emit()">
            <mf-icon [icon]="removeIcon" size="sm" /> Remove
          </button>
        }
      </div>
    }

    <input #file class="file" type="file" accept="image/jpeg,image/png,image/webp,image/heic" (change)="picked($event)" />
  `,
  styles: `
    :host {
      display: grid;
      gap: var(--space-2);
    }

    .frame {
      position: relative;
      overflow: hidden;
      border-radius: var(--radius-card);
      background: var(--surface-inset);
      box-shadow: inset 0 0 0 1px var(--border);
    }

    .frame.filled {
      box-shadow: var(--shadow-raised);
    }

    img {
      display: block;
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .empty {
      display: grid;
      place-content: center;
      justify-items: center;
      gap: var(--space-2);
      width: 100%;
      height: 100%;
      padding: var(--space-5);
      border: 0;
      border-radius: inherit;
      background:
        repeating-linear-gradient(135deg, transparent 0 10px, color-mix(in srgb, var(--border) 45%, transparent) 10px 11px),
        var(--surface-inset);
      color: var(--text-muted);
      font: inherit;
      text-align: center;
      cursor: pointer;
    }

    .empty:active {
      background-color: var(--surface-hover);
    }

    .label {
      font-weight: var(--font-weight-semibold);
      color: var(--text);
    }

    .hint {
      font-size: var(--font-size-sm);
    }

    .progress {
      position: absolute;
      inset: 0;
      display: grid;
      place-items: center;
      background: rgb(4 8 5 / 0.55);
      backdrop-filter: blur(4px);
      color: var(--color-neutral-0);
    }

    .ring {
      grid-area: 1 / 1;
      width: 56px;
      height: 56px;
      border-radius: var(--radius-full);
      background: conic-gradient(var(--color-neutral-0) calc(var(--mf-upload) * 1%), rgb(255 255 255 / 0.2) 0);
      mask: radial-gradient(farthest-side, transparent calc(100% - 5px), black 0);
    }

    .pct {
      grid-area: 1 / 1;
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-semibold);
      font-variant-numeric: tabular-nums;
    }

    .actions {
      display: flex;
      gap: var(--space-2);
    }

    .action {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      height: 40px;
      padding: 0 var(--space-4);
      border: 0;
      border-radius: var(--radius-full);
      background: var(--surface-raised);
      box-shadow: inset 0 0 0 1px var(--border);
      color: var(--text);
      font: inherit;
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-medium);
      cursor: pointer;
    }

    .action.danger {
      color: var(--danger-text);
    }

    .file {
      display: none;
    }
  `,
})
export class MfImagePick {
  readonly src = input<string | null>(null);
  readonly label = input('Add a picture');
  readonly hint = input<string | null>(null);
  /** Width over height, as CSS writes it: "4 / 5" for a poster, "16 / 9" for a banner. */
  readonly ratio = input('4 / 5');
  readonly busy = input(false, { transform: booleanAttribute });
  /** 0–100 while uploading, null while it is still being prepared. */
  readonly progress = input<number | null>(null);
  readonly removable = input(false, { transform: booleanAttribute });

  readonly chosen = output<File>();
  readonly removed = output<void>();

  protected readonly addIcon = ImagePlus;
  protected readonly replaceIcon = RefreshCw;
  protected readonly removeIcon = Trash2;

  private readonly file = viewChild.required<ElementRef<HTMLInputElement>>('file');

  choose(): void {
    this.file().nativeElement.click();
  }

  protected picked(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    // Cleared, so choosing the same picture again still counts as a choice.
    input.value = '';

    if (file) this.chosen.emit(file);
  }
}
