<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Inbound;

use SkladX\PrestaShop\Support\T;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Texts of the receipt editor script (views/js/inbound.js).
 */
final class EditorTexts
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'newReceipt' => T::__('New receipt'),
            'receiptTitle' => T::__('Receipt %s'),
            'tabDetail' => T::__('Detail'),
            'tabEdit' => T::__('Edit receipt'),
            'tabBulk' => T::__('Bulk add'),
            'tabScan' => T::__('Scan EAN'),
            'tabAttachments' => T::__('Attachments'),
            'name' => T::__('Name'),
            'namePlaceholder' => T::__('e.g. Autumn collection – delivery 1'),
            'expectedAt' => T::__('Expected delivery date'),
            'carrier' => T::__('Via carrier'),
            'chooseCarrier' => T::__('— Choose —'),
            'carrierOther' => T::__('Carrier name'),
            'tracking' => T::__('Tracking number'),
            'reference' => T::__('Reference (optional)'),
            'referenceLabel' => T::__('Reference'),
            'referenceHelp' => T::__('e.g. your purchase order or supplier invoice number.'),
            'blind' => T::__('Blind receipt'),
            'blindHelp' => T::__('The warehouse counts whatever arrives; the items below are only a guide.'),
            'note' => T::__('Note for the warehouse'),
            'items' => T::__('Items'),
            'product' => T::__('Product'),
            'productSearch' => T::__('Search by name, SKU or EAN…'),
            'sku' => T::__('SKU'),
            'ean' => T::__('EAN'),
            'quantity' => T::__('Quantity'),
            'pieces' => T::__('pcs'),
            'batch' => T::__('Batch'),
            'expiry' => T::__('Expiry'),
            'addItem' => T::__('Add item'),
            'remove' => T::__('Remove'),
            'noItems' => T::__('No items yet. Search for a product above, paste lines in Bulk add, or scan EANs.'),
            'noResults' => T::__('No product with a reference (SKU) matches.'),
            'searching' => T::__('Searching…'),
            'chooseProduct' => T::__('Choose a product from the list first.'),
            'notInSkladx' => T::__('Not in SkladX yet'),
            'notInSkladxHelp' => T::__('SkladX does not know this product yet. Sync it first, otherwise the receipt will be rejected.'),
            'notInShop' => T::__('Not found in the shop'),
            'syncError' => T::__('Last sync failed:'),
            'sync' => T::__('Sync to SkladX'),
            'syncAll' => T::__('Sync to SkladX (%d)'),
            'synced' => T::__('Products synced to SkladX.'),
            'syncing' => T::__('Syncing…'),
            'totals' => T::__('%1$d lines, %2$d pieces'),
            'saveDraft' => T::__('Save draft'),
            'saving' => T::__('Saving…'),
            'saved' => T::__('Draft saved in SkladX.'),
            'announce' => T::__('Send to warehouse'),
            'announceConfirm' => T::__('Send the receipt to the warehouse? It can no longer be edited afterwards.'),
            'announced' => T::__('The receipt was sent to the warehouse. Print the label and stick it on the parcel.'),
            'cancel' => T::__('Cancel receipt'),
            'cancelConfirm' => T::__('Cancel this receipt in SkladX?'),
            'cancelled' => T::__('The receipt was cancelled.'),
            'printLabel' => T::__('Print label'),
            'labelZpl' => T::__('Label (ZPL)'),
            'labelHelp' => T::__('Stick the label with the receipt code on the parcel so the warehouse can match it.'),
            'refresh' => T::__('Refresh'),
            'unsaved' => T::__('You have unsaved changes.'),
            'errorGeneric' => T::__('Something went wrong. Please try again.'),
            'number' => T::__('Number'),
            'status' => T::__('Status'),
            'warehouse' => T::__('Warehouse'),
            'created' => T::__('Created'),
            'announcedAt' => T::__('Sent to warehouse'),
            'receivedAt' => T::__('Received on'),
            'yes' => T::__('Yes'),
            'no' => T::__('No'),
            'expected' => T::__('Expected'),
            'received' => T::__('Received'),
            'difference' => T::__('Difference'),
            'total' => T::__('Total'),
            'differencesFound' => T::__('The warehouse received a different quantity than announced. See the differences below.'),
            'receivedOk' => T::__('Everything was received as announced.'),
            'bulkHelp' => T::__('One item per line: SKU or EAN; quantity; batch (optional); expiry (optional). Columns can be separated by semicolon, tab (paste from a spreadsheet) or comma. Expiry: YYYY-MM-DD, DD.MM.YYYY or MM/YYYY.'),
            'bulkPlaceholder' => "PS-TRI-M;24\n2000000000062;10;L2026-11;31.12.2027\nPS-FLA-01;5;B7;2028-03",
            'bulkAdd' => T::__('Add lines'),
            'bulkAdded' => T::__('%d lines added to the receipt.'),
            'bulkErrors' => T::__('These lines were not added:'),
            'bulkEmpty' => T::__('Paste at least one line.'),
            'scanHelp' => T::__('Click into the field and scan EAN codes with a USB scanner (or type a SKU/EAN and press Enter). Every scan adds the quantity to the line with the same product, batch and expiry.'),
            'scanPlaceholder' => T::__('Scan EAN or SKU'),
            'scanQuantity' => T::__('Pieces per scan'),
            'scanBatch' => T::__('Batch for scanned items'),
            'scanExpiry' => T::__('Expiry for scanned items'),
            'scanAdded' => T::__('%1$s – now %2$d pcs'),
            'scanSaveHint' => T::__('Scanned items are added to the receipt. Save the draft when you are done.'),
            'attachmentsHelp' => T::__('Attach the delivery note or photos of the shipment (PDF, JPG, PNG, WebP, up to 10 MB each).'),
            'attachmentsFirst' => T::__('Save the draft first, then you can attach files.'),
            'upload' => T::__('Upload'),
            'uploading' => T::__('Uploading…'),
            'uploaded' => T::__('%s uploaded to SkladX.'),
            'tooLarge' => T::__('The file is larger than 10 MB.'),
            'noAttachments' => T::__('No attachments yet.'),
            'readOnly' => T::__('The receipt has been sent to the warehouse and can no longer be edited.'),
        ];
    }
}
