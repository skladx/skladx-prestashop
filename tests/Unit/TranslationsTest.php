<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TranslationsTest extends TestCase
{
    public function testEveryStringHasASlovakTranslation(): void
    {
        $root = dirname(__DIR__, 2);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/dev/i18n.php') . ' --check 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
    }

    public function testGeneratedTranslationFileIsUpToDate(): void
    {
        $root = dirname(__DIR__, 2);
        $before = (string) file_get_contents($root . '/translations/sk.php');
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/dev/i18n.php') . ' 2>&1');
        $after = (string) file_get_contents($root . '/translations/sk.php');

        $this->assertSame($before, $after, 'Run php dev/i18n.php and commit translations/sk.php.');

        global $_MODULE;
        include $root . '/translations/sk.php';
        $this->assertGreaterThan(300, count($_MODULE));
        $this->assertSame('Vybrať výdajné miesto', $_MODULE['<{skladx}prestashop>carrier_extra_' . md5('Choose pickup point')]);
    }
}
