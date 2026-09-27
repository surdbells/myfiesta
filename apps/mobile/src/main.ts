import { bootstrapApplication } from '@angular/platform-browser';
import { App } from './app/app';
import { appConfig } from './app/app.config';
import { reportError, startErrorReporting } from './app/core/error-reporting';

// Before the app starts, so an error while it starts is reported too. Does
// nothing unless the build stamped a DSN into the page.
void startErrorReporting(document);

bootstrapApplication(App, appConfig).catch((error) => {
  console.error(error);
  reportError(error);
});
