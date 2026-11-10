<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Queue;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Thrown by a job handler: run the job again after $delay seconds.
 */
final class RetryLater extends \RuntimeException
{
    public function __construct(public readonly int $delay, string $message = '')
    {
        parent::__construct($message);
    }
}
