import { isFriendLink, percentOf } from './share';

describe('friend-discount wording', () => {
  it('says a percentage the way a person would', () => {
    expect(percentOf(1500)).toBe('15%');
    expect(percentOf(1250)).toBe('12.5%');
    expect(percentOf(333)).toBe('3.33%');
  });

  it('tells a friend’s link from a promoter’s', () => {
    expect(isFriendLink('fabcdefgh23')).toBe(true);
    expect(isFriendLink('FABCDEFGH23')).toBe(true);
    // A promoter's slug, however close, is not one.
    expect(isFriendLink('fiesta')).toBe(false);
    expect(isFriendLink('fabcdefgh18')).toBe(false);
    expect(isFriendLink(null)).toBe(false);
  });
});
