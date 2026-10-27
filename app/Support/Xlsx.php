<?php

namespace App\Support;

use ZipArchive;

/**
 * A minimal .xlsx writer (one sheet) for exports that open in Excel, Google Sheets and LibreOffice:
 * bold, frozen header row with a filter, sensible column widths, numbers as numbers, web
 * addresses as clickable links, and PNG pictures (signatures) placed in their cells. Enough for
 * response tables without a spreadsheet library.
 */
class Xlsx
{
    /**
     * Pictures are shown this tall (pixels); their row grows to fit.
     */
    private const IMAGE_HEIGHT = 60;

    /**
     * A cell value that is a picture.
     *
     * @return array{png: string}
     */
    public static function image(string $png): array
    {
        return ['png' => $png];
    }

    /**
     * Writes the workbook to $path.
     *
     * @param  list<string>  $header
     * @param  iterable<list<string|int|float|array{png: string}|null>>  $rows
     */
    public static function write(string $path, string $sheetName, array $header, iterable $rows): void
    {
        $widths = array_map(fn (string $title): int => min(60, max(10, mb_strlen($title) + 2)), $header);
        $images = [];
        $xmlRows = [self::row(1, $header, true, $widths, $images)];
        $number = 1;
        foreach ($rows as $row) {
            $xmlRows[] = self::row(++$number, array_values($row), false, $widths, $images);
        }

        $lastColumn = self::column(max(1, count($header)) - 1);
        $cols = implode('', array_map(fn (int $width, int $index): string => '<col min="'.($index + 1).'" max="'.($index + 1).'" width="'.$width.'" customWidth="1"/>', $widths, array_keys($widths)));

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols>'.$cols.'</cols>'
            .'<sheetData>'.implode('', $xmlRows).'</sheetData>'
            .'<autoFilter ref="A1:'.$lastColumn.$number.'"/>'
            .($images !== [] ? '<drawing r:id="rId1"/>' : '')
            .'</worksheet>';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .($images !== [] ? '<Default Extension="png" ContentType="image/png"/><Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '')
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::escape(self::sheetName($sheetName)).'" sheetId="1" r:id="rId1"/></sheets>'
            .'<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">'.self::escape("'".self::sheetName($sheetName)."'").'!$A$1:$'.$lastColumn.'$'.$number.'</definedName></definedNames>'
            .'</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        // Styles: 0 plain (wrapped, top), 1 header (bold, shaded), 2 link (blue, underlined).
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><u/><sz val="11"/><color rgb="FF1155CC"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE2EEEA"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="3">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="top"/></xf>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        if ($images !== []) {
            self::addImages($zip, $images);
        }
        $zip->close();
    }

    /**
     * The pictures as a drawing on the sheet, each anchored to the top-left of its cell.
     *
     * @param  list<array{column: int, row: int, png: string, width: int, height: int}>  $images
     */
    private static function addImages(ZipArchive $zip, array $images): void
    {
        $anchors = '';
        $relations = '';
        foreach ($images as $index => $image) {
            $id = $index + 1;
            // Sizes are in EMU, 9525 per pixel; a small offset keeps the picture off the grid lines.
            $size = 'cx="'.($image['width'] * 9525).'" cy="'.($image['height'] * 9525).'"';
            $zip->addFromString("xl/media/image{$id}.png", $image['png']);
            $relations .= '<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image'.$id.'.png"/>';
            $anchors .= '<xdr:oneCellAnchor>'
                .'<xdr:from><xdr:col>'.$image['column'].'</xdr:col><xdr:colOff>38100</xdr:colOff><xdr:row>'.$image['row'].'</xdr:row><xdr:rowOff>38100</xdr:rowOff></xdr:from>'
                .'<xdr:ext '.$size.'/>'
                .'<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="'.($id + 1).'" name="Gambar '.$id.'"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
                .'<xdr:blipFill><a:blip r:embed="rId'.$id.'"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
                .'<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext '.$size.'/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic>'
                .'<xdr:clientData/></xdr:oneCellAnchor>';
        }

        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/drawings/drawing1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .$anchors.'</xdr:wsDr>');
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relations.'</Relationships>');
    }

    /**
     * @param  list<string|int|float|array{png: string}|null>  $values
     * @param  list<int>  $widths
     * @param  list<array{column: int, row: int, png: string, width: int, height: int}>  $images
     */
    private static function row(int $number, array $values, bool $header, array &$widths, array &$images): string
    {
        $cells = '';
        $height = null;
        foreach ($values as $index => $value) {
            $reference = self::column($index).$number;
            if (is_array($value)) {
                $size = @getimagesizefromstring($value['png'] ?? '');
                if ($size === false || $size[1] === 0) {
                    continue;
                }
                $imageWidth = (int) round($size[0] / $size[1] * self::IMAGE_HEIGHT);
                $images[] = ['column' => $index, 'row' => $number - 1, 'png' => $value['png'], 'width' => $imageWidth, 'height' => self::IMAGE_HEIGHT];
                // Column width is in characters (about 7 pixels each), row height in points (0.75 per pixel).
                $widths[$index] = min(60, max($widths[$index] ?? 10, (int) ceil(($imageWidth + 10) / 7)));
                $height = max($height ?? 0, (self::IMAGE_HEIGHT + 8) * 0.75);

                continue;
            }
            $text = self::clean((string) ($value ?? ''));
            if (! $header) {
                $longestLine = max(array_map('mb_strlen', explode("\n", $text)) ?: [0]);
                $widths[$index] = min(60, max($widths[$index] ?? 10, $longestLine + 2));
            }

            if ($text === '') {
                continue;
            }
            if ($header) {
                $cells .= '<c r="'.$reference.'" s="1" t="inlineStr"><is><t xml:space="preserve">'.self::escape($text).'</t></is></c>';
            } elseif (is_int($value) || is_float($value) || preg_match('/^-?(0|[1-9]\d{0,14})(\.\d+)?$/', $text) === 1) {
                // Plain numbers stay numbers; codes with leading zeros stay text.
                $cells .= '<c r="'.$reference.'"><v>'.$text.'</v></c>';
            } elseif (preg_match('~^https?://\S+$~', $text) === 1 && strlen($text) <= 255) {
                $cells .= '<c r="'.$reference.'" s="2" t="str"><f>'.self::escape('HYPERLINK("'.str_replace('"', '""', $text).'")').'</f><v>'.self::escape($text).'</v></c>';
            } else {
                $cells .= '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.self::escape($text).'</t></is></c>';
            }
        }

        return '<row r="'.$number.'"'.($height !== null ? ' ht="'.$height.'" customHeight="1"' : '').'>'.$cells.'</row>';
    }

    /**
     * Column letters: 0 → A, 25 → Z, 26 → AA.
     */
    public static function column(int $index): string
    {
        $letters = '';
        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $letters = chr(65 + ($index - 1) % 26).$letters;
        }

        return $letters;
    }

    private static function sheetName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', preg_replace('/[\[\]:*?\/\\\\\']/', ' ', $name) ?? '') ?? '');

        return $name !== '' ? rtrim(mb_substr($name, 0, 31)) : 'Respons';
    }

    /**
     * Excel cells hold at most 32,767 characters and no control characters.
     */
    private static function clean(string $text): string
    {
        return mb_substr(preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '', 0, 32000);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
