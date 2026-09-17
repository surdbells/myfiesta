/**
 * What a door decides with no signal, and where it keeps what it needs.
 *
 * Imported as source by whichever app is doing the scanning — see this
 * package's README for why the decision lives here rather than in either of
 * them.
 */
export { decideOffline, admittedAfter, hashCode } from './rules';
export { DoorOfflineStore, scanId } from './store';
export type {
  DoorList,
  DoorListTicket,
  OfflineScan,
  ScanResult,
  StoredList,
  SyncResult,
} from './types';
