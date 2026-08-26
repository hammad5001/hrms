<?php
/**
 * Robust Spreadsheet (XLSX / XLS / CSV) Reader Helper
 * Handles namespaces (e.g. xmlns:x="..."), SharedStrings, InlineStrings, Direct Numbers/Dates, and multi-sheet XMLs.
 */

class SimpleSpreadsheetReader {

    /**
     * Parse an uploaded file (.xlsx, .xls, .csv, .txt) into an array of rows (each row is an array of cell values)
     *
     * @param string $filePath
     * @param string $extension
     * @return array
     */
    public static function parse(string $filePath, string $extension): array {
        $ext = strtolower($extension);

        if ($ext === 'csv' || $ext === 'txt') {
            return self::parseCSV($filePath);
        } elseif ($ext === 'xlsx') {
            return self::parseXLSX($filePath);
        } elseif ($ext === 'xls') {
            return self::parseLegacyXLS($filePath);
        }

        throw new Exception("Unsupported file format: .$ext. Please upload .xlsx, .xls or .csv file.");
    }

    /**
     * Parse CSV
     */
    private static function parseCSV(string $filePath): array {
        $rows = [];
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new Exception("Unable to open CSV file.");
        }

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);
        return $rows;
    }

    /**
     * Parse XLSX (OpenXML format using ZipArchive + SimpleXML / DOMDocument)
     */
    private static function parseXLSX(string $filePath): array {
        if (!class_exists('ZipArchive')) {
            throw new Exception("ZipArchive PHP extension is required for XLSX files.");
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new Exception("Could not open XLSX file. The file might be corrupted.");
        }

        // 1. Read Shared Strings (if any)
        $sharedStrings = [];
        $sharedStringsXML = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedStringsXML !== false) {
            $dom = new DOMDocument();
            @$dom->loadXML($sharedStringsXML);
            $siList = $dom->getElementsByTagName('si');
            foreach ($siList as $si) {
                // Get all text inside <t> elements within <si>
                $tNodes = $si->getElementsByTagName('t');
                $text = '';
                foreach ($tNodes as $t) {
                    $text .= $t->nodeValue;
                }
                $sharedStrings[] = $text;
            }
        }

        // 2. Read Worksheets (Try sheet1, then first available sheet with rows)
        $sheetXML = $zip->getFromName('xl/worksheets/sheet1.xml');
        $sheetIndex = 1;

        // If sheet1 is not found, search all worksheets
        if ($sheetXML === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (strpos($filename, 'xl/worksheets/sheet') === 0 && strpos($filename, '.xml') !== false) {
                    $sheetXML = $zip->getFromIndex($i);
                    break;
                }
            }
        }

        $allSheetsRows = [];

        // Parse sheet with DOMDocument (namespace agnostic)
        if ($sheetXML !== false) {
            $allSheetsRows = self::parseSheetXML($sheetXML, $sharedStrings);
        }

        // If sheet 1 was empty, check other sheets
        if (empty($allSheetsRows)) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (strpos($filename, 'xl/worksheets/sheet') === 0 && strpos($filename, '.xml') !== false) {
                    $otherXML = $zip->getFromIndex($i);
                    $candidateRows = self::parseSheetXML($otherXML, $sharedStrings);
                    if (!empty($candidateRows)) {
                        $allSheetsRows = $candidateRows;
                        break;
                    }
                }
            }
        }

        $zip->close();
        return $allSheetsRows;
    }

    /**
     * Parses sheet XML content using DOMDocument so prefixed tags (e.g. <x:row>, <x:c>) work cleanly
     */
    private static function parseSheetXML(string $xmlContent, array $sharedStrings): array {
        $dom = new DOMDocument();
        @$dom->loadXML($xmlContent);

        $rows = [];
        $rowNodes = $dom->getElementsByTagName('row');

        foreach ($rowNodes as $row) {
            $rowData = [];
            $maxColIdx = -1;

            $cellNodes = $row->getElementsByTagName('c');
            foreach ($cellNodes as $cell) {
                $ref = $cell->getAttribute('r'); // e.g. "A1", "C2"
                $colLetters = preg_replace('/[0-9]/', '', $ref);
                $colIdx = self::columnLetterToIndex($colLetters);

                $type = $cell->getAttribute('t');
                $val = '';

                if ($type === 's') {
                    // Shared String lookup
                    $vNodes = $cell->getElementsByTagName('v');
                    if ($vNodes->length > 0) {
                        $sIdx = (int)$vNodes->item(0)->nodeValue;
                        $val = $sharedStrings[$sIdx] ?? '';
                    }
                } elseif ($type === 'inlineStr') {
                    $tNodes = $cell->getElementsByTagName('t');
                    if ($tNodes->length > 0) {
                        $val = $tNodes->item(0)->nodeValue;
                    }
                } else {
                    // Direct value (number, date, boolean, string)
                    $vNodes = $cell->getElementsByTagName('v');
                    if ($vNodes->length > 0) {
                        $val = $vNodes->item(0)->nodeValue;
                    }
                }

                $rowData[$colIdx] = trim($val);
                if ($colIdx > $maxColIdx) {
                    $maxColIdx = $colIdx;
                }
            }

            if ($maxColIdx >= 0) {
                $rowArray = [];
                for ($c = 0; $c <= $maxColIdx; $c++) {
                    $rowArray[$c] = $rowData[$c] ?? '';
                }

                // Only include if at least one cell has content
                $hasContent = false;
                foreach ($rowArray as $v) {
                    if ($v !== '') { $hasContent = true; break; }
                }

                if ($hasContent) {
                    $rows[] = $rowArray;
                }
            }
        }

        return $rows;
    }

    /**
     * Fallback for legacy .xls (often tab-delimited or XML/HTML table)
     */
    private static function parseLegacyXLS(string $filePath): array {
        $content = file_get_contents($filePath);
        if (strpos($content, '<table') !== false) {
            // HTML table export
            $rows = [];
            $dom = new DOMDocument();
            @$dom->loadHTML($content);
            $trList = $dom->getElementsByTagName('tr');
            foreach ($trList as $tr) {
                $row = [];
                $tdList = $tr->getElementsByTagName('td');
                if ($tdList->length === 0) {
                    $tdList = $tr->getElementsByTagName('th');
                }
                foreach ($tdList as $td) {
                    $row[] = trim($td->textContent);
                }
                if (!empty($row)) {
                    $rows[] = $row;
                }
            }
            return $rows;
        }

        // Try tab-delimited
        $rows = [];
        $handle = fopen($filePath, 'r');
        while (($row = fgetcsv($handle, 0, "\t")) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }

    /**
     * Convert Excel column letter to 0-indexed integer (A->0, B->1, Z->25, AA->26)
     */
    private static function columnLetterToIndex(string $letters): int {
        $letters = strtoupper(trim($letters));
        if ($letters === '') return 0;

        $len = strlen($letters);
        $index = 0;
        for ($i = 0; $i < $len; $i++) {
            $index *= 26;
            $index += ord($letters[$i]) - ord('A') + 1;
        }
        return $index - 1;
    }
}
