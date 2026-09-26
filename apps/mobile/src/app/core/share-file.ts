import { Share } from '@capacitor/share';

/**
 * Hand a file to the phone's share sheet: Mail, Files, Drive, WhatsApp.
 *
 * An export on a desk is a download. On a phone there is no Downloads folder
 * anybody looks in, and what an organizer actually wants is to send the guest
 * list to the promoter or save it where they keep things — which is exactly
 * what the share sheet offers.
 *
 * The Web Share API carries files on both WebViews the app runs in; where it
 * cannot, the text itself goes to the share sheet so the data still gets out.
 */
export async function shareFile(text: string, filename: string, title: string): Promise<'shared' | 'dismissed'> {
  const file = new File([text], filename, { type: 'text/csv' });

  try {
    if (typeof navigator.canShare === 'function' && navigator.canShare({ files: [file] })) {
      await navigator.share({ files: [file], title });

      return 'shared';
    }

    await Share.share({ title, text, dialogTitle: title });

    return 'shared';
  } catch (error) {
    // Closing the share sheet without choosing is not a failure.
    if (error instanceof Error && /abort|cancel/i.test(error.name + error.message)) return 'dismissed';

    throw error;
  }
}
