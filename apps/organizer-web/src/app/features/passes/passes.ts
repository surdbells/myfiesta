import { Component } from '@angular/core';
import { UiPageHeader } from '@myfiesta/ui';

/**
 * Flex passes, filled in by the PASS track in wave 3.
 *
 * A heading and nothing else until then. The route is registered already so
 * that building the screen never means editing app.routes.ts, and nothing
 * links here while passes/enabled.ts has it switched off.
 */
@Component({
  selector: 'app-passes',
  imports: [UiPageHeader],
  template: `<ui-page-header title="Passes" />`,
})
export class Passes {}
