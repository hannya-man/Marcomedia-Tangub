<?php
namespace App\Support;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Excel workbooks (.xlsx), written and read with PHP's zip extension instead of a spreadsheet
 * library: an .xlsx is a zip of a few XML files.
 *
 * Writing: [sheet name => sheet]. A sheet is a list of rows (the first row is drawn as headings), or
 * ['rows' => [...], 'widths' => [col => width], 'heights' => [row => height], 'merge' => ['A1:F1'],
 *  'freeze' => true (keep row 1 on screen), 'filter' => 'A1:H20', 'grid' => false, 'tab' => 'RRGGBB',
 *  'charts' => [...] (see chart())]. A cell is a value, or ['v' => value, 's' => style, 'f' => formula]:
 * a formula's 'v' is its value until Excel works it out again (it does on opening). Text is always
 * stored as text, so a name typed into the app can't run as a formula (Csv::cell does the same for CSV).
 */
class Xlsx
{
    private const MAX_PART = 20 * 1024 * 1024;   // a part bigger than this when unzipped is refused (a zip bomb)

    // Cell styles by name => their index in styles.xml (cellXfs), in the same order.
    private const STYLES = [
        'plain' => 0, 'header' => 1, 'text' => 2, 'money' => 3, 'int' => 4, 'qty' => 5, 'date' => 6, 'time' => 7,
        'total' => 8, 'total_money' => 9, 'total_int' => 10, 'title' => 11, 'subtitle' => 12, 'kpi_label' => 13,
        'kpi_money' => 14, 'kpi_int' => 15, 'kpi_note' => 16, 'section' => 17, 'link' => 18, 'warn' => 19, 'bad' => 20, 'total_qty' => 21,
    ];

    // Slices of a doughnut, in turn.
    private const PALETTE = ['146C84', 'E0A526', '1F8BA0', '10B981', '6366F1', 'F43F5E', '0EA5E9', '94A3B8'];

    public static function download(string $filename, array $sheets): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        self::write($path, $sheets);

        return response()->download($path, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }

