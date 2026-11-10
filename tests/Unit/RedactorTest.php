<?php

namespace SkladX\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\PrestaShop\Support\Redactor;

final class RedactorTest extends TestCase
{
    public function testMasksApiKeysSecretsAndBearerTokensInText(): void
    {
        $text = 'key skx_test_AbC123xyz and skx_live_ZZZ999, secret whsec_abcDEF123, header Bearer abc.def-123';

        $this->assertSame(
            'key skx_test_[redacted] and skx_live_[redacted], secret whsec_[redacted], header Bearer [redacted]',
            Redactor::redact($text)
        );
    }

    public function testMasksKnownSecretsAndSensitiveKeysInArrays(): void
    {
        $result = Redactor::redact(
            [
                'secret' => 'whsec_x',
                'nested' => ['Authorization' => 'Bearer x', 'note' => 'contains my-own-secret-value here'],
                'count' => 3,
            ],
            ['my-own-secret-value']
        );

        $this->assertSame(
            [
                'secret' => '[redacted]',
                'nested' => ['Authorization' => '[redacted]', 'note' => 'contains [redacted] here'],
                'count' => 3,
            ],
            $result
        );
    }

    public function testMaskKeepsOnlyTheLastFourCharactersOfLongSecrets(): void
    {
        $this->assertSame('••••••••WXYZ', Redactor::mask('skx_test_abcdefghWXYZ'));
        $this->assertSame('••••••••', Redactor::mask('short'));
        $this->assertSame('', Redactor::mask(''));
    }
}
