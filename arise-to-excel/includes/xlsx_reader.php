<?php

function xlsx_read_rows(string $filename): array
{
    if (!is_file($filename) || !is_readable($filename)) {
        throw new RuntimeException('The uploaded Excel file could not be read.');
    }

    $zipData = file_get_contents($filename);
    if ($zipData === false || strlen($zipData) < 22) {
        throw new RuntimeException('The uploaded Excel file is empty or invalid.');
    }

    $entries = xlsx_zip_entries($zipData);
    $sharedStrings = xlsx_shared_strings($entries);
    $sheetPath = xlsx_first_sheet_path($entries);

    if (!isset($entries[$sheetPath])) {
        throw new RuntimeException('The first worksheet could not be found in the Excel file.');
    }

    return xlsx_parse_sheet_rows($entries[$sheetPath], $sharedStrings);
}

function xlsx_zip_entries(string $zipData): array
{
    $eocdOffset = xlsx_end_of_central_directory_offset($zipData);
    $centralDirectorySize = xlsx_le32($zipData, $eocdOffset + 12);
    $centralDirectoryOffset = xlsx_le32($zipData, $eocdOffset + 16);
    $offset = $centralDirectoryOffset;
    $end = $centralDirectoryOffset + $centralDirectorySize;
    $entries = [];

    while ($offset < $end) {
        if (substr($zipData, $offset, 4) !== "PK\x01\x02") {
            break;
        }

        $method = xlsx_le16($zipData, $offset + 10);
        $compressedSize = xlsx_le32($zipData, $offset + 20);
        $nameLength = xlsx_le16($zipData, $offset + 28);
        $extraLength = xlsx_le16($zipData, $offset + 30);
        $commentLength = xlsx_le16($zipData, $offset + 32);
        $localHeaderOffset = xlsx_le32($zipData, $offset + 42);
        $name = str_replace('\\', '/', substr($zipData, $offset + 46, $nameLength));

        if (substr($zipData, $localHeaderOffset, 4) !== "PK\x03\x04") {
            throw new RuntimeException('The Excel file has an invalid ZIP structure.');
        }

        $localNameLength = xlsx_le16($zipData, $localHeaderOffset + 26);
        $localExtraLength = xlsx_le16($zipData, $localHeaderOffset + 28);
        $dataOffset = $localHeaderOffset + 30 + $localNameLength + $localExtraLength;
        $compressedData = substr($zipData, $dataOffset, $compressedSize);

        if ($method === 0) {
            $entries[$name] = $compressedData;
        } elseif ($method === 8) {
            $content = @gzinflate($compressedData);
            if ($content === false) {
                throw new RuntimeException('The Excel file could not be decompressed.');
            }
            $entries[$name] = $content;
        }

        $offset += 46 + $nameLength + $extraLength + $commentLength;
    }

    return $entries;
}

function xlsx_end_of_central_directory_offset(string $zipData): int
{
    $tailLength = min(strlen($zipData), 66000);
    $tail = substr($zipData, -$tailLength);
    $position = strrpos($tail, "PK\x05\x06");

    if ($position === false) {
        throw new RuntimeException('The uploaded file is not a valid .xlsx workbook.');
    }

    return strlen($zipData) - $tailLength + $position;
}

function xlsx_first_sheet_path(array $entries): string
{
    if (!isset($entries['xl/workbook.xml'], $entries['xl/_rels/workbook.xml.rels'])) {
        return 'xl/worksheets/sheet1.xml';
    }

    $workbook = @simplexml_load_string($entries['xl/workbook.xml']);
    $rels = @simplexml_load_string($entries['xl/_rels/workbook.xml.rels']);

    if (!$workbook || !$rels) {
        return 'xl/worksheets/sheet1.xml';
    }

    $sheets = $workbook->xpath('//*[local-name()="sheet"]');
    if (!$sheets) {
        return 'xl/worksheets/sheet1.xml';
    }

    $relationshipAttributes = $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $relationshipId = (string) ($relationshipAttributes['id'] ?? '');

    if ($relationshipId === '') {
        return 'xl/worksheets/sheet1.xml';
    }

    foreach ($rels->Relationship as $relationship) {
        $attributes = $relationship->attributes();
        if ((string) $attributes['Id'] === $relationshipId) {
            $target = (string) $attributes['Target'];
            if ($target === '') {
                break;
            }

            return str_starts_with($target, '/')
                ? ltrim($target, '/')
                : 'xl/' . ltrim($target, '/');
        }
    }

    return 'xl/worksheets/sheet1.xml';
}

