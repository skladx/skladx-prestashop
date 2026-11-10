<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Inbound;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Parses pasted receipt lines ("Hromadné pridanie"). Pure PHP — unit tested.
 *
 * One item per line: `code;quantity[;batch[;expiry]]`, where code is a
 * product reference (SKU) or an EAN. Columns may be separated by `;`, a tab
 * (pasted from a spreadsheet), `,` or — with only code and quantity —
 * spaces. Empty lines and lines starting with `#` are skipped, and so is a
 * header line such as `SKU;Počet;Šarža;Expirácia`. Expiry accepts every
 * format of ReceiptPayload::normalizeDate().
 *
 * Lines are not merged here: the same code with another batch or expiry is
 * a separate receipt line (ReceiptPayload merges exact duplicates).
 */
final class BulkParser
{
    public const MAX_LINES = ReceiptPayload::MAX_ITEMS;

    /** Words that mark the first line as a header. */
    private const HEADER_WORDS = ['sku', 'ean', 'kod', 'kód', 'code', 'produkt', 'product', 'kód/ean', 'sku/ean', 'reference', 'referencia'];

    /**
     * @return array{rows: list<array{line: int, code: string, quantity: int, batch: string, expires_at: string}>, errors: list<array{field: string, reason: string, line: int|null, value: string, max: int|null}>}
     */
    public static function parse(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $rows = [];
        $errors = [];
        $seen = false;

        foreach ($lines as $index => $rawLine) {
            $line = $index + 1;
            $content = trim($rawLine);

            if ($content === '' || str_starts_with($content, '#')) {
                continue;
            }

            $columns = self::columns($content);

            if (!$seen && self::isHeader($columns)) {
                $seen = true;
                continue;
            }

            $seen = true;

            if (count($rows) >= self::MAX_LINES) {
                $errors[] = ValidationException::error('lines', ValidationException::TOO_MANY_LINES, $line, '', self::MAX_LINES);
                break;
            }

            $row = self::row($columns, $line, $errors);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return [
            'rows' => $rows,
            'errors' => $errors,
        ];
    }

    /**
     * Splits a line into trimmed columns (trailing empty columns dropped).
     *
     * @return list<string>
     */
    public static function columns(string $line): array
    {
        if (str_contains($line, "\t")) {
            $columns = explode("\t", $line);
        } elseif (str_contains($line, ';')) {
            $columns = explode(';', $line);
        } elseif (str_contains($line, ',')) {
            $columns = explode(',', $line);
        } else {
            $columns = preg_split('/\s+/', $line) ?: [$line];
        }

        $columns = array_map(static fn (string $column): string => trim($column, " \t\"'"), $columns);

        while ($columns !== [] && end($columns) === '') {
            array_pop($columns);
        }

        return array_values($columns);
    }

    /**
     * @param list<string> $columns
     */
    private static function isHeader(array $columns): bool
    {
        $first = mb_strtolower($columns[0] ?? '', 'UTF-8');
        $second = $columns[1] ?? '';

        return in_array($first, self::HEADER_WORDS, true) && !preg_match('/^\d+$/', $second);
    }

    /**
     * @param list<string> $columns
     * @param list<array{field: string, reason: string, line: int|null, value: string, max: int|null}> $errors
     *
     * @return array{line: int, code: string, quantity: int, batch: string, expires_at: string}|null
     */
    private static function row(array $columns, int $line, array &$errors): ?array
    {
        if (count($columns) > 4) {
            $errors[] = ValidationException::error('line', ValidationException::TOO_MANY_COLUMNS, $line);

            return null;
        }

        $before = count($errors);
        $code = $columns[0] ?? '';

        if ($code === '') {
            $errors[] = ValidationException::error('code', ValidationException::REQUIRED, $line);
        } elseif (strlen($code) > ReceiptPayload::MAX_SKU) {
            $errors[] = ValidationException::error('code', ValidationException::TOO_LONG, $line, '', ReceiptPayload::MAX_SKU);
        }

        $reason = null;
        $quantity = ReceiptPayload::parseQuantity($columns[1] ?? '', $reason);

        if ($quantity === null) {
            $errors[] = ValidationException::error('quantity', (string) $reason, $line, $columns[1] ?? '', ReceiptPayload::MAX_QUANTITY);
        }

        $batch = $columns[2] ?? '';

        if (mb_strlen($batch, 'UTF-8') > ReceiptPayload::MAX_BATCH) {
            $errors[] = ValidationException::error('batch', ValidationException::TOO_LONG, $line, '', ReceiptPayload::MAX_BATCH);
        }

        $expiryRaw = $columns[3] ?? '';
        $expiresAt = $expiryRaw === '' ? '' : (string) ReceiptPayload::normalizeDate($expiryRaw);

        if ($expiryRaw !== '' && $expiresAt === '') {
            $errors[] = ValidationException::error('expires_at', ValidationException::INVALID_DATE, $line, $expiryRaw);
        }

        if (count($errors) > $before) {
            return null;
        }

        return [
            'line' => $line,
            'code' => $code,
            'quantity' => (int) $quantity,
            'batch' => $batch,
            'expires_at' => $expiresAt,
        ];
    }
}
