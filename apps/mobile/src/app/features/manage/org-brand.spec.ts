import { TestBed } from "@angular/core/testing";
import { provideRouter } from "@angular/router";
import type { Brand } from "@myfiesta/api-types";
import { beforeEach, describe, expect, it } from "vitest";
import { ApiError } from "../../core/api";
import { Discover } from "../../core/discovery";
import { Organizer } from "../../core/organizer";
import { SessionStore } from "../../core/session";
import { Dialogs, ToastStore, type ConfirmRequest } from "../../ui";
import { OrgBrand } from "./org-brand";
import { OrgBrandApi, type BrandChanges } from "./org-brand-api";

function brand(overrides: Partial<Brand> = {}): Brand {
  return {
    name: "Lagos Nights",
    slug: "lagos-nights",
    description: "Afrobeats every second Friday.",
    logo_url: null,
    is_verified: false,
    verification_pending_name: false,
    socials: {
      instagram: "lagosnights",
      tiktok: null,
      x: null,
      facebook: null,
      website: null,
    },
    ...overrides,
  };
}

/**
 * Where else to find them, on the phone's brand screen: filled from what is
 * kept, saved with the rest once the organizer agrees, and a refused box says
 * why under itself.
 */
describe("the organization’s brand, where else to find you", () => {
  let asked: ConfirmRequest[];
  let say: boolean;
  let sent: BrandChanges[];
  let answer: (changes: BrandChanges) => Promise<Brand>;
  let toasts: string[];
  let synced: number;

  beforeEach(() => {
    asked = [];
    say = true;
    sent = [];
    toasts = [];
    synced = 0;
    answer = async () => brand();

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        { provide: Organizer, useValue: { brand: async () => brand() } },
        {
          provide: Discover,
          useValue: { siteBase: () => "https://myfiesta.ca" },
        },
        {
          provide: SessionStore,
          useValue: { sync: async () => void synced++ },
        },
        {
          provide: OrgBrandApi,
          useValue: {
            save: async (changes: BrandChanges) => {
              sent.push(changes);

              return answer(changes);
            },
          },
        },
        {
          provide: Dialogs,
          useValue: {
            confirm: async (options: ConfirmRequest) => {
              asked.push(options);

              return say;
            },
          },
        },
        {
          provide: ToastStore,
          useValue: { show: (text: string) => toasts.push(text) },
        },
      ],
    });
  });

  async function screen() {
    const fixture = TestBed.createComponent(OrgBrand);
    fixture.autoDetectChanges();
    await fixture.whenStable();

    return fixture;
  }

  function box(
    fixture: { nativeElement: HTMLElement },
    network: string,
  ): HTMLInputElement {
    return fixture.nativeElement.querySelector<HTMLInputElement>(
      `input[data-network="${network}"]`,
    )!;
  }

  async function type(
    fixture: { nativeElement: HTMLElement; whenStable(): Promise<unknown> },
    network: string,
    value: string,
  ) {
    const input = box(fixture, network);
    input.value = value;
    input.dispatchEvent(new Event("input"));
    await fixture.whenStable();
  }

  function save(fixture: { componentInstance: OrgBrand }): Promise<void> {
    return (
      fixture.componentInstance as unknown as { save(): Promise<void> }
    ).save();
  }

  it("starts from what is kept", async () => {
    const fixture = await screen();

    expect(box(fixture, "instagram").value).toBe("lagosnights");
    expect(box(fixture, "website").value).toBe("");
    expect(box(fixture, "website").type).toBe("url");
  });

  it("asks first, then sends only the links that changed, and shows what was kept", async () => {
    const fixture = await screen();

    await type(fixture, "tiktok", "tiktok.com/@lagos.nights");
    await type(fixture, "instagram", "");
    answer = async () =>
      brand({
        socials: {
          instagram: null,
          tiktok: "lagos.nights",
          x: null,
          facebook: null,
          website: null,
        },
      });

    await save(fixture);
    await fixture.whenStable();

    expect(asked[0].title).toBe("Save where else to find you?");
    expect(asked[0].consequences).toContain("Instagram comes off your page.");
    expect(sent).toEqual([
      { socials: { instagram: null, tiktok: "tiktok.com/@lagos.nights" } },
    ]);
    expect(box(fixture, "tiktok").value).toBe("lagos.nights");
    // Only a new name or description changes what the session knows.
    expect(synced).toBe(0);
  });

  it("only says a link comes off when that is all the organizer is doing", async () => {
    const fixture = await screen();
    say = false;

    await type(fixture, "instagram", "");
    await save(fixture);

    expect(asked[0].consequences).toEqual(["Instagram comes off your page."]);
    expect(sent).toEqual([]);
  });

  it("sends nothing when the organizer thinks better of it", async () => {
    const fixture = await screen();
    say = false;

    await type(fixture, "x", "lagosnights");
    await save(fixture);

    expect(sent).toEqual([]);
  });

  it("puts what the server refused under the box it was in", async () => {
    const fixture = await screen();
    await type(fixture, "website", "http://lagosnights.com");

    answer = async () => {
      throw new ApiError("That is not a website address we can link to.", 422, {
        "socials.website": ["That is not a website address we can link to."],
      });
    };

    await save(fixture);
    await fixture.whenStable();

    const field = box(fixture, "website").closest("mf-field")!;
    expect(field.textContent).toContain(
      "That is not a website address we can link to.",
    );
    expect(toasts).toContain("Check the links marked below.");
  });

  it("saves a new name and a link together, in one go", async () => {
    const fixture = await screen();
    const name = fixture.nativeElement.querySelector(
      'input[maxlength="120"]',
    ) as HTMLInputElement;
    name.value = "Lagos Nights Toronto";
    name.dispatchEvent(new Event("input"));
    await type(fixture, "x", "@lagosnights");

    await save(fixture);

    expect(asked[0].title).toBe(
      "Rename the organization to Lagos Nights Toronto?",
    );
    expect(sent).toEqual([
      {
        name: "Lagos Nights Toronto",
        description: "Afrobeats every second Friday.",
        socials: { x: "@lagosnights" },
      },
    ]);
    expect(synced).toBe(1);
  });
});
