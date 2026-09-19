/**
 * Who may put this site inside a frame.
 *
 * Nobody, except for the embedded checkout. Every other page — the event
 * pages, the ordinary checkout, a buyer's tickets — refuses to be framed, so
 * nobody can lay our buttons under their own and have a buyer click something
 * they cannot see. Before the widget nothing said either way, which meant
 * every page could be framed by anybody.
 *
 * /embed/ is the exception by design: an organizer's venue site is anywhere,
 * so any site may frame it. What is framed is the choosing and the form; the
 * card is taken in a tab of its own on the processor's page, which frames
 * nothing.
 */
export function framingHeaders(path: string): Record<string, string> {
  if (path === '/embed' || path.startsWith('/embed/')) {
    return { 'Content-Security-Policy': 'frame-ancestors *' };
  }

  return { 'Content-Security-Policy': "frame-ancestors 'self'", 'X-Frame-Options': 'SAMEORIGIN' };
}
