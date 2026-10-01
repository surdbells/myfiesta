import { Component } from '@angular/core';
import { UiPageHeader } from '@myfiesta/ui';

/**
 * Demand across upcoming events, filled in by the WAIT track.
 *
 * A heading and nothing else until then. The route is registered already so
 * that building the screen never means editing app.routes.ts, and nothing
 * links here while demand/enabled.ts has it switched off.
 */
@Component({
  selector: 'app-demand',
  imports: [UiPageHeader],
  template: `<ui-page-header title="Demand" />`,
})
export class Demand {}
