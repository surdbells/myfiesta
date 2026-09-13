<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spreadsheets an organizer downloads: orders for an accountant, a guest list
 * to print.
 *
 * Two things make a CSV more dangerous than it looks. Most of what goes in it
 * was typed by a stranger at checkout, and a spreadsheet treats a cell that
 * starts with "=" as a formula — so a buyer named =HYPERLINK("…","Click") puts
 * a working link, or worse, in front of whoever opens the file. Text from
 * outside is therefore defused on the way in. And it is written as a stream,
 * row by row, so a large event's export neither loads every order into memory
 * nor times out halfway through a download.
 */
final class Csv
{
    /**
     * Text that came from a person, made inert for a spreadsheet.
     *
     * The OWASP rule: a leading =, +, -, @, tab or carriage return gets a
     * single quote in front, which spreadsheets display as plain text. Only
     * for text. Numbers this system produces are written as they are — a
     * refund of -5.00 is a number, and quoting it would break the column an
     * accountant sums.
     */
    public static function text(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return preg_match('/^[=+\-@\t\r]/u', $value) ? "'".$value : $value;
    }

    /** Minor units as a decimal, with no thousands separator to confuse a spreadsheet. */
    public static function money(?int $minor): string
    {
        return $minor === null ? '' : number_format($minor / 100, 2, '.', '');
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<list<string|int|null>>  $rows
     */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');

            // Excel opens a BOM-less UTF-8 file as the local code page, which
            // turns Adébáyọ̀ and Québec into mojibake.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $header, escape: '');

            foreach ($rows as $row) {
                fputcsv($out, $row, escape: '');
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // Names and email addresses: never kept by a proxy or the browser.
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
