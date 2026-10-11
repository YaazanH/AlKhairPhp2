<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class SpreadsheetImportReader
{
    public const MAX_DATA_ROWS = 1000;

    private const MAX_XML_BYTES = 10_000_000;

    /** @return array<int, array<string, string>> */
    public function read(string $path, string $extension): array
    {
        $rows = match (Str::lower($extension)) {
            'csv', 'txt' => $this->readCsv($path),
            'xlsx' => $this->readXlsx($path),
            default => throw new RuntimeException(__('crud.students.import.errors.file_type')),
        };

        if ($rows === []) {
            throw new RuntimeException(__('crud.students.import.errors.empty'));
        }

        $headers = array_map($this->normalizeHeader(...), array_shift($rows));

        if (in_array('', $headers, true) || count($headers) !== count(array_unique($headers))) {
            throw new RuntimeException(__('crud.students.import.errors.headers'));
        }

        $data = [];
        foreach ($rows as $index => $row) {
            $row = array_pad(array_slice($row, 0, count($headers)), count($headers), '');
            $record = array_combine($headers, array_map(fn ($value) => trim((string) $value), $row));

            if ($record !== false && collect($record)->contains(fn ($value) => $value !== '')) {
                foreach (['birth_date', 'joined_at'] as $dateField) {
                    if (isset($record[$dateField]) && is_numeric($record[$dateField]) && (float) $record[$dateField] > 1000) {
                        $record[$dateField] = gmdate('Y-m-d', (int) (((float) $record[$dateField] - 25569) * 86400));
                    }
                }
                $record['_line'] = (string) ($index + 2);
                $data[] = $record;
            }
        }

        if ($data === []) {
            throw new RuntimeException(__('crud.students.import.errors.empty'));
        }

        if (count($data) > self::MAX_DATA_ROWS) {
            throw new RuntimeException(__('crud.students.import.errors.too_many', ['count' => self::MAX_DATA_ROWS]));
        }

        return $data;
    }

    /** @return array<int, array<int, string>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(__('crud.students.import.errors.unreadable'));
        }

        try {
            $sample = (string) fgets($handle);
            rewind($handle);
            $delimiter = collect([',', ';', "\t"])
                ->sortByDesc(fn (string $candidate) => substr_count($sample, $candidate))
                ->first() ?: ',';
            $rows = [];

            while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $rows[] = array_map(fn ($value) => (string) ($value ?? ''), $row);
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @return array<int, array<int, string>> */
    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException(__('crud.students.import.errors.unreadable'));
        }

        try {
            foreach (['xl/worksheets/sheet1.xml', 'xl/sharedStrings.xml'] as $entry) {
                $stat = $zip->statName($entry);

                if ($stat !== false && ($stat['size'] ?? 0) > self::MAX_XML_BYTES) {
                    throw new RuntimeException(__('crud.students.import.errors.unreadable'));
                }
            }

            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');

            if ($sheetXml === false) {
                throw new RuntimeException(__('crud.students.import.errors.unreadable'));
            }

            $sharedStrings = $this->sharedStrings($zip);
            [$sheet, $xpath] = $this->xml($sheetXml);
            $rows = [];

            foreach ($xpath->query('//x:sheetData/x:row') ?: [] as $row) {
                $values = [];
                foreach ($xpath->query('./x:c', $row) ?: [] as $cell) {
                    if (! $cell instanceof DOMElement) {
                        continue;
                    }
                    $reference = $cell->getAttribute('r');
                    $column = $this->columnIndex($reference);
                    $type = $cell->getAttribute('t');
                    $value = '';

                    if ($type === 's') {
                        $value = $sharedStrings[(int) $this->nodeText($xpath, './x:v', $cell)] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $value = $this->nodeText($xpath, './/x:t', $cell, true);
                    } else {
                        $value = $this->nodeText($xpath, './x:v', $cell);
                    }

                    $values[$column] = $value;
                }

                if ($values !== []) {
                    $last = max(array_keys($values));
                    $rows[] = array_map(fn (int $column) => $values[$column] ?? '', range(0, $last));
                }
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        [, $xpath] = $this->xml($xml);
        $strings = [];

        foreach ($xpath->query('//x:si') ?: [] as $item) {
            $strings[] = $this->nodeText($xpath, './/x:t', $item, true);
        }

        return $strings;
    }

    /** @return array{DOMDocument, DOMXPath} */
    private function xml(string $contents): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;

        try {
            $loaded = $document->loadXML($contents, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            throw new RuntimeException(__('crud.students.import.errors.unreadable'));
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        return [$document, $xpath];
    }

    private function nodeText(DOMXPath $xpath, string $expression, \DOMNode $context, bool $all = false): string
    {
        $nodes = $xpath->query($expression, $context);

        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        if (! $all) {
            return (string) $nodes->item(0)?->textContent;
        }

        $value = '';
        foreach ($nodes as $node) {
            $value .= $node->textContent;
        }

        return $value;
    }

    private function columnIndex(string $reference): int
    {
        preg_match('/^[A-Z]+/i', $reference, $matches);
        $letters = strtoupper($matches[0] ?? 'A');
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', trim($header)) ?? '';

        return Str::of($header)->lower()->replace([' ', '-'], '_')->replaceMatches('/_+/', '_')->trim('_')->value();
    }
}
