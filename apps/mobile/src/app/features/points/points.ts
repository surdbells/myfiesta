import { Component } from '@angular/core';
import { MfScreen } from '../../ui';

/**
 * Fiesta Points: the balance, what earned it, and the perks claimed with it.
 *
 * Only the screen's place for now. The route is registered once, ahead of
 * the feature, so the feature fills this file and never has to edit the
 * routes. Nothing links here until it does, and the screen is its heading
 * alone: no balance is better than a balance of nought that is not true.
 */
@Component({
  selector: 'mf-points',
  imports: [MfScreen],
  template: `<mf-screen title="Fiesta Points" back backTo="/settings" />`,
})
export class Points {}
