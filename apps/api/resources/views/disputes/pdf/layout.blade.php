{{--
    The frame every dispute document shares. Plain on purpose: this is read by
    somebody at a bank deciding between two accounts of the same evening, and
    a document that looks like a record reads as one.

    Dompdf draws it, so CSS 2.1 and one font — DejaVu Sans, which has the
    naira sign — and nothing fetched from anywhere.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — order {{ $case->order->reference }}</title>
    <style>
        @page { margin: 16mm 15mm 18mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1b1b1b; line-height: 1.4; }
        h1 { font-size: 14pt; margin: 0 0 3pt; }
        h2 { font-size: 11pt; margin: 14pt 0 5pt; padding-bottom: 2pt; border-bottom: 0.6pt solid #8a8a8a; }
        p { margin: 0 0 6pt; }
        .meta { color: #555; font-size: 8pt; margin-bottom: 10pt; }
        table { width: 100%; border-collapse: collapse; margin: 3pt 0 8pt; }
        th, td { text-align: left; vertical-align: top; padding: 3pt 4pt; border-bottom: 0.4pt solid #d4d4d4; }
        th { font-size: 7.5pt; color: #444; text-transform: uppercase; letter-spacing: 0.3pt; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        td.when { white-space: nowrap; width: 27%; }
        table.facts th { width: 30%; text-transform: none; letter-spacing: 0; font-size: 8.5pt; }
        .total td { font-weight: bold; border-top: 0.8pt solid #1b1b1b; }
        .small { font-size: 7.5pt; color: #555; }
        .none { color: #666; font-style: italic; }
        .policy h1 { font-size: 11pt; margin: 8pt 0 4pt; }
        .policy h2 { font-size: 10pt; border: 0; margin: 8pt 0 3pt; }
        .policy { border-left: 1.5pt solid #bbb; padding-left: 8pt; }
        .break { page-break-after: always; }
        .footer { margin-top: 14pt; padding-top: 4pt; border-top: 0.4pt solid #d4d4d4; font-size: 7pt; color: #666; }
    </style>
</head>
<body>
@yield('content')

<div class="footer">
    Prepared by myFiesta from its own records on {{ $prepared }}, for a payment dispute on order {{ $case->order->reference }}.
    Times are in UTC. Ticket codes are never shown: a ticket is named by its type and the last characters of its code.
</div>
</body>
</html>
