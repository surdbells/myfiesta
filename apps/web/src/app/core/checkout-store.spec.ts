import { TestBed } from '@angular/core/testing';
import { CheckoutStore } from './checkout-store';

/**
 * The basket between the ticket page and checkout.
 *
 * Selection and payment are different routes, and "back to change my tickets"
 * is a reload in disguise — so the choice has to survive both, and only for
 * the event it was made on.
 */
describe('CheckoutStore', () => {
  beforeEach(() => {
    sessionStorage.clear();
    TestBed.configureTestingModule({});
  });

  it('survives a reload', () => {
    TestBed.inject(CheckoutStore).setQuantity('afrobeats-rooftop', 'general', 2);

    // A fresh store, as after a reload, reading what the last one kept.
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({});
    const again = TestBed.inject(CheckoutStore);
    again.loadFor('afrobeats-rooftop');

    expect(again.count()).toBe(2);
    expect(again.lines()).toEqual([{ ticket_type_id: 'general', quantity: 2 }]);
  });

  it('never sends a tier the buyer set back to zero', () => {
    const store = TestBed.inject(CheckoutStore);

    store.setQuantity('afrobeats-rooftop', 'general', 2);
    store.setQuantity('afrobeats-rooftop', 'vip', 1);
    store.setQuantity('afrobeats-rooftop', 'vip', 0);

    expect(store.lines()).toEqual([{ ticket_type_id: 'general', quantity: 2 }]);
    expect(store.count()).toBe(2);
  });

  it('keeps one event’s basket out of another’s', () => {
    const store = TestBed.inject(CheckoutStore);

    store.setQuantity('afrobeats-rooftop', 'general', 2);
    store.loadFor('amapiano-sundays');

    expect(store.count()).toBe(0);

    store.loadFor('afrobeats-rooftop');
    expect(store.count()).toBe(2);
  });

  it('forgets the basket once the order is placed', () => {
    const store = TestBed.inject(CheckoutStore);

    store.setQuantity('afrobeats-rooftop', 'general', 2);
    store.setCode('afrobeats-rooftop', 'EARLYBIRD');
    store.clear('afrobeats-rooftop');
    store.loadFor('afrobeats-rooftop');

    expect(store.count()).toBe(0);
    expect(store.code()).toBe('');
  });

  /*
   * The ref a link arrived with — a promoter's, or a friend's that takes
   * money off — rode in memory only, so a reload between the event page and
   * paying dropped it: the promoter lost the sale, and a friend's discount
   * vanished, a price that went up between two pages.
   */
  it('keeps the ref a link arrived with through a reload', () => {
    TestBed.inject(CheckoutStore).setRef('afrobeats-rooftop', 'fabcdefghij');

    TestBed.resetTestingModule();
    TestBed.configureTestingModule({});
    const again = TestBed.inject(CheckoutStore);
    again.loadFor('afrobeats-rooftop');

    expect(again.ref()).toBe('fabcdefghij');
  });

  it('keeps the ref when the basket changes after it', () => {
    const store = TestBed.inject(CheckoutStore);

    store.setRef('afrobeats-rooftop', 'promo-ada');
    store.setQuantity('afrobeats-rooftop', 'general', 2);
    store.setCode('afrobeats-rooftop', 'EARLYBIRD');

    TestBed.resetTestingModule();
    TestBed.configureTestingModule({});
    const again = TestBed.inject(CheckoutStore);
    again.loadFor('afrobeats-rooftop');

    expect(again.ref()).toBe('promo-ada');
    expect(again.count()).toBe(2);
  });

  it('keeps one event’s ref out of another’s, and forgets it once the order is placed', () => {
    const store = TestBed.inject(CheckoutStore);

    store.setRef('afrobeats-rooftop', 'fabcdefghij');
    store.loadFor('amapiano-sundays');
    expect(store.ref()).toBeNull();

    store.loadFor('afrobeats-rooftop');
    expect(store.ref()).toBe('fabcdefghij');

    store.clear('afrobeats-rooftop');
    expect(store.ref()).toBeNull();
    store.loadFor('afrobeats-rooftop');
    expect(store.ref()).toBeNull();
  });
});
