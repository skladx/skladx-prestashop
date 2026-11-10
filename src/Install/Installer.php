<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Install;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Database tables of the module.
 *
 * - skladx_order: SkladX state of an order (id, status, tracking, export state, pickup point)
 * - skladx_cart_pickup: pickup point chosen at checkout (copied to skladx_order on validation)
 * - skladx_product: storage class and sync state per product / combination
 * - skladx_queue: job queue (order export, product sync, stock pull, events) with retries
 * - skladx_event: inbox of received SkladX events (dedupe by event id)
 * - skladx_cache: pickup point search cache and rate limits
 * - skladx_receipt: last known summary of inbound receipts (admin notices)
 */
final class Installer
{
    /**
     * @return array<string, string> table (without prefix) => CREATE TABLE body
     */
    public static function tables(): array
    {
        return [
            'skladx_order' => '(
                `id_order` INT UNSIGNED NOT NULL,
                `skladx_id` VARCHAR(64) NOT NULL DEFAULT \'\',
                `skladx_number` VARCHAR(64) NOT NULL DEFAULT \'\',
                `status` VARCHAR(32) NOT NULL DEFAULT \'\',
                `status_label` VARCHAR(128) NOT NULL DEFAULT \'\',
                `status_changed_at` VARCHAR(40) NOT NULL DEFAULT \'\',
                `tracking_number` VARCHAR(128) NOT NULL DEFAULT \'\',
                `tracking_url` VARCHAR(512) NOT NULL DEFAULT \'\',
                `carrier` VARCHAR(32) NOT NULL DEFAULT \'\',
                `is_test` TINYINT(1) NOT NULL DEFAULT 0,
                `export_state` VARCHAR(16) NOT NULL DEFAULT \'\',
                `export_error` TEXT NULL,
                `export_revision` INT UNSIGNED NOT NULL DEFAULT 0,
                `address_hash` CHAR(32) NOT NULL DEFAULT \'\',
                `pickup_carrier` VARCHAR(32) NOT NULL DEFAULT \'\',
                `pickup_point_id` VARCHAR(64) NOT NULL DEFAULT \'\',
                `pickup_point` TEXT NULL,
                `last_sync` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_order`),
                KEY `skladx_id` (`skladx_id`)
            )',
            'skladx_cart_pickup' => '(
                `id_cart` INT UNSIGNED NOT NULL,
                `carrier` VARCHAR(32) NOT NULL DEFAULT \'\',
                `point_id` VARCHAR(64) NOT NULL DEFAULT \'\',
                `point` TEXT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_cart`)
            )',
            'skladx_product' => '(
                `id_product` INT UNSIGNED NOT NULL,
                `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
                `storage_class` VARCHAR(64) NOT NULL DEFAULT \'\',
                `synced_at` INT UNSIGNED NOT NULL DEFAULT 0,
                `sync_hash` CHAR(32) NOT NULL DEFAULT \'\',
                `sync_error` TEXT NULL,
                `sync_error_code` VARCHAR(32) NOT NULL DEFAULT \'\',
                `stock_event_id` VARCHAR(64) NOT NULL DEFAULT \'\',
                PRIMARY KEY (`id_product`, `id_product_attribute`)
            )',
            'skladx_queue' => '(
                `id_skladx_queue` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `job` VARCHAR(32) NOT NULL,
                `ref` VARCHAR(191) NOT NULL DEFAULT \'\',
                `payload` TEXT NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
                `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                `run_after` DATETIME NOT NULL,
                `locked_until` DATETIME NULL,
                `last_error` TEXT NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_skladx_queue`),
                KEY `due` (`status`, `run_after`),
                KEY `job_ref` (`job`, `ref`, `status`)
            )',
            'skladx_event' => '(
                `event_id` VARCHAR(64) NOT NULL,
                `type` VARCHAR(64) NOT NULL,
                `source` VARCHAR(16) NOT NULL,
                `payload` MEDIUMTEXT NOT NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
                `error` TEXT NULL,
                `received_at` DATETIME NOT NULL,
                `processed_at` DATETIME NULL,
                PRIMARY KEY (`event_id`),
                KEY `received_at` (`received_at`)
            )',
            'skladx_cache' => '(
                `cache_key` VARCHAR(64) NOT NULL,
                `value` MEDIUMTEXT NOT NULL,
                `expires_at` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`cache_key`),
                KEY `expires_at` (`expires_at`)
            )',
            'skladx_receipt' => '(
                `id_receipt` VARCHAR(64) NOT NULL,
                `summary` TEXT NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_receipt`)
            )',
        ];
    }

    public static function install(): bool
    {
        $db = \Db::getInstance();

        foreach (self::tables() as $table => $body) {
            $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . $table . '` ' . $body . ' ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

            if (!$db->execute($sql)) {
                return false;
            }
        }

        return true;
    }

    public static function uninstall(): bool
    {
        $db = \Db::getInstance();

        foreach (array_keys(self::tables()) as $table) {
            $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`');
        }

        return true;
    }
}
