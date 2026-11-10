<?php

namespace SkladX\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\PrestaShop\Inbound\ReceiptPayload;
use SkladX\PrestaShop\Inbound\ValidationException;

final class ReceiptPayloadTest extends TestCase
{
    private const CARRIERS = ['packeta', 'dpd_sk', 'gls_sk', 'sps', 'posta_sk', 'balikovo'];

    /**
     * @param array<string, mixed> $overrides input overrides
     *
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge(
            [
                'name' => 'Jesenná kolekcia',
                'reference' => 'PO-2026-15',
                'expected_at' => '2026-11-20',
                'carrier' => 'dpd_sk',
                'carrier_other' => '',
                'tracking_number' => '01234567890',
                'is_blind' => false,
                'note' => '',
                'warehouse_code' => '',
                'items' => [
                    ['sku' => 'RF-TRIKO-M', 'quantity' => '24', 'batch' => '', 'expires_at' => ''],
                    ['sku' => 'RF-FLASA', 'quantity' => 5, 'batch' => 'B7', 'expires_at' => '31.03.2028'],
                ],
            ],
            $overrides
        );
    }

    /**
     * @param array<string, mixed> $input input
     *
     * @return list<array<string, mixed>>
     */
    private function errors(array $input): array
    {
        try {
            ReceiptPayload::build($input, self::CARRIERS);
        } catch (ValidationException $exception) {
            return $exception->errors;
        }

        $this->fail('Expected a ValidationException.');
    }

    public function testBuildsTheApiPayload(): void
    {
        $payload = ReceiptPayload::build($this->input(), self::CARRIERS);

        $this->assertSame(
            [
                'name' => 'Jesenná kolekcia',
                'reference' => 'PO-2026-15',
                'expected_at' => '2026-11-20',
                'carrier' => 'dpd_sk',
                'tracking_number' => '01234567890',
                'is_blind' => false,
                'note' => null,
                'warehouse_code' => null,
                'items' => [
                    ['sku' => 'RF-TRIKO-M', 'quantity' => 24, 'batch' => null, 'expires_at' => null],
                    ['sku' => 'RF-FLASA', 'quantity' => 5, 'batch' => 'B7', 'expires_at' => '2028-03-31'],
                ],
            ],
            $payload
        );
    }

    public function testMergesIdenticalLinesButKeepsOtherBatchesSeparate(): void
    {
        $payload = ReceiptPayload::build(
            $this->input(
                [
                    'items' => [
                        ['sku' => 'A', 'quantity' => 2, 'batch' => 'L1', 'expires_at' => '2027-01-31'],
                        ['sku' => 'A', 'quantity' => 3, 'batch' => 'L1', 'expires_at' => '31.1.2027'],
                        ['sku' => 'A', 'quantity' => 4, 'batch' => 'L2', 'expires_at' => '2027-01-31'],
                        ['sku' => 'A', 'quantity' => 1, 'batch' => 'L1', 'expires_at' => ''],
                    ],
                ]
            ),
            self::CARRIERS
        );

        $this->assertSame(
            [
                ['sku' => 'A', 'quantity' => 5, 'batch' => 'L1', 'expires_at' => '2027-01-31'],
                ['sku' => 'A', 'quantity' => 4, 'batch' => 'L2', 'expires_at' => '2027-01-31'],
                ['sku' => 'A', 'quantity' => 1, 'batch' => 'L1', 'expires_at' => null],
            ],
            $payload['items']
        );
    }

    public function testPersonalDeliveryDropsTrackingAndOtherCarrierUsesItsName(): void
    {
        $personal = ReceiptPayload::build($this->input(['carrier' => 'personal']), self::CARRIERS);

        $this->assertSame('personal', $personal['carrier']);
        $this->assertNull($personal['tracking_number']);

        $other = ReceiptPayload::build($this->input(['carrier' => 'other', 'carrier_other' => 'Raben']), self::CARRIERS);

        $this->assertSame('Raben', $other['carrier']);

        $none = ReceiptPayload::build($this->input(['carrier' => '', 'tracking_number' => '']), self::CARRIERS);

        $this->assertNull($none['carrier']);
        $this->assertNull($none['tracking_number']);
    }

    public function testBlindFlagAndEmptyDraftAreAllowed(): void
    {
        $payload = ReceiptPayload::build($this->input(['is_blind' => '1', 'items' => []]), self::CARRIERS);

        $this->assertTrue($payload['is_blind']);
        $this->assertSame([], $payload['items']);
    }

