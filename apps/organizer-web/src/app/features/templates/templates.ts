import { Component } from '@angular/core';
import { UiPageHeader } from '@myfiesta/ui';

/**
 * Event templates, filled in by the CLONE track.
 *
 * A heading and nothing else until then. The route is registered already so
 * that building the screen never means editing app.routes.ts, and nothing
 * links here while templates/enabled.ts has it switched off.
 */
@Component({
  selector: 'app-templates',
  imports: [UiPageHeader],
  template: `<ui-page-header title="Templates" />`,
})
export class Templates {}
