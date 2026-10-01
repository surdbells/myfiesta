import { Component } from '@angular/core';
import { UiPageHeader } from '@myfiesta/ui';

/**
 * The organization's survey templates, filled in by the SURVEY track.
 *
 * A heading and nothing else until then. The route is registered already so
 * that building the screen never means editing app.routes.ts, and nothing
 * links here while surveys/enabled.ts has it switched off.
 */
@Component({
  selector: 'app-surveys',
  imports: [UiPageHeader],
  template: `<ui-page-header title="Surveys" />`,
})
export class Surveys {}
