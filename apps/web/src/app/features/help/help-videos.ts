import { DOCUMENT } from '@angular/common';
import { Component, Injector, afterNextRender, computed, inject, signal } from '@angular/core';
import { DomSanitizer, Meta, SafeResourceUrl } from '@angular/platform-browser';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { UiIcon } from '@myfiesta/ui';
import { Play } from 'lucide-angular';
import { HelpVideo, HelpVideoAudience } from '../../core/api.types';
import { Seo } from '../../core/seo';
import { HelpApi } from './help-api';
import { PLAY_PARAM, PlayerFrame } from './player-frame';

/** YouTube's id for a video: eleven letters, numbers, hyphens and underscores. */
const YOUTUBE_ID = /^[A-Za-z0-9_-]{11}$/;

/** Who a video is for, in the order the page shows them, as the admin names them. */
export const AUDIENCES: { audience: HelpVideoAudience; heading: string }[] = [
  { audience: 'buyers', heading: 'Buying tickets' },
  { audience: 'organizers', heading: 'Running your events' },
];

/**
 * How-to videos, for buyers and for organizers: help/videos.
 *
 * Under help/ rather than a word of its own at the root, which an event's
 * slug could otherwise take (ReservedSlugMirrorTest).
 *
 * Each video starts as its thumbnail and a play button. Nothing from YouTube
 * but that picture loads until somebody presses play, and then the player
 * comes from youtube-nocookie.com, which sets no cookies until the video
 * plays. The addresses are built here from the id alone (checked again here,
 * though the API and the database both insist on it), so nothing typed into
 * the admin can point a frame anywhere else.
 */
@Component({
  selector: 'mf-help-videos',
  imports: [RouterLink, UiIcon],
  templateUrl: './help-videos.html',
})
export class HelpVideos {
  private readonly api = inject(HelpApi);
  private readonly frame = inject(PlayerFrame);
  private readonly sanitizer = inject(DomSanitizer);
  private readonly seo = inject(Seo);
  private readonly meta = inject(Meta);
  private readonly route = inject(ActivatedRoute);
  private readonly injector = inject(Injector);
  private readonly document = inject(DOCUMENT);

  protected readonly playIcon = Play;

  /** Null until they arrive. */
  readonly videos = signal<HelpVideo[] | null>(null);
  readonly unavailable = signal(false);

  /** The video whose player is open, by our id. One at a time. */
  readonly playing = signal<string | null>(null);

  /**
   * The open player's address, on the no-cookie domain, built from the
   * checked id. Made once per video rather than on every check, so the frame
   * is never handed a "new" address and made to load again.
   */
  readonly player = computed<SafeResourceUrl | null>(() => {
    const video = this.videos()?.find((candidate) => candidate.id === this.playing());

    return video
      ? this.sanitizer.bypassSecurityTrustResourceUrl(
          `https://www.youtube-nocookie.com/embed/${video.youtube_id}?autoplay=1&rel=0`,
        )
      : null;
  });

  /** Each audience that has a video, with its videos, in the page's order. */
  readonly sections = computed(() =>
    AUDIENCES.map(({ audience, heading }) => ({
      audience,
      heading,
      videos: (this.videos() ?? []).filter((video) => video.audience === audience),
    })).filter((section) => section.videos.length > 0),
  );

  constructor() {
    this.seo.forListing(
      'How-to videos',
      'Short videos on buying a ticket, getting in at the door, and running your events on myFiesta.',
      'https://myfiesta.ca/help/videos',
    );

    this.api.videos().subscribe({
      next: ({ data }) => {
        const videos = data.filter((video) => YOUTUBE_ID.test(video.youtube_id));
        this.videos.set(videos);

        // An empty page is not worth a search result.
        if (videos.length === 0) {
          this.meta.updateTag({ name: 'robots', content: 'noindex' }, 'name="robots"');
        }

        // Loaded afresh to play one (PlayerFrame.reloadToPlay): open it.
        const wanted = this.route.snapshot.queryParamMap.get(PLAY_PARAM);
        const asked = videos.find((video) => video.youtube_id === wanted);
        if (asked && this.frame.allowed()) {
          this.playing.set(asked.id);
          afterNextRender(
            () =>
              this.document.getElementById(asked.youtube_id)?.scrollIntoView?.({ block: 'center' }),
            {
              injector: this.injector,
            },
          );
        }
      },
      error: () => {
        this.unavailable.set(true);
        this.seo.unavailable('How-to videos');
      },
    });
  }

  thumbnail(video: HelpVideo): string {
    return `https://i.ytimg.com/vi/${video.youtube_id}/hqdefault.jpg`;
  }

  play(video: HelpVideo): void {
    if (!this.frame.allowed()) {
      this.frame.reloadToPlay(video.youtube_id);
      return;
    }

    this.playing.set(video.id);
  }
}
