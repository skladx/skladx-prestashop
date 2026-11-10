<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Sync;

use SkladX\PrestaShop\Support\Logger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Private order messages ("SkladX: …") shown in the Messages card of the
 * order page — the order history of the integration.
 */
final class OrderNotes
{
    public static function add(\Order $order, string $message, bool $private = true): void
    {
        try {
            $customer = new \Customer((int) $order->id_customer);
            $threadId = (int) \CustomerThread::getIdCustomerThreadByEmailAndIdOrder((string) $customer->email, (int) $order->id);

            if ($threadId <= 0) {
                $thread = new \CustomerThread();
                $thread->id_contact = 0;
                $thread->id_customer = (int) $order->id_customer;
                $thread->id_shop = (int) $order->id_shop;
                $thread->id_order = (int) $order->id;
                $thread->id_lang = (int) $order->id_lang;
                $thread->email = (string) $customer->email;
                $thread->status = 'open';
                $thread->token = \Tools::passwdGen(12);
                $thread->add();
                $threadId = (int) $thread->id;
            }

            $customerMessage = new \CustomerMessage();
            $customerMessage->id_customer_thread = $threadId;
            $customerMessage->id_employee = 0;
            $customerMessage->message = mb_substr('SkladX: ' . $message, 0, 1600);
            $customerMessage->private = $private ? 1 : 0;
            $customerMessage->add();
        } catch (\Throwable $exception) {
            Logger::warning(sprintf('Order message for #%d could not be saved: %s', (int) $order->id, $exception->getMessage()));
        }
    }
}
