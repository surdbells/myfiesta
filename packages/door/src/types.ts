/**
 * The shapes a door works with.
 *
 * Owned here rather than in either app, because the two of them scan the same
 * tickets against the same server and a drifting field is a door that stops
 * recognising people.
 */

/** What the server says about one scan, online or off. */
export interface ScanResult {
  result: string;
  /** Whether anybody went in. A table can be partly admitted. */
  accepted: boolean;
  admitted: number;
  /** Still outstanding on this ticket — what keeps a table open. */
  remaining: number;
  message: string;
  ticket: {
    holder_name: string | null;
    type: string | null;
    admits: number;
    admitted_count: number;
    /**
     * What this person was asked at checkout: a name to check against an ID,
     * a table number, an access requirement.
     *
     * Only what was asked of them. What the buyer answered for the order —
     * how they heard about the night — is not a door's business, and the
     * server does not send it.
     *
     * Absent on a scan decided offline: the saved list carries hashes and a
     * name, never the answers.
     */
    answers?: { label: string | null; value: string }[];
  } | null;
  /** Decided on this phone from its saved list, with no connection. */
  offline?: boolean;
  /** What the door did offline, echoed back when a queued scan is synced. */
  offline_result?: string | null;
  /** Where the offline door and the server disagreed. */
  conflict?: 'admitted_invalid' | 'refused_valid' | null;
}

/** One ticket in the list a door phone keeps for when signal goes. */
export interface DoorListTicket {
  /** PBKDF2 of the code — enough to recognise one, never enough to show one. */
  hash: string;
  status: string;
  admits: number;
  admitted_count: number;
  holder_name: string | null;
  type: string | null;
}

export interface DoorList {
  event_id: string;
  salt: string;
  iterations: number;
  generated_at: string;
  tickets: DoorListTicket[];
}

/** A scan the door made offline, waiting on the phone to be sent. */
export interface OfflineScan {
  client_id: string;
  event_id: string;
  code: string;
  party: number | null;
  offline_result: string;
  scanned_at: string;
}

export interface SyncResult {
  data: (ScanResult & { client_id: string })[];
  conflicts: (ScanResult & { client_id: string })[];
}

/** A saved list as a phone holds it: the list without its tickets. */
export interface StoredList {
  event_id: string;
  salt: string;
  iterations: number;
  generated_at: string;
  count: number;
}
