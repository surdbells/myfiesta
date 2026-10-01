import { Component } from '@angular/core';
import { UiPageHeader } from '@myfiesta/ui';

/**
 * The organization's audience by month, filled in by the INSIGHT track.
 *
 * A heading and nothing else until then. The route is registered already so
 * that building the screen never means editing app.routes.ts, and nothing
 * links here while audience/enabled.ts has it switched off.
 */
@Component({
  selector: 'app-audience',
  imports: [UiPageHeader],
  template: `<ui-page-header title="Audience" />`,
})
export class Audience {}
