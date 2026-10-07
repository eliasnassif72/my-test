<?php
// كاتب ملفات Excel (xlsx) بسيط بدون مكتبات خارجية — يدعم عدة أوراق، RTL، صف عناوين عريض
// يبني ملف zip بنفسه (تخزين بدون ضغط) فلا يحتاج ZipArchive على السيرفر.

class WlXlsx
{
    private $sheets = [];

    // $rows: مصفوفة صفوف؛ كل خلية نص أو رقم أو null. الصف الأول عناوين.
    public function addSheet($name, array $rows, array $widths = [])
    {
        $this->sheets[] = ['name' => $name, 'rows' => $rows, 'widths' => $widths];
    }

    private static function x($s)
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function col($i)
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m) . $s;
            $i = (int)(($i - $m) / 26);
        }
        return $s;
    }

    private function sheetXml($sh)
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
           . '<sheetViews><sheetView workbookViewId="0" rightToLeft="1"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        if ($sh['widths']) {
            $x .= '<cols>';
            foreach ($sh['widths'] as $i => $w) {
                $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        foreach ($sh['rows'] as $r => $cells) {
            $x .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($cells) as $c => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $ref = self::col($c) . ($r + 1);
                $style = $r === 0 ? ' s="1"' : '';
                if (is_int($v) || is_float($v)) {
                    $x .= '<c r="' . $ref . '"' . $style . '><v>' . (0 + $v) . '</v></c>';
                } else {
                    $x .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . self::x($v) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        return $x . '</sheetData></worksheet>';
    }

    public function build()
    {
        $files = [];
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wbSheets = '';
        $wbRels = '';
        foreach ($this->sheets as $i => $sh) {
            $n = $i + 1;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wbSheets .= '<sheet name="' . self::x(mb_substr($sh['name'], 0, 31)) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $files['xl/worksheets/sheet' . $n . '.xml'] = $this->sheetXml($sh);
        }
        $k = count($this->sheets) + 1;
        $wbRels .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $files['[Content_Types].xml'] = $ct . '</Types>';
        $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView/></bookViews><sheets>' . $wbSheets . '</sheets></workbook>';
        $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '</Relationships>';
        $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFD1FAE5"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '</styleSheet>';
        return self::zip($files);
    }

    // zip بطريقة التخزين (بدون ضغط) — متوافق مع Excel
    private static function zip(array $files)
    {
        $data = '';
        $central = '';
        $offset = 0;
        $time = (date('H') << 11) | (date('i') << 5) | (int)(date('s') / 2);
        $date = ((date('Y') - 1980) << 9) | (date('n') << 5) | date('j');
        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $len = strlen($content);
            $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $time, $date, $crc, $len, $len, strlen($name), 0) . $name;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $time, $date, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
            $data .= $local . $content;
            $offset += strlen($local) + $len;
        }
        return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), $offset, 0);
    }

    public function download($filename)
    {
        $bin = $this->build();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Content-Length: ' . strlen($bin));
        header('Cache-Control: no-store');
        echo $bin;
    }
}
