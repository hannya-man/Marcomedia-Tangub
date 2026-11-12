<?php
namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

// A spreadsheet download (CSV) that opens in Excel.
class Csv
{
    // $forExcel adds the mark Excel needs to read ₱ and ñ right; Google Sheets reads UTF-8 without it.
    public static function download(string $filename, iterable $rows, bool $forExcel = true): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows, $forExcel) {
            $out = fopen('php://output', 'w');
            if ($forExcel) {
                fwrite($out, "\xEF\xBB\xBF");
            }
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