    public function testHeaderValidation(): void
    {
        $errors = $this->errors(
            $this->input(
                [
                    'name' => '  ',
                    'expected_at' => '2026-02-30',
                    'carrier' => 'ups',
                    'tracking_number' => 'abc<script>',
                    'reference' => str_repeat('x', 65),
                ]
            )
        );

        $this->assertSame(
            [
                ['name', ValidationException::REQUIRED],
                ['expected_at', ValidationException::INVALID_DATE],
                ['carrier', ValidationException::INVALID_CARRIER],
                ['tracking_number', ValidationException::INVALID_TRACKING],
                ['reference', ValidationException::TOO_LONG],
            ],
            array_map(static fn (array $error): array => [$error['field'], $error['reason']], $errors)
        );
    }

    public function testExpectedDateIsRequiredAndOtherCarrierNeedsAName(): void
    {
        $errors = $this->errors($this->input(['expected_at' => '', 'carrier' => 'other']));

        $this->assertSame(['expected_at', 'carrier_other'], array_column($errors, 'field'));
        $this->assertSame([ValidationException::REQUIRED, ValidationException::REQUIRED], array_column($errors, 'reason'));
    }

    public function testItemValidationReportsLineNumbers(): void
    {
        $errors = $this->errors(
            $this->input(
                [
                    'items' => [
                        ['sku' => 'A', 'quantity' => 1],
                        ['sku' => 'B', 'quantity' => 0],
                        ['sku' => 'C', 'quantity' => '2.5'],
                        ['sku' => 'D', 'quantity' => -3],
                        ['sku' => '', 'quantity' => 1],
                        ['sku' => 'E', 'quantity' => 1, 'batch' => str_repeat('B', 65)],
                        ['sku' => 'F', 'quantity' => 1, 'expires_at' => '2027-13-01'],
                        ['sku' => 'G', 'quantity' => 1, 'expires_at' => 'zajtra'],
                        ['sku' => 'H', 'quantity' => ''],
                        ['sku' => 'I', 'quantity' => 1000001],
                    ],
                ]
            )
        );

        $this->assertSame(
            [
                [2, 'items.1.quantity', ValidationException::QUANTITY_POSITIVE],
                [3, 'items.2.quantity', ValidationException::INVALID_QUANTITY],
                [4, 'items.3.quantity', ValidationException::QUANTITY_POSITIVE],
                [5, 'items.4.sku', ValidationException::REQUIRED],
                [6, 'items.5.batch', ValidationException::TOO_LONG],
                [7, 'items.6.expires_at', ValidationException::INVALID_DATE],
                [8, 'items.7.expires_at', ValidationException::INVALID_DATE],
                [9, 'items.8.quantity', ValidationException::REQUIRED],
                [10, 'items.9.quantity', ValidationException::QUANTITY_TOO_LARGE],
            ],
            array_map(static fn (array $error): array => [$error['line'], $error['field'], $error['reason']], $errors)
        );
    }

    public function testMergedLinesCannotExceedTheMaximumQuantity(): void
    {
        $errors = $this->errors(
            $this->input(
                [
                    'items' => [
                        ['sku' => 'A', 'quantity' => 600000],
                        ['sku' => 'A', 'quantity' => 600000],
                    ],
                ]
            )
        );

        $this->assertSame(ValidationException::QUANTITY_TOO_LARGE, $errors[0]['reason']);
    }

    public function testLocalizedMessagesFallBackToEnglishWithoutWordpress(): void
    {
        try {
            ReceiptPayload::build($this->input(['items' => [['sku' => 'A', 'quantity' => 0]]]), self::CARRIERS);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                [
                    [
                        'field' => 'items.0.quantity',
                        'line' => 1,
                        'message' => 'Line 1: Quantity must be greater than 0.',
                    ],
                ],
                $exception->localized()
            );
        }
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function dates(): array
    {
        return [
            'iso' => ['2027-03-31', '2027-03-31'],
            'iso short' => ['2027-3-1', '2027-03-01'],
            'slovak' => ['31.03.2027', '2027-03-31'],
            'slovak spaces' => ['1. 3. 2027', '2027-03-01'],
            'slash' => ['31/03/2027', '2027-03-31'],
            'month slash' => ['02/2028', '2028-02-29'],
            'month dot' => ['11.2027', '2027-11-30'],
            'iso month' => ['2027-04', '2027-04-30'],
            'feb 30' => ['30.02.2027', null],
            'month 13' => ['2027-13-01', null],
            'two-digit year' => ['31.03.27', null],
            'text' => ['next week', null],
            'empty' => ['', null],
        ];
    }

    /**
     * @dataProvider dates
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('dates')]
    public function testNormalizesDates(string $input, ?string $expected): void
    {
        $this->assertSame($expected, ReceiptPayload::normalizeDate($input));
    }

    public function testAnnounceRequiresItemsUnlessBlind(): void
    {
        ReceiptPayload::assertAnnounceable(['is_blind' => true, 'items' => []]);
        ReceiptPayload::assertAnnounceable(['is_blind' => false, 'items' => [['sku' => 'A']]]);

        $this->expectException(ValidationException::class);
        ReceiptPayload::assertAnnounceable(['is_blind' => false, 'items' => []]);
    }
}
