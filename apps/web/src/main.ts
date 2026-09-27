import { bootstrapApplication } from '@angular/platform-browser';
import { appConfig } from './app/app.config';
import { App } from './app/app';
import { reportError, startErrorReporting } from './app/core/error-reporting';

// Before the app starts, so an error while it starts is reported too. Does
// nothing unless the server stamped a DSN into the page.
void startErrorReporting(document);

bootstrapApplication(App, appConfig).catch((err) => {
  console.error(err);
  reportError(err);
});
