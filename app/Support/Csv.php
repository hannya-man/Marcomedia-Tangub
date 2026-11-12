<?php
namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

// A spreadsheet download (CSV) that opens in Excel.
class Csv
{
    public static function download(string $filename, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // so Excel reads ₱ and ñ right
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'cell'], $row), ',', '"', '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // Text that starts like a formula (= + - @) is kept as text: a name typed into the app must never
    // run as a formula when the file is opened.
    public static function cell(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
