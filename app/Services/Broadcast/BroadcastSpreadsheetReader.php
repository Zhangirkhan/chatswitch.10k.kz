<?php

declare(strict_types=1);

namespace App\Services\Broadcast;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

final class BroadcastSpreadsheetReader
{
    private const MAX_ROWS = 500;

    /**
     * @return list<array{row: int, phone: string, message: string}>
     */
    public function read(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        $path = $file->getRealPath();
        if ($path === false) {
            throw ValidationException::withMessages(['file' => ['Не удалось прочитать файл.']]);
        }

        $rows = match ($ext) {
            'csv', 'txt' => $this->readCsv($path),
            'xlsx' => $this->readXlsx($path),
            default => throw ValidationException::withMessages([
                'file' => ['Поддерживаются файлы .xlsx и .csv (2 столбца: номер и текст).'],
            ]),
        };

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => ['Файл пуст или не содержит данных.']]);
        }

        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'file' => ['Максимум '.self::MAX_ROWS.' строк за одну рассылку.'],
            ]);
        }

        return $rows;
    }

    /**
     * @return list<array{row: int, phone: string, message: string}>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Не удалось открыть CSV.');
        }

        $rows = [];
        $rowNum = 0;
        while (($line = fgetcsv($handle)) !== false) {
            $rowNum++;
            if ($this->isHeaderRow($line, $rowNum)) {
                continue;
            }
            $parsed = $this->parseColumns($line, $rowNum);
            if ($parsed !== null) {
                $rows[] = $parsed;
            }
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return list<array{row: int, phone: string, message: string}>
     */
    private function readXlsx(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);

        $rows = [];
        $rowNum = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rowNum++;
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $cells[] = trim((string) $cell->getValue());
                }
                if ($this->isHeaderRow($cells, $rowNum)) {
                    continue;
                }
                $parsed = $this->parseColumns($cells, $rowNum);
                if ($parsed !== null) {
                    $rows[] = $parsed;
                }
            }
            break;
        }

        $reader->close();

        return $rows;
    }

    /**
     * @param  list<string>  $cells
     */
    private function isHeaderRow(array $cells, int $rowNum): bool
    {
        if ($rowNum !== 1) {
            return false;
        }
        $joined = mb_strtolower(implode(' ', $cells));
        if (str_contains($joined, 'номер') || str_contains($joined, 'phone') || str_contains($joined, 'телефон')) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<string|null>  $cells
     * @return array{row: int, phone: string, message: string}|null
     */
    private function parseColumns(array $cells, int $rowNum): ?array
    {
        $phone = trim((string) ($cells[0] ?? ''));
        $message = trim((string) ($cells[1] ?? ''));
        if ($phone === '' && $message === '') {
            return null;
        }
        if ($phone === '' || $message === '') {
            throw ValidationException::withMessages([
                'file' => ["Строка {$rowNum}: нужны оба столбца — номер и текст сообщения."],
            ]);
        }

        return [
            'row' => $rowNum,
            'phone' => $phone,
            'message' => $message,
        ];
    }
}
