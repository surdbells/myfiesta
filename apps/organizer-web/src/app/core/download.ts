/**
 * Hand a file the API produced to the browser to save.
 *
 * Exports need the bearer token, so they cannot be a plain link — a link sends
 * no Authorization header. The file is fetched as a blob instead, and an
 * object URL stands in for the link just long enough to be clicked.
 */
export function saveFile(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');

  link.href = url;
  link.download = filename;
  link.rel = 'noopener';
  document.body.appendChild(link);
  link.click();
  link.remove();

  // Released on the next tick rather than immediately: revoking in the same
  // tick as the click cancels the download in some browsers.
  setTimeout(() => URL.revokeObjectURL(url), 0);
}

/** Today, as it appears in an export's filename. */
export function today(): string {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');

  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}