    public static function write(string $path, array $sheets): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Couldn't write the spreadsheet.");
        }
        $names = [];
        $types = '';
        $filters = '';
        $chartNo = 0;
        foreach (array_values($sheets) as $i => $sheet) {
            $n = $i + 1;
            $sheet = array_is_list($sheet) ? ['rows' => $sheet, 'freeze' => true, 'headings' => true] : $sheet;
            $name = mb_substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', array_keys($sheets)[$i]), 0, 31);
            $names[] = $name;
            $drawing = '';
            if (! empty($sheet['charts'])) {
                $anchors = '';
                $rels = '';
                foreach ($sheet['charts'] as $c => $chart) {
                    $chartNo++;
                    $zip->addFromString("xl/charts/chart{$chartNo}.xml", self::chart($chart));
                    $types .= "<Override PartName=\"/xl/charts/chart{$chartNo}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.drawingml.chart+xml\"/>";
                    $rels .= '<Relationship Id="rId' . ($c + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart' . $chartNo . '.xml"/>';
                    $anchors .= self::anchor($chart, $c + 1, $chartNo);
                }
                $zip->addFromString("xl/drawings/drawing{$n}.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">' . $anchors . '</xdr:wsDr>');
                $zip->addFromString("xl/drawings/_rels/drawing{$n}.xml.rels", self::rels($rels));
                $zip->addFromString("xl/worksheets/_rels/sheet{$n}.xml.rels", self::rels("<Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing\" Target=\"../drawings/drawing{$n}.xml\"/>"));
                $types .= "<Override PartName=\"/xl/drawings/drawing{$n}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.drawing+xml\"/>";
                $drawing = '<drawing r:id="rId1"/>';
            }
            if (! empty($sheet['filter'])) {
                $filters .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">' . self::text(self::quote($name) . '!' . self::absolute($sheet['filter'])) . '</definedName>';
            }
            $zip->addFromString("xl/worksheets/sheet{$n}.xml", self::sheet($sheet, $drawing));
            $types .= "<Override PartName=\"/xl/worksheets/sheet{$n}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        }

        $count = count($names);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $types . '</Types>');
        $zip->addFromString('_rels/.rels', self::rels('<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'));
        $sheetList = '';
        $sheetRels = '';
        foreach ($names as $i => $name) {
            $n = $i + 1;
            $sheetList .= '<sheet name="' . self::text($name) . "\" sheetId=\"{$n}\" r:id=\"rId{$n}\"/>";
            $sheetRels .= "<Relationship Id=\"rId{$n}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$n}.xml\"/>";
        }
        // fullCalcOnLoad: Excel works every formula out again on opening.
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . "<sheets>{$sheetList}</sheets>" . ($filters ? "<definedNames>{$filters}</definedNames>" : '') . '<calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::rels($sheetRels . '<Relationship Id="rId' . ($count + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'));
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->close();
    }

    /**
     * Every sheet of a workbook as rows of values: [sheet name => [[cell, ...], ...]]. Text comes back
     * as text, numbers (dates too: Excel keeps them as day numbers, see date()) as numbers.
     */
    public static function read(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException("That file isn't an Excel workbook (.xlsx).");
        }
        try {
            $part = function (string $name) use ($zip): ?\SimpleXMLElement {
                $stat = $zip->statName($name);
                if ($stat === false) {
                    return null;
                }
                if ($stat['size'] > self::MAX_PART) {
                    throw new RuntimeException('That workbook is too big to read.');
                }
                $xml = simplexml_load_string((string) $zip->getFromName($name), \SimpleXMLElement::class, LIBXML_NONET);

                return $xml === false ? null : $xml;
            };
            $workbook = $part('xl/workbook.xml') ?? throw new RuntimeException("That file isn't an Excel workbook (.xlsx).");
            $targets = [];
            foreach ($part('xl/_rels/workbook.xml.rels')?->Relationship ?? [] as $rel) {
                $targets[(string) $rel['Id']] = ltrim(preg_replace('#^/?xl/#', '', (string) $rel['Target']), '/');
            }
            $strings = [];
            foreach ($part('xl/sharedStrings.xml')?->si ?? [] as $si) {
                $strings[] = self::plainText($si);
            }

            $sheets = [];
            foreach ($workbook->sheets->sheet as $sheet) {
                $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $xml = isset($targets[$id]) ? $part('xl/' . $targets[$id]) : null;
                $rows = [];
                foreach ($xml?->sheetData->row ?? [] as $row) {
                    $cells = [];
                    foreach ($row->c as $c) {
                        $col = self::columnIndex(preg_replace('/\d+/', '', (string) $c['r']));
                        $v = (string) $c->v;
                        $cells[$col] = match ((string) $c['t']) {
                            's' => $strings[(int) $v] ?? '',
                            'inlineStr' => self::plainText($c->is),
                            'str', 'e' => $v,
                            'b' => (bool) $v,
                            default => $v === '' ? null : (float) $v,
                        };
                    }
                    if ($cells) {
                        $rows[(int) $row['r'] - 1] = array_replace(array_fill(0, max(array_keys($cells)) + 1, null), $cells);
                    }
                }
                $sheets[(string) $sheet['name']] = $rows ? array_values(array_replace(array_fill(0, max(array_keys($rows)) + 1, []), $rows)) : [];
            }

            return $sheets;
        } finally {
            $zip->close();
        }
    }

    // An Excel day number (days since 30 Dec 1899; the fraction is the time of day) as 'Y-m-d H:i:s'.
    public static function date(float $serial): string
    {
        return gmdate('Y-m-d H:i:s', (int) round(($serial - 25569) * 86400));
    }

    // 'Y-m-d' or 'Y-m-d H:i' as an Excel day number.
    public static function serial(string $date): float
    {
        return round(strtotime($date . ' UTC') / 86400 + 25569, 6);
    }

    // 0 -> A, 25 -> Z, 26 -> AA.
    public static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26) . $name;
        }

        return $name;
    }

    // A sheet name as a formula writes it: 'Items sold'.
    public static function quote(string $sheet): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $sheet) ? $sheet : "'" . str_replace("'", "''", $sheet) . "'";
    }

    private static function sheet(array $sheet, string $drawing): string
    {
        $rows = array_values($sheet['rows'] ?? []);
        $widths = $sheet['widths'] ?? [];
        $auto = [];
        $data = '';
        foreach ($rows as $r => $row) {
            $cells = '';
            foreach (array_values($row) as $c => $cell) {
                $cell = is_array($cell) ? $cell : ['v' => $cell];
                $value = $cell['v'] ?? null;
                if (($value === null || $value === '') && ! isset($cell['f']) && ! isset($cell['s'])) {
                    continue;
                }
                $ref = self::column($c) . ($r + 1);
                $style = $cell['s'] ?? ($r === 0 && ! empty($sheet['headings']) ? 'header' : (is_int($value) ? 'int' : (is_float($value) ? 'qty' : 'text')));
                $s = ' s="' . (self::STYLES[$style] ?? 0) . '"';
                $formula = isset($cell['f']) ? '<f>' . self::text($cell['f']) . '</f>' : '';
                if (is_int($value) || is_float($value)) {
                    $cells .= "<c r=\"{$ref}\"{$s}>{$formula}<v>" . (is_float($value) ? round($value, 6) : $value) . '</v></c>';
                } elseif ($value === null || $value === '') {
                    $cells .= $formula ? "<c r=\"{$ref}\"{$s} t=\"str\">{$formula}<v></v></c>" : "<c r=\"{$ref}\"{$s}/>";
                } elseif ($formula) {
                    $cells .= "<c r=\"{$ref}\"{$s} t=\"str\">{$formula}<v>" . self::text((string) $value) . '</v></c>';
                } else {
                    $cells .= "<c r=\"{$ref}\"{$s} t=\"inlineStr\"><is><t xml:space=\"preserve\">" . self::text((string) $value) . '</t></is></c>';
                }
                if (! isset($cell['f'])) {
                    $auto[$c] = max($auto[$c] ?? 9, min(mb_strlen((string) $value) + 3, 60));
                }
            }
            $height = isset($sheet['heights'][$r + 1]) ? ' ht="' . $sheet['heights'][$r + 1] . '" customHeight="1"' : '';
            $data .= '<row r="' . ($r + 1) . "\"{$height}>{$cells}</row>";
        }
        $widths += $auto;
        ksort($widths);
        $cols = implode('', array_map(fn ($c, $w) => '<col min="' . ($c + 1) . '" max="' . ($c + 1) . "\" width=\"{$w}\" customWidth=\"1\"/>", array_keys($widths), $widths));
        $merge = implode('', array_map(fn ($range) => "<mergeCell ref=\"{$range}\"/>", $sheet['merge'] ?? []));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            // Printed landscape on A4, one page wide.
            . '<sheetPr>' . (isset($sheet['tab']) ? "<tabColor rgb=\"FF{$sheet['tab']}\"/>" : '') . '<pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<sheetViews><sheetView workbookViewId="0"' . (($sheet['grid'] ?? true) ? '' : ' showGridLines="0"') . '>'
            . (! empty($sheet['freeze']) ? '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>' : '')
            . '</sheetView></sheetViews>'
            . ($cols ? "<cols>{$cols}</cols>" : '') . "<sheetData>{$data}</sheetData>"
            . (! empty($sheet['filter']) ? "<autoFilter ref=\"{$sheet['filter']}\"/>" : '')
            . ($merge ? '<mergeCells count="' . count($sheet['merge']) . "\">{$merge}</mergeCells>" : '')
            . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
            . '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
            . $drawing . '</worksheet>';
    }

    /**
     * A chart: ['type' => 'bar' or 'doughnut', 'title' => ..., 'labels' => [...], 'values' => [...],
     * 'labelsRef' / 'valuesRef' => "'By time'!\$A\$2:\$A\$13" (where Excel reads them from; labels and
     * values are what shows until it has), 'from' => [col, row], 'to' => [col, row] (0-based), 'money' => bool].
     */
    private static function chart(array $chart): string
    {
        $count = count($chart['values']);
        $points = fn (array $list, bool $text) => "<c:ptCount val=\"{$count}\"/>" . implode('', array_map(
            fn ($v, $i) => "<c:pt idx=\"{$i}\"><c:v>" . ($text ? self::text((string) $v) : (float) $v) . '</c:v></c:pt>', $list, array_keys($list)));
        $cat = '<c:cat><c:strRef><c:f>' . self::text($chart['labelsRef']) . '</c:f><c:strCache>' . $points(array_values($chart['labels']), true) . '</c:strCache></c:strRef></c:cat>';
        $val = '<c:val><c:numRef><c:f>' . self::text($chart['valuesRef']) . '</c:f><c:numCache><c:formatCode>General</c:formatCode>' . $points(array_values($chart['values']), false) . '</c:numCache></c:numRef></c:val>';
        $title = '<c:title><c:tx><c:rich><a:bodyPr/><a:p><a:pPr><a:defRPr sz="1200" b="1"/></a:pPr><a:r><a:rPr lang="en-US" sz="1200" b="1"><a:solidFill><a:srgbClr val="0F3B47"/></a:solidFill></a:rPr><a:t>'
            . self::text($chart['title']) . '</a:t></a:r></a:p></c:rich></c:tx><c:overlay val="0"/></c:title><c:autoTitleDeleted val="0"/>';
        $fill = fn (string $rgb) => "<c:spPr><a:solidFill><a:srgbClr val=\"{$rgb}\"/></a:solidFill></c:spPr>";

        if ($chart['type'] === 'doughnut') {
            $slices = implode('', array_map(fn ($i) => "<c:dPt><c:idx val=\"{$i}\"/><c:bubble3D val=\"0\"/>" . $fill(self::PALETTE[$i % count(self::PALETTE)]) . '</c:dPt>', range(0, max($count - 1, 0))));
            $plot = '<c:doughnutChart><c:varyColors val="1"/><c:ser><c:idx val="0"/><c:order val="0"/>' . $slices . $cat . $val
                . '</c:ser><c:firstSliceAng val="0"/><c:holeSize val="55"/></c:doughnutChart>';
            $legend = '<c:legend><c:legendPos val="r"/><c:overlay val="0"/></c:legend>';
        } else {
            $format = ($chart['money'] ?? false) ? '&quot;₱&quot;#,##0' : '#,##0';
            $plot = '<c:barChart><c:barDir val="' . (($chart['horizontal'] ?? false) ? 'bar' : 'col') . '"/><c:grouping val="clustered"/><c:varyColors val="0"/>'
                . '<c:ser><c:idx val="0"/><c:order val="0"/>' . $fill($chart['color'] ?? '1F8BA0') . '<c:invertIfNegative val="0"/>' . $cat . $val . '</c:ser>'
                . '<c:gapWidth val="60"/><c:axId val="50010"/><c:axId val="50020"/></c:barChart>'
                . '<c:catAx><c:axId val="50010"/><c:scaling><c:orientation val="' . (($chart['horizontal'] ?? false) ? 'maxMin' : 'minMax') . '"/></c:scaling><c:delete val="0"/><c:axPos val="' . (($chart['horizontal'] ?? false) ? 'l' : 'b') . '"/>'
                . '<c:numFmt formatCode="General" sourceLinked="0"/><c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/><c:crossAx val="50020"/><c:crosses val="autoZero"/><c:auto val="1"/><c:lblAlgn val="ctr"/><c:lblOffset val="100"/><c:noMultiLvlLbl val="0"/></c:catAx>'
                . '<c:valAx><c:axId val="50020"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/><c:axPos val="' . (($chart['horizontal'] ?? false) ? 'b' : 'l') . '"/>'
                . '<c:majorGridlines><c:spPr><a:ln w="6350"><a:solidFill><a:srgbClr val="E2E8F0"/></a:solidFill></a:ln></c:spPr></c:majorGridlines>'
                . "<c:numFmt formatCode=\"{$format}\" sourceLinked=\"0\"/><c:majorTickMark val=\"none\"/><c:minorTickMark val=\"none\"/><c:tickLblPos val=\"nextTo\"/><c:crossAx val=\"50010\"/><c:crosses val=\"" . (($chart['horizontal'] ?? false) ? 'max' : 'autoZero') . '"/><c:crossBetween val="between"/></c:valAx>';
            $legend = '';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<c:roundedCorners val="0"/><c:chart>' . $title . '<c:plotArea><c:layout/>' . $plot . '</c:plotArea>' . $legend . '<c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/></c:chart>'
            . '<c:spPr><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill><a:ln w="9525"><a:solidFill><a:srgbClr val="CBD5E1"/></a:solidFill></a:ln></c:spPr></c:chartSpace>';
    }

    private static function anchor(array $chart, int $rel, int $id): string
    {
        [$fromCol, $fromRow] = $chart['from'];
        [$toCol, $toRow] = $chart['to'];

        return "<xdr:twoCellAnchor><xdr:from><xdr:col>{$fromCol}</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>{$fromRow}</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>"
            . "<xdr:to><xdr:col>{$toCol}</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>{$toRow}</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>"
            . '<xdr:graphicFrame macro=""><xdr:nvGraphicFramePr><xdr:cNvPr id="' . ($id + 1) . '" name="Chart ' . $id . '"/><xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr>'
            . '<xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">'
            . '<c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rId' . $rel . '"/>'
            . '</a:graphicData></a:graphic></xdr:graphicFrame><xdr:clientData/></xdr:twoCellAnchor>';
    }

    private static function styles(): string
    {
        $font = fn (string $extra = '', int $size = 11, string $rgb = '1E293B') => "<font>{$extra}<sz val=\"{$size}\"/><color rgb=\"FF{$rgb}\"/><name val=\"Calibri\"/><family val=\"2\"/></font>";
        $fonts = [$font(), $font('<b/>'), $font('<b/>', 11, 'FFFFFF'), $font('<b/>', 18, 'FFFFFF'), $font('', 10, 'D9EEF3'),
            $font('', 9, '64748B'), $font('<b/>', 20, '0F3B47'), $font('<b/>', 13, '0F3B47'), $font('<u/>', 11, '146C84')];
        $fill = fn (string $rgb) => "<fill><patternFill patternType=\"solid\"><fgColor rgb=\"FF{$rgb}\"/><bgColor indexed=\"64\"/></patternFill></fill>";
        $fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>',
            $fill('146C84'), $fill('E8F4F7'), $fill('F1F5F9'), $fill('FEF3C7'), $fill('FEE2E2')];
        $line = '<left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right><top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom>';
        $borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>', "<border>{$line}<diagonal/></border>"];
        // [number format, font, fill, border, alignment], in the order of STYLES.
        $xfs = [[0, 0, 0, 0, ''], [0, 2, 2, 1, 'center'], [0, 0, 0, 1, ''], [164, 0, 0, 1, ''], [3, 0, 0, 1, ''], [0, 0, 0, 1, ''],
            [165, 0, 0, 1, 'left'], [166, 0, 0, 1, 'left'], [0, 1, 4, 1, ''], [164, 1, 4, 1, ''], [3, 1, 4, 1, ''],
            [0, 3, 2, 0, 'left'], [0, 4, 2, 0, 'left'], [0, 5, 3, 0, 'left'], [164, 6, 3, 0, 'left'], [3, 6, 3, 0, 'left'], [0, 5, 3, 0, 'left'],
            [0, 7, 0, 0, ''], [0, 8, 0, 0, ''], [0, 0, 5, 1, ''], [0, 1, 6, 1, ''], [0, 1, 4, 1, '']];
        $cellXfs = implode('', array_map(fn ($x) => "<xf numFmtId=\"{$x[0]}\" fontId=\"{$x[1]}\" fillId=\"{$x[2]}\" borderId=\"{$x[3]}\" xfId=\"0\""
            . ($x[0] ? ' applyNumberFormat="1"' : '') . ($x[1] ? ' applyFont="1"' : '') . ($x[2] ? ' applyFill="1"' : '') . ($x[3] ? ' applyBorder="1"' : '')
            . ($x[4] ? " applyAlignment=\"1\"><alignment horizontal=\"{$x[4]}\" vertical=\"center\"/></xf>" : '/>'), $xfs));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="3"><numFmt numFmtId="164" formatCode="&quot;₱&quot;#,##0.00"/><numFmt numFmtId="165" formatCode="mmm d, yyyy"/><numFmt numFmtId="166" formatCode="h:mm AM/PM"/></numFmts>'
            . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
            . '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>'
            . '<borders count="2">' . implode('', $borders) . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($xfs) . "\">{$cellXfs}</cellXfs>"
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private static function rels(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $body . '</Relationships>';
    }

    // A1:H20 -> $A$1:$H$20
    private static function absolute(string $range): string
    {
        return preg_replace('/([A-Z]+)(\d+)/', '\$$1\$$2', $range);
    }

    // A -> 0, Z -> 25, AA -> 26.
    private static function columnIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return max($n - 1, 0);
    }

    // The text of a string item, rich text runs joined.
    private static function plainText(?\SimpleXMLElement $node): string
    {
        if (! $node) {
            return '';
        }
        if (isset($node->t)) {
            return (string) $node->t;
        }

        return implode('', array_map(fn ($run) => (string) $run->t, iterator_to_array($node->r, false)));
    }

    // XML-safe text: escaped, and without the control characters XML can't hold.
    private static function text(string $value): string
    {
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
