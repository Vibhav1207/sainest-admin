<?php
/**
 * Lightweight, high-performance native XLSX generator for Hotel Sai Nest HMS.
 * Uses PHP ZipArchive to produce 100% compliant Microsoft Excel (.xlsx) files
 * with custom styling, headers, currency/date formatting, frozen panes and totals.
 */
class XlsxWriter {
    private string $hotelName;
    private string $reportName;
    private string $generatedAt;
    private string $filterSummary;
    private array $sheets = [];

    public function __construct(string $hotelName, string $reportName, string $filterSummary = 'All Data') {
        $this->hotelName     = $hotelName;
        $this->reportName    = $reportName;
        $this->generatedAt   = date('d M Y, h:i A');
        $this->filterSummary = $filterSummary;
    }

    public function addSheet(string $title, array $headers, array $rows, array $columnTypes = [], ?array $totals = null): void {
        $this->sheets[] = [
            'title'       => substr(preg_replace('/[^\w\s-]/', '', $title), 0, 31) ?: 'Sheet1',
            'headers'     => $headers,
            'rows'        => $rows,
            'colTypes'    => $columnTypes,
            'totals'      => $totals,
        ];
    }

    public function download(string $filename = 'Report.xlsx'): void {
        $tempPath = sys_get_temp_dir() . '/xlsx_' . bin2hex(random_bytes(8)) . '.xlsx';
        $this->saveToFile($tempPath);

        if (!file_exists($tempPath)) {
            throw new RuntimeException('Failed to generate Excel file.');
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        header('Content-Length: ' . filesize($tempPath));
        header('Cache-Control: max-age=0');
        readfile($tempPath);
        @unlink($tempPath);
        exit;
    }

    public function saveToFile(string $filePath): void {
        $zip = new ZipArchive();
        if ($zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create zip file at ' . $filePath);
        }

        $zip->addFromString('[Content_Types].xml', $this->buildContentTypesXml());
        $zip->addFromString('_rels/.rels', $this->buildRelsXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->buildWorkbookRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->buildWorkbookXml());
        $zip->addFromString('xl/styles.xml', $this->buildStylesXml());

        foreach ($this->sheets as $idx => $sheet) {
            $sheetNum = $idx + 1;
            $zip->addFromString("xl/worksheets/sheet{$sheetNum}.xml", $this->buildSheetXml($sheet));
        }

        $zip->close();
    }

    private function buildContentTypesXml(): string {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n";
        $xml .= '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' . "\n";
        $xml .= '  <Default Extension="xml" ContentType="application/xml"/>' . "\n";
        $xml .= '  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . "\n";
        $xml .= '  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . "\n";
        foreach ($this->sheets as $idx => $sheet) {
            $sNum = $idx + 1;
            $xml .= "  <Override PartName=\"/xl/worksheets/sheet{$sNum}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>\n";
        }
        $xml .= '</Types>';
        return $xml;
    }

    private function buildRelsXml(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n" .
            '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' . "\n" .
            '</Relationships>';
    }

    private function buildWorkbookRelsXml(): string {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        $xml .= '  <Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' . "\n";
        foreach ($this->sheets as $idx => $sheet) {
            $rId = 'rIdSheet' . ($idx + 1);
            $sNum = $idx + 1;
            $xml .= "  <Relationship Id=\"{$rId}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$sNum}.xml\"/>\n";
        }
        $xml .= '</Relationships>';
        return $xml;
    }

    private function buildWorkbookXml(): string {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
        $xml .= '  <sheets>' . "\n";
        foreach ($this->sheets as $idx => $sheet) {
            $rId = 'rIdSheet' . ($idx + 1);
            $sId = $idx + 1;
            $name = htmlspecialchars($sheet['title'], ENT_QUOTES, 'UTF-8');
            $xml .= "    <sheet name=\"{$name}\" sheetId=\"{$sId}\" r:id=\"{$rId}\"/>\n";
        }
        $xml .= '  </sheets>' . "\n";
        $xml .= '</workbook>';
        return $xml;
    }

    private function buildStylesXml(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n" .
            '  <numFmts count="1">' . "\n" .
            '    <numFmt numFmtId="164" formatCode="&quot;&#8377;&quot;#,##0.00"/>' . "\n" .
            '  </numFmts>' . "\n" .
            '  <fonts count="4">' . "\n" .
            '    <font><sz val="10"/><name val="Segoe UI"/></font>' . "\n" . // 0: Normal
            '    <font><b/><sz val="10"/><name val="Segoe UI"/></font>' . "\n" . // 1: Bold
            '    <font><b/><sz val="14"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>' . "\n" . // 2: Title White
            '    <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>' . "\n" . // 3: Header White
            '  </fonts>' . "\n" .
            '  <fills count="5">' . "\n" .
            '    <fill><patternFill patternType="none"/></fill>' . "\n" .
            '    <fill><patternFill patternType="gray125"/></fill>' . "\n" .
            '    <fill><patternFill patternType="solid"><fgColor rgb="FF2C2416"/><bgColor indexed="64"/></patternFill></fill>' . "\n" . // 2: Dark Header (#2C2416)
            '    <fill><patternFill patternType="solid"><fgColor rgb="FFC9A84C"/><bgColor indexed="64"/></patternFill></fill>' . "\n" . // 3: Gold Accent (#C9A84C)
            '    <fill><patternFill patternType="solid"><fgColor rgb="FFFAF9F5"/><bgColor indexed="64"/></patternFill></fill>' . "\n" . // 4: Alternate Cream Row (#FAF9F5)
            '  </fills>' . "\n" .
            '  <borders count="2">' . "\n" .
            '    <border><left/><right/><top/><bottom/></border>' . "\n" . // 0: None
            '    <border><top style="thin"><color rgb="FFCCCCCC"/></top><bottom style="double"><color rgb="FF2C2416"/></bottom></border>' . "\n" . // 1: Totals Border
            '  </borders>' . "\n" .
            '  <cellStyleXfs count="1">' . "\n" .
            '    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>' . "\n" .
            '  </cellStyleXfs>' . "\n" .
            '  <cellXfs count="11">' . "\n" .
            '    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' . "\n" . // 0: Default
            '    <xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>' . "\n" . // 1: Title Banner
            '    <xf numFmtId="0" fontId="3" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>' . "\n" . // 2: Column Header Gold
            '    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' . "\n" . // 3: Text Data Normal
            '    <xf numFmtId="0" fontId="0" fillId="4" borderId="0" xfId="0" applyFill="1"/>' . "\n" . // 4: Text Data Alt
            '    <xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right"/></xf>' . "\n" . // 5: Currency Normal
            '    <xf numFmtId="164" fontId="0" fillId="4" borderId="0" xfId="0" applyNumberFormat="1" applyFill="1" applyAlignment="1"><alignment horizontal="right"/></xf>' . "\n" . // 6: Currency Alt
            '    <xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="right"/></xf>' . "\n" . // 7: Number Normal
            '    <xf numFmtId="1" fontId="0" fillId="4" borderId="0" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="right"/></xf>' . "\n" . // 8: Number Alt
            '    <xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"/>' . "\n" . // 9: Total Text
            '    <xf numFmtId="164" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right"/></xf>' . "\n" . // 10: Total Currency
            '  </cellXfs>' . "\n" .
            '</styleSheet>';
    }

    private function buildSheetXml(array $sheet): string {
        $headers  = $sheet['headers'];
        $rows     = $sheet['rows'];
        $colTypes = $sheet['colTypes'];
        $totals   = $sheet['totals'];

        $numCols = count($headers);

        // Auto-calculate column widths
        $colWidths = array_fill(0, $numCols, 12);
        foreach ($headers as $i => $h) {
            $colWidths[$i] = max($colWidths[$i], mb_strlen($h) + 4);
        }
        foreach ($rows as $r) {
            foreach ($r as $i => $val) {
                if (isset($colWidths[$i])) {
                    $str = is_numeric($val) ? number_format((float)$val, 2) : (string)$val;
                    $colWidths[$i] = max($colWidths[$i], min(mb_strlen($str) + 3, 45));
                }
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
        
        // Column widths
        $xml .= '  <cols>' . "\n";
        foreach ($colWidths as $i => $w) {
            $colNum = $i + 1;
            $xml .= "    <col min=\"{$colNum}\" max=\"{$colNum}\" width=\"{$w}\" customWidth=\"1\"/>\n";
        }
        $xml .= '  </cols>' . "\n";

        $xml .= '  <sheetData>' . "\n";

        $rowNum = 1;

        // Row 1: Title Banner
        $lastColRef = $this->colName($numCols - 1);
        $xml .= "    <row r=\"{$rowNum}\" ht=\"32\" customHeight=\"1\">\n";
        $xml .= "      <c r=\"A1\" s=\"1\" t=\"inlineStr\"><is><t>" . $this->esc($this->hotelName . ' — ' . $this->reportName) . "</t></is></c>\n";
        $xml .= "    </row>\n";
        $rowNum++;

        // Row 2: Metadata
        $metaText = "Generated: " . $this->generatedAt . " | Filters: " . $this->filterSummary;
        $xml .= "    <row r=\"{$rowNum}\" ht=\"20\" customHeight=\"1\">\n";
        $xml .= "      <c r=\"A2\" t=\"inlineStr\"><is><t>" . $this->esc($metaText) . "</t></is></c>\n";
        $xml .= "    </row>\n";
        $rowNum++;

        // Row 3: Blank spacing
        $rowNum++;

        // Row 4: Column Headers
        $headerRowNum = $rowNum;
        $xml .= "    <row r=\"{$rowNum}\" ht=\"26\" customHeight=\"1\">\n";
        foreach ($headers as $i => $h) {
            $cellRef = $this->colName($i) . $rowNum;
            $xml .= "      <c r=\"{$cellRef}\" s=\"2\" t=\"inlineStr\"><is><t>" . $this->esc($h) . "</t></is></c>\n";
        }
        $xml .= "    </row>\n";
        $rowNum++;

        // Data Rows
        foreach ($rows as $rIdx => $r) {
            $isAlt = ($rIdx % 2 === 1);
            $xml .= "    <row r=\"{$rowNum}\">\n";
            foreach ($r as $cIdx => $val) {
                $cellRef = $this->colName($cIdx) . $rowNum;
                $type = $colTypes[$cIdx] ?? 'string';

                if ($type === 'currency' && is_numeric($val)) {
                    $styleId = $isAlt ? 6 : 5;
                    $xml .= "      <c r=\"{$cellRef}\" s=\"{$styleId}\"><v>{$val}</v></c>\n";
                } elseif ($type === 'number' && is_numeric($val)) {
                    $styleId = $isAlt ? 8 : 7;
                    $xml .= "      <c r=\"{$cellRef}\" s=\"{$styleId}\"><v>{$val}</v></c>\n";
                } else {
                    $styleId = $isAlt ? 4 : 3;
                    $strVal = (string)($val ?? '');
                    $xml .= "      <c r=\"{$cellRef}\" s=\"{$styleId}\" t=\"inlineStr\"><is><t>" . $this->esc($strVal) . "</t></is></c>\n";
                }
            }
            $xml .= "    </row>\n";
            $rowNum++;
        }

        // Totals Row if present
        if (!empty($totals)) {
            $xml .= "    <row r=\"{$rowNum}\" ht=\"24\" customHeight=\"1\">\n";
            foreach ($totals as $cIdx => $tVal) {
                $cellRef = $this->colName($cIdx) . $rowNum;
                $type = $colTypes[$cIdx] ?? 'string';
                if ($type === 'currency' && is_numeric($tVal)) {
                    $xml .= "      <c r=\"{$cellRef}\" s=\"10\"><v>{$tVal}</v></c>\n";
                } else {
                    $strVal = (string)($tVal ?? '');
                    $xml .= "      <c r=\"{$cellRef}\" s=\"9\" t=\"inlineStr\"><is><t>" . $this->esc($strVal) . "</t></is></c>\n";
                }
            }
            $xml .= "    </row>\n";
        }

        $xml .= '  </sheetData>' . "\n";

        // Freeze pane below headers
        $freezeRow = $headerRowNum;
        $xml .= "  <sheetViews>\n";
        $xml .= "    <sheetView tabSelected=\"1\" workbookViewId=\"0\">\n";
        $xml .= "      <pane ySplit=\"{$freezeRow}\" topLeftCell=\"A" . ($freezeRow + 1) . "\" activePane=\"bottomLeft\" state=\"frozen\"/>\n";
        $xml .= "    </sheetView>\n";
        $xml .= "  </sheetViews>\n";

        $xml .= '</worksheet>';
        return $xml;
    }

    private function colName(int $index): string {
        $numeric = $index % 26;
        $letter = chr(65 + $numeric);
        $num2 = (int)($index / 26);
        if ($num2 > 0) {
            return $this->colName($num2 - 1) . $letter;
        }
        return $letter;
    }

    private function esc(string $str): string {
        return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
