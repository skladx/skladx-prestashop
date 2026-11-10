<?php

namespace SkladX\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\PrestaShop\Inbound\BulkParser;
use SkladX\PrestaShop\Inbound\ValidationException;

final class BulkParserTest extends TestCase
{
    public function testParsesAllLineFormats(): void
    {
        $text = "RF-TRIKO-M;24\n"
            . "8588000000062;10;L2026-11\n"
            . "RF-FLASA;5;B7;31.12.2027\n"
            . "RF-HRNCEK;3;;2028-03\n";

        $this->assertSame(
            [
                ['line' => 1, 'code' => 'RF-TRIKO-M', 'quantity' => 24, 'batch' => '', 'expires_at' => ''],
                ['line' => 2, 'code' => '8588000000062', 'quantity' => 10, 'batch' => 'L2026-11', 'expires_at' => ''],
                ['line' => 3, 'code' => 'RF-FLASA', 'quantity' => 5, 'batch' => 'B7', 'expires_at' => '2027-12-31'],
                ['line' => 4, 'code' => 'RF-HRNCEK', 'quantity' => 3, 'batch' => '', 'expires_at' => '2028-03-31'],
            ],
            BulkParser::parse($text)['rows']
        );
    }

    public function testAcceptsTabsCommasSpacesHeaderCommentsAndWindowsLineEndings(): void
    {
        $text = "\xEF\xBB\xBFSKU\tPočet\tŠarža\tExpirácia\r\n"
            . "# poznámka\r\n"
            . "\r\n"
            . "A\t2\tL1\t2027-01-31\r\n"
            . "B,3\r\n"
            . "C 4\r\n"
            . "\"D\";\"5\";\r\n";

        $result = BulkParser::parse($text);

        $this->assertSame([], $result['errors']);
        $this->assertSame(
            [[4, 'A', 2, 'L1', '2027-01-31'], [5, 'B', 3, '', ''], [6, 'C', 4, '', ''], [7, 'D', 5, '', '']],
            array_map(static fn (array $row): array => array_values($row), $result['rows'])
        );
    }

    public function testHeaderIsOnlySkippedOnTheFirstLine(): void
    {
        $result = BulkParser::parse("A;1\nSKU;počet\n");

        $this->assertCount(1, $result['rows']);
        $this->assertSame([[2, 'quantity', ValidationException::INVALID_QUANTITY]], $this->summary($result['errors']));
    }

    public function testReportsErrorsWithLineNumbersAndKeepsValidLines(): void
    {
        $text = "A;1\n"
            . "B;0\n"
            . "C;x\n"
            . "D\n"
            . ";4\n"
            . 'E;2;' . str_repeat('L', 65) . "\n"
            . "F;2;L1;31.02.2027\n"
            . "G;1;L1;2027-01-01;extra\n"
            . "H;-2\n"
            . "I;1,5\n"
            . "J;7\n";

        $result = BulkParser::parse($text);

        $this->assertSame(['A', 'J'], array_column($result['rows'], 'code'));
        $this->assertSame([1, 11], array_column($result['rows'], 'line'));
        $this->assertSame(
            [
                [2, 'quantity', ValidationException::QUANTITY_POSITIVE],
                [3, 'quantity', ValidationException::INVALID_QUANTITY],
                [4, 'quantity', ValidationException::REQUIRED],
                [5, 'code', ValidationException::REQUIRED],
                [6, 'batch', ValidationException::TOO_LONG],
                [7, 'expires_at', ValidationException::INVALID_DATE],
                [8, 'line', ValidationException::TOO_MANY_COLUMNS],
                [9, 'quantity', ValidationException::QUANTITY_POSITIVE],
                [10, 'quantity', ValidationException::INVALID_QUANTITY],
            ],
            $this->summary($result['errors'])
        );

        $messages = (new ValidationException($result['errors']))->localized();

        $this->assertSame('Line 2: Quantity must be greater than 0.', $messages[0]['message']);
        $this->assertSame('Line 3: Quantity "x" is not a whole number.', $messages[1]['message']);
        $this->assertSame('Line 7: Expiry "31.02.2027" is not a valid date (use YYYY-MM-DD or DD.MM.YYYY).', $messages[5]['message']);
    }

    public function testSameCodeWithOtherBatchesStaysSeparate(): void
    {
        $rows = BulkParser::parse("A;1;L1\nA;2;L2\nA;3;L1\n")['rows'];

        $this->assertSame([1, 2, 3], array_column($rows, 'quantity'));
    }

    public function testLimitsTheNumberOfLines(): void
    {
        $result = BulkParser::parse(str_repeat("A;1\n", BulkParser::MAX_LINES + 5));

        $this->assertCount(BulkParser::MAX_LINES, $result['rows']);
        $this->assertSame([[BulkParser::MAX_LINES + 1, 'lines', ValidationException::TOO_MANY_LINES]], $this->summary($result['errors']));
    }

    public function testEmptyInput(): void
    {
        $this->assertSame(['rows' => [], 'errors' => []], BulkParser::parse("\n  \n"));
    }

    /**
     * @param list<array<string, mixed>> $errors errors
     *
     * @return list<array{int|null, string, string}>
     */
    private function summary(array $errors): array
    {
        return array_map(static fn (array $error): array => [$error['line'], $error['field'], $error['reason']], $errors);
    }
}