function xlsx_shared_strings(array $entries): array
{
    if (!isset($entries['xl/sharedStrings.xml'])) {
        return [];
    }

    $xml = @simplexml_load_string($entries['xl/sharedStrings.xml']);
    if (!$xml) {
        throw new RuntimeException('The Excel shared strings could not be read.');
    }

    $strings = [];
    $items = $xml->xpath('//*[local-name()="si"]') ?: [];

    foreach ($items as $item) {
        $text = '';
        $textNodes = $item->xpath('.//*[local-name()="t"]') ?: [];

        foreach ($textNodes as $textNode) {
            $text .= (string) $textNode;
        }

        $strings[] = $text;
    }

    return $strings;
}

function xlsx_parse_sheet_rows(string $sheetXml, array $sharedStrings): array
{
    $xml = @simplexml_load_string($sheetXml);
    if (!$xml) {
        throw new RuntimeException('The first worksheet could not be parsed.');
    }

    $rows = [];
    $sheetRows = $xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [];

    foreach ($sheetRows as $sheetRow) {
        $row = [];
        $cells = $sheetRow->xpath('./*[local-name()="c"]') ?: [];
        $fallbackColumn = 0;

        foreach ($cells as $cell) {
            $attributes = $cell->attributes();
            $reference = strtoupper((string) ($attributes['r'] ?? ''));
            $columnIndex = xlsx_column_index($reference);

            if ($columnIndex === null) {
                $columnIndex = $fallbackColumn;
            }

            $row[$columnIndex] = xlsx_cell_value($cell, $sharedStrings);
            $fallbackColumn = $columnIndex + 1;
        }

        if ($row) {
            ksort($row);
            $rows[] = xlsx_normalize_row($row);
        }
    }

    return $rows;
}

function xlsx_cell_value(SimpleXMLElement $cell, array $sharedStrings): string
{
    $attributes = $cell->attributes();
    $type = (string) ($attributes['t'] ?? '');

    if ($type === 'inlineStr') {
        $text = '';
        $textNodes = $cell->xpath('.//*[local-name()="t"]') ?: [];
        foreach ($textNodes as $textNode) {
            $text .= (string) $textNode;
        }

        return trim($text);
    }

    $valueNodes = $cell->xpath('./*[local-name()="v"]');
    $value = $valueNodes ? (string) $valueNodes[0] : '';

    if ($type === 's') {
        return trim((string) ($sharedStrings[(int) $value] ?? ''));
    }

    if ($type === 'b') {
        return $value === '1' ? 'TRUE' : 'FALSE';
    }

    return trim($value);
}

function xlsx_normalize_row(array $row): array
{
    $normalized = [];
    $maxColumn = max(array_keys($row));

    for ($index = 0; $index <= $maxColumn; $index++) {
        $normalized[] = isset($row[$index]) ? trim((string) $row[$index]) : '';
    }

    return $normalized;
}

function xlsx_column_index(string $reference): ?int
{
    if (!preg_match('/^([A-Z]+)/', $reference, $matches)) {
        return null;
    }

    $letters = $matches[1];
    $index = 0;

    for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }

    return $index - 1;
}

function xlsx_le16(string $data, int $offset): int
{
    $value = unpack('vvalue', substr($data, $offset, 2));
    return (int) $value['value'];
}

function xlsx_le32(string $data, int $offset): int
{
    $value = unpack('Vvalue', substr($data, $offset, 4));
    return (int) $value['value'];
}
