import {
  Component,
  ElementRef,
  OnDestroy,
  afterNextRender,
  computed,
  effect,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { UiIcon, type LucideIconData } from '@myfiesta/ui';
import {
  Bold,
  Heading3,
  Italic,
  Link,
  List,
  ListOrdered,
  Redo2,
  TextQuote,
  Underline,
  Undo2,
  Unlink,
} from 'lucide-angular';

/** One toolbar control. */
interface Tool {
  readonly id: string;
  readonly label: string;
  readonly shortcut?: string;
  readonly icon: LucideIconData;
  readonly run: (editor: Editor) => void;
  readonly active?: (editor: Editor) => boolean;
}

/**
 * Formatted text for an event description.
 *
 * Built on Tiptap, over ProseMirror, rather than a contenteditable driven by
 * execCommand. execCommand is deprecated and writes different markup in every
 * browser — Chrome makes divs where Firefox makes breaks — whereas this
 * editor can only produce the elements its schema defines, and those are
 * chosen to match what the server's sanitizer allows. What an organizer can
 * make here is exactly what survives saving; nothing they format vanishes on
 * the way to the page.
 *
 * The editing surface wears the same .rich class as the public page, so a
 * lineup looks in the console the way it will look to a buyer.
 *
 * Deliberately short on tools. A description needs emphasis, a heading for a
 * lineup, lists, a quote and links; colours, fonts and tables are how event
 * pages end up unreadable on a phone, and the sanitizer would remove them
 * anyway.
 */
@Component({
  selector: 'app-rich-text-editor',
  imports: [UiIcon],
  template: `
    <div
      class="overflow-hidden rounded-md border border-field-border bg-surface-raised transition-[border-color,box-shadow] duration-(--motion-fast) focus-within:border-primary focus-within:shadow-(--focus-ring)"
    >
      <div
        class="flex flex-wrap items-center gap-0.5 border-b border-border bg-surface px-1.5 py-1"
        role="toolbar"
        [attr.aria-label]="'Formatting for ' + label()"
        [attr.aria-controls]="inputId()"
      >
        @for (tool of tools; track tool.id) {
          <button
            type="button"
            class="grid h-8 w-8 cursor-pointer place-items-center rounded border-0 [font-family:inherit] disabled:cursor-not-allowed disabled:opacity-35"
            [class]="isActive(tool) ? 'bg-primary-soft text-primary-soft-text' : 'bg-transparent text-text-muted hover:bg-surface-hover hover:text-text'"
            [attr.aria-label]="tool.label"
            [attr.aria-pressed]="tool.active ? isActive(tool) : null"
            [title]="tool.shortcut ? tool.label + ' (' + tool.shortcut + ')' : tool.label"
            [disabled]="!ready()"
            (mousedown)="$event.preventDefault()"
            (click)="runTool(tool)"
          >
            <ui-icon [icon]="tool.icon" size="sm" />
          </button>

          @if (tool.id === 'underline') {
            <span class="mx-1 h-5 w-px bg-border" aria-hidden="true"></span>
          }
        }

        <span class="mx-1 h-5 w-px bg-border" aria-hidden="true"></span>

        <button
          type="button"
          class="grid h-8 w-8 cursor-pointer place-items-center rounded border-0 [font-family:inherit] disabled:cursor-not-allowed disabled:opacity-35"
          [class]="linkActive() ? 'bg-primary-soft text-primary-soft-text' : 'bg-transparent text-text-muted hover:bg-surface-hover hover:text-text'"
          aria-label="Link"
          [attr.aria-pressed]="linkActive()"
          [attr.aria-expanded]="linking()"
          title="Link (Ctrl+K)"
          [disabled]="!ready()"
          (mousedown)="$event.preventDefault()"
          (click)="openLink()"
        >
          <ui-icon [icon]="linkIcon" size="sm" />
        </button>

        <span class="ml-auto flex gap-0.5">
          <button
            type="button"
            class="grid h-8 w-8 cursor-pointer place-items-center rounded border-0 bg-transparent text-text-muted hover:bg-surface-hover hover:text-text disabled:cursor-not-allowed disabled:opacity-35"
            aria-label="Undo"
            title="Undo (Ctrl+Z)"
            [disabled]="!canUndo()"
            (mousedown)="$event.preventDefault()"
            (click)="undo()"
          >
            <ui-icon [icon]="undoIcon" size="sm" />
          </button>
          <button
            type="button"
            class="grid h-8 w-8 cursor-pointer place-items-center rounded border-0 bg-transparent text-text-muted hover:bg-surface-hover hover:text-text disabled:cursor-not-allowed disabled:opacity-35"
            aria-label="Redo"
            title="Redo (Ctrl+Shift+Z)"
            [disabled]="!canRedo()"
            (mousedown)="$event.preventDefault()"
            (click)="redo()"
          >
            <ui-icon [icon]="redoIcon" size="sm" />
          </button>
        </span>
      </div>

      <!--
        Where a link is written. Inline under the toolbar rather than a
        window.prompt: a prompt cannot be styled, blocks the page, and on a
        phone covers the text the link is being attached to.
      -->
      @if (linking()) {
        <div class="flex flex-wrap items-center gap-2 border-b border-border bg-surface-inset px-3 py-2">
          <label class="sr-only" [attr.for]="inputId() + '-link'">Link address</label>
          <input
            #linkField
            class="h-9 min-w-0 flex-1 text-sm"
            type="url"
            inputmode="url"
            placeholder="https://instagram.com/yournight"
            [id]="inputId() + '-link'"
            [value]="linkDraft()"
            (input)="linkDraft.set($any($event.target).value)"
            (keydown.enter)="applyLink(); $event.preventDefault()"
            (keydown.escape)="closeLink(); $event.preventDefault()"
          />
          <button
            type="button"
            class="h-9 cursor-pointer rounded-md border-0 bg-primary px-3 text-sm font-semibold text-on-primary [font-family:inherit] hover:bg-primary-hover"
            (click)="applyLink()"
          >
            Apply
          </button>
          @if (linkActive()) {
            <button
              type="button"
              class="inline-flex h-9 cursor-pointer items-center gap-1 rounded-md border border-border bg-surface px-3 text-sm text-text-muted [font-family:inherit] hover:bg-surface-hover hover:text-text"
              (click)="removeLink()"
            >
              <ui-icon [icon]="unlinkIcon" size="sm" />
              Remove
            </button>
          }
          <button
            type="button"
            class="h-9 cursor-pointer border-0 bg-transparent px-2 text-sm text-text-muted underline underline-offset-2 [font-family:inherit] hover:text-text"
            (click)="closeLink()"
          >
            Cancel
          </button>
          @if (linkError(); as message) {
            <p class="basis-full text-xs text-danger" role="alert">{{ message }}</p>
          }
        </div>
      }

      <div class="relative">
        <!-- Placeholder drawn over an empty document rather than as a
             ProseMirror decoration, which would need another package. -->
        @if (empty()) {
          <p class="pointer-events-none absolute left-4 top-3 text-sm text-text-subtle" aria-hidden="true">
            {{ placeholder() }}
          </p>
        }
        <div #surface></div>
      </div>

      <div class="flex items-center justify-between gap-3 border-t border-border-subtle px-3 py-1.5 text-xs text-text-subtle">
        <span>Ctrl+B bold · Ctrl+I italic · type "- " to start a list</span>
        <!-- Measured the way the server measures it: the saved markup, which
             is what the limit applies to. -->
        <span class="tabular-nums" [class.text-danger]="overLimit()">
          {{ characters().toLocaleString('en-CA') }} characters
          @if (overLimit()) { — too long to save }
        </span>
      </div>
    </div>
  `,
})
export class RichTextEditor implements OnDestroy {
  /** The HTML to show. An empty string means no description. */
  readonly value = input('');
  readonly valueChange = output<string>();

  readonly inputId = input.required<string>();
  readonly label = input('Description');
  readonly placeholder = input('What should people know before they buy?');

  /** The server's limit on the saved markup. */
  readonly maxLength = input(20000);

  private readonly surface = viewChild.required<ElementRef<HTMLElement>>('surface');
  private readonly linkField = viewChild<ElementRef<HTMLInputElement>>('linkField');

  editor: Editor | null = null;

  readonly ready = signal(false);
  readonly empty = signal(true);
  readonly characters = signal(0);
  readonly overLimit = computed(() => this.characters() > this.maxLength());

  readonly canUndo = signal(false);
  readonly canRedo = signal(false);

  /** Bumped on every transaction, so active states re-read the editor. */
  private readonly tick = signal(0);

  readonly linking = signal(false);
  readonly linkDraft = signal('');
  readonly linkError = signal<string | null>(null);
  readonly linkActive = computed(() => {
    this.tick();
    return this.editor?.isActive('link') ?? false;
  });

  /** The last HTML this editor emitted, so an echo of it is not reloaded. */
  private lastEmitted = '';

  protected readonly linkIcon = Link;
  protected readonly unlinkIcon = Unlink;
  protected readonly undoIcon = Undo2;
  protected readonly redoIcon = Redo2;

  readonly tools: Tool[] = [
    {
      id: 'bold',
      label: 'Bold',
      shortcut: 'Ctrl+B',
      icon: Bold,
      run: (e) => e.chain().focus().toggleBold().run(),
      active: (e) => e.isActive('bold'),
    },
    {
      id: 'italic',
      label: 'Italic',
      shortcut: 'Ctrl+I',
      icon: Italic,
      run: (e) => e.chain().focus().toggleItalic().run(),
      active: (e) => e.isActive('italic'),
    },
    {
      id: 'underline',
      label: 'Underline',
      shortcut: 'Ctrl+U',
      icon: Underline,
      run: (e) => e.chain().focus().toggleUnderline().run(),
      active: (e) => e.isActive('underline'),
    },
    {
      id: 'heading',
      label: 'Heading',
      icon: Heading3,
      run: (e) => e.chain().focus().toggleHeading({ level: 3 }).run(),
      // Either level counts: descriptions imported from the old platform
      // carry h2, and the button should say they are headings too.
      active: (e) => e.isActive('heading'),
    },
    {
      id: 'bullets',
      label: 'Bulleted list',
      icon: List,
      run: (e) => e.chain().focus().toggleBulletList().run(),
      active: (e) => e.isActive('bulletList'),
    },
    {
      id: 'numbers',
      label: 'Numbered list',
      icon: ListOrdered,
      run: (e) => e.chain().focus().toggleOrderedList().run(),
      active: (e) => e.isActive('orderedList'),
    },
    {
      id: 'quote',
      label: 'Quote',
      icon: TextQuote,
      run: (e) => e.chain().focus().toggleBlockquote().run(),
      active: (e) => e.isActive('blockquote'),
    },
  ];

  constructor() {
    afterNextRender(() => this.create());

    // A value arriving after the editor exists — the edit form loads the event
    // asynchronously — replaces the content, unless it is this editor's own
    // output coming back round, which would reset the cursor on every key.
    effect(() => {
      const next = this.value() ?? '';

      if (!this.editor || next === this.lastEmitted) return;

      this.editor.commands.setContent(next, { emitUpdate: false });
      this.lastEmitted = next;
      this.sync();
    });
  }

  ngOnDestroy(): void {
    this.editor?.destroy();
    this.editor = null;
  }

  isActive(tool: Tool): boolean {
    this.tick();

    return !!(this.editor && tool.active?.(this.editor));
  }

  undo(): void {
    this.editor?.chain().focus().undo().run();
  }

  redo(): void {
    this.editor?.chain().focus().redo().run();
  }

  runTool(tool: Tool): void {
    if (this.editor) tool.run(this.editor);
  }

  // --- links -----------------------------------------------------------------

  openLink(): void {
    if (!this.editor) return;

    this.linkDraft.set(this.editor.getAttributes('link')['href'] ?? '');
    this.linkError.set(null);
    this.linking.set(true);

    queueMicrotask(() => this.linkField()?.nativeElement.focus());
  }

  closeLink(): void {
    this.linking.set(false);
    this.linkError.set(null);
    this.editor?.commands.focus();
  }

  /**
   * Attach the address to the selection, or insert it as its own text when
   * nothing is selected — a link with no words to click is not a link.
   */
  applyLink(): void {
    const editor = this.editor;

    if (!editor) return;

    const href = this.normaliseHref(this.linkDraft());

    if (href === null) {
      this.linkError.set('Use a web address (https://…) or an email address.');

      return;
    }

    if (href === '') {
      this.removeLink();

      return;
    }

    const { empty } = editor.state.selection;

    if (empty && !editor.isActive('link')) {
      editor
        .chain()
        .focus()
        .insertContent({ type: 'text', text: href.replace(/^mailto:/, ''), marks: [{ type: 'link', attrs: { href } }] })
        .run();
    } else {
      editor.chain().focus().extendMarkRange('link').setLink({ href }).run();
    }

    this.linking.set(false);
    this.linkError.set(null);
  }

  removeLink(): void {
    this.editor?.chain().focus().extendMarkRange('link').unsetLink().run();
    this.linking.set(false);
    this.linkError.set(null);
  }

  /**
   * The same three schemes the server keeps: http, https and mailto. A bare
   * "instagram.com/night" is what people type, so it is given https; a bare
   * address with an @ is given mailto. Anything else — javascript:, data: —
   * is refused here rather than silently stripped on save.
   */
  private normaliseHref(raw: string): string | null {
    const value = raw.trim();

    if (value === '') return '';

    if (/^(https?:\/\/|mailto:)/i.test(value)) return value;

    if (/^[a-z][a-z0-9+.-]*:/i.test(value)) return null;

    if (/^[^\s@/]+@[^\s@/]+\.[^\s@/]+$/.test(value)) return `mailto:${value}`;

    if (/^[^\s]+\.[^\s]{2,}/.test(value)) return `https://${value}`;

    return null;
  }

  // --- the editor --------------------------------------------------------------

  private create(): void {
    this.editor = new Editor({
      element: this.surface().nativeElement,
      content: this.value() ?? '',
      extensions: [
        StarterKit.configure({
          // Off: none of these survive the server's allowlist, and a tool
          // whose result disappears on save is worse than no tool.
          code: false,
          codeBlock: false,
          horizontalRule: false,
          // h2 stays parseable so imported descriptions keep their headings;
          // the toolbar makes h3.
          heading: { levels: [2, 3] },
          link: {
            openOnClick: false,
            autolink: true,
            defaultProtocol: 'https',
            protocols: ['mailto'],
            isAllowedUri: (url) => /^(https?:\/\/|mailto:)/i.test(url),
            HTMLAttributes: { rel: 'noopener noreferrer nofollow', target: '_blank' },
          },
        }),
      ],
      editorProps: {
        attributes: {
          id: this.inputId(),
          role: 'textbox',
          'aria-multiline': 'true',
          'aria-label': this.label(),
          class:
            'rich min-h-40 max-h-[32rem] overflow-y-auto px-4 py-3 text-sm text-text outline-none',
        },
        handleKeyDown: (_view, event) => {
          if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            this.openLink();

            return true;
          }

          return false;
        },
      },
      onTransaction: () => this.sync(),
      onUpdate: ({ editor }) => {
        // An empty document is no description at all, not an empty paragraph.
        const html = editor.isEmpty ? '' : editor.getHTML();

        this.lastEmitted = html;
        this.valueChange.emit(html);
      },
    });

    this.lastEmitted = this.value() ?? '';
    this.ready.set(true);
    this.sync();
  }

  private sync(): void {
    const editor = this.editor;

    if (!editor) return;

    this.empty.set(editor.isEmpty);
    this.characters.set(editor.isEmpty ? 0 : editor.getHTML().length);
    this.canUndo.set(editor.can().undo());
    this.canRedo.set(editor.can().redo());
    this.tick.update((n) => n + 1);
  }
}
