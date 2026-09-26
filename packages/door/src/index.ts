/**
 * What a door decides with no signal, and where it keeps what it needs —
 * and the camera inside the page it reads tickets with, and what it does with
 * what that camera reads.
 *
 * Imported as source by whichever app is doing the scanning — see this
 * package's README for why the decision lives here rather than in either of
 * them.
 */
export { decideOffline, admittedAfter, hashCode } from './rules';
export { DoorOfflineStore, scanId } from './store';
export {
  PageCamera,
  READ_EVERY_MS,
  canScanInPage,
  fetchDecoderAhead,
  openDetector,
  zxingWasmUrl,
} from './camera';
export { RepeatReads, SAME_TICKET_AGAIN_AFTER_MS, ticketCode } from './reads';
export { MOST_AT_ONCE, partyKey, partySize } from './party';
export { conflictsIn, unrecordedAdmission, unsendableScans } from './sync';
export type { PartySize } from './party';
export type { CameraRefusal, CodeDetector, OpenedDetector, PageCameraEvents } from './camera';
export type {
  DoorList,
  DoorListTicket,
  OfflineScan,
  ScanResult,
  StoredList,
  SyncResult,
} from './types';
