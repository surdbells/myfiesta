import { Injectable } from '@angular/core';
import { DoorOfflineStore } from '@myfiesta/door';

/**
 * The door's memory, for this app's injector.
 *
 * The store itself is in `@myfiesta/door`, shared with the phone app, and has
 * no framework in it. This is the one line that makes it injectable here — two
 * apps admitting people through the same doors must not be two implementations
 * of when to admit them, and the way to keep that true is to have one.
 */
@Injectable({ providedIn: 'root' })
export class DoorOffline extends DoorOfflineStore {}

export { scanId } from '@myfiesta/door';
