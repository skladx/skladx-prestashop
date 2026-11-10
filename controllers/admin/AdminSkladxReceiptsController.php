<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'skladx/src/autoload.php';

use SkladX\PrestaShop\Api\ApiException;
use SkladX\PrestaShop\Api\Client;
use SkladX\PrestaShop\Config\Settings;
use SkladX\PrestaShop\Inbound\BulkParser;
use SkladX\PrestaShop\Inbound\DbReceiptStore;
use SkladX\PrestaShop\Inbound\ProductLookup;
use SkladX\PrestaShop\Inbound\Receipt;
use SkladX\PrestaShop\Inbound\ReceiptPayload;
use SkladX\PrestaShop\Inbound\Receipts;
use SkladX\PrestaShop\Inbound\ValidationException;
use SkladX\PrestaShop\Queue\RetryLater;
use SkladX\PrestaShop\Support\T;
use SkladX\PrestaShop\Sync\ProductSync;

/**
 * Catalog → SkladX receipts ("Príjemky"): announce goods sent to the
 * warehouse. The list is rendered on the server (one `GET /inbound-receipts`
 * page, cursor pagination); the editor is plain JavaScript
 * (views/js/inbound.js) talking to the AJAX actions below. SkladX is the
 * source of truth; the API key never reaches the browser.
 */
class AdminSkladxReceiptsController extends ModuleAdminController
{
    /** Receipts per list page. */
    public const PAGE_SIZE = 25;

    /** Attachments: maximum size in bytes. */
    public const MAX_UPLOAD = 10485760;

    /** Attachments: allowed MIME types by extension. */
    public const UPLOAD_MIMES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function __construct()
    {
        $this->bootstrap = true;

        parent::__construct();

        $this->meta_title = T::__('SkladX receipts');
    }

    public function setMedia($isNewTheme = false): void
    {
        parent::setMedia($isNewTheme);

        $this->addCSS(_MODULE_DIR_ . 'skladx/views/css/inbound.css?v=' . self::assetVersion('views/css/inbound.css'));

        if ($this->isEditor()) {
            $this->addJS(_MODULE_DIR_ . 'skladx/views/js/inbound.js?v=' . self::assetVersion('views/js/inbound.js'));
        }
    }

    /**
     * Cache-busting version of a module asset.
     */
    public static function assetVersion(string $file): string
    {
        return Settings::MODULE_VERSION . '-' . (int) @filemtime(_PS_MODULE_DIR_ . 'skladx/' . $file);
    }

    public function initToolbar(): void
    {
        parent::initToolbar();

        if (Settings::isConfigured() && !$this->isEditor()) {
            $this->page_header_toolbar_btn['new_receipt'] = [
                'href' => self::$currentIndex . '&new=1&token=' . $this->token,
                'desc' => T::__('New receipt'),
                'icon' => 'process-icon-new',
            ];
        }
    }

    public function initPageHeaderToolbar(): void
    {
        $this->initToolbar();
        parent::initPageHeaderToolbar();
    }

    public function initContent(): void
    {
        parent::initContent();

        if (!Settings::isConfigured()) {
            $this->content = $this->renderMessage(T::__('SkladX is not configured. Fill in the API URL and key in Modules → SkladX → Configure.'), 'warning', $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => 'skladx']), T::__('Open SkladX settings'));
        } else {
            $this->content = $this->isEditor() ? $this->renderEditor() : $this->renderReceiptList();
        }

        $this->context->smarty->assign('content', $this->content);
    }

    private function isEditor(): bool
    {
        return Tools::getIsset('receipt') || Tools::getIsset('new');
    }

    private function renderReceiptList(): string
    {
        $status = (string) Tools::getValue('status');
        $status = in_array($status, Receipt::STATUSES, true) ? $status : '';
        $cursor = (string) Tools::getValue('cursor');
        $cursor = preg_match('/^[A-Za-z0-9_\-=]{1,200}$/', $cursor) ? $cursor : '';
        $receipts = [];
        $meta = [];
        $error = null;
        $stale = false;

        try {
            $page = Client::fromSettings()->inboundReceipts(['status' => $status, 'cursor' => $cursor, 'limit' => self::PAGE_SIZE]);

            foreach ($page['data'] as $raw) {
                $receipt = Receipt::normalize($raw);
                Receipts::remember($receipt);
                $receipts[] = $this->listRow($receipt);
            }

            $meta = $page['meta'];
        } catch (ApiException $exception) {
            if (Receipts::isUnsupported($exception)) {
                return $this->renderMessage(Receipts::unsupportedMessage() . ' ' . T::__('Receipts will appear here once your SkladX warehouse turns on inbound receipts in the API.'), 'info');
            }

            $error = Receipts::errorMessage($exception);

            foreach ((new DbReceiptStore())->all() as $summary) {
                $receipts[] = $this->listRow($summary);
                $stale = true;
            }
        }

        $next = (string) ($meta['next_cursor'] ?? '');
        $base = self::$currentIndex . '&token=' . $this->token;

        $this->context->smarty->assign([
            'skladx_receipts' => $receipts,
            'skladx_error' => $error,
            'skladx_stale' => $stale,
            'skladx_status' => $status,
            'skladx_status_labels' => Receipts::statusLabels(),
            'skladx_list_url' => $base,
            'skladx_new_url' => $base . '&new=1',
            'skladx_first_url' => $cursor !== '' ? $base . ($status !== '' ? '&status=' . urlencode($status) : '') : '',
            'skladx_next_url' => !empty($meta['has_more']) && $next !== '' ? $base . ($status !== '' ? '&status=' . urlencode($status) : '') . '&cursor=' . urlencode($next) : '',
            'skladx_mark' => _MODULE_DIR_ . 'skladx/views/img/sx-mark.svg',
        ]);

        return $this->createTemplate('receipts_list.tpl')->fetch();
    }

    /**
     * One list row from a normalised receipt or a cached summary.
     *
     * @param array<string, mixed> $receipt
     *
     * @return array<string, mixed>
     */
    private function listRow(array $receipt): array
    {
        $id = (string) ($receipt['id'] ?? '');
        $status = (string) ($receipt['status'] ?? '');
        $totals = (array) ($receipt['totals'] ?? []);
        $expected = (int) ($totals['expected'] ?? ($receipt['expected'] ?? 0));
        $received = $totals['received'] ?? ($receipt['received'] ?? null);
        $tracking = (string) ($receipt['tracking_number'] ?? '');
        $date = (string) ($receipt['expected_at'] ?? '');

        return [
            'id' => $id,
            'number' => (string) ($receipt['number'] ?? '') !== '' ? (string) $receipt['number'] : $id,
            'name' => (string) ($receipt['name'] ?? ''),
            'status' => $status,
            'status_text' => Receipts::statusLabel($status, (string) ($receipt['status_label'] ?? '')),
            'tone' => Receipt::tone($status),
            'expected_at' => $date !== '' && strtotime($date) ? Tools::displayDate(date('Y-m-d', (int) strtotime($date))) : '—',
            'carrier' => Receipts::carrierLabel((string) ($receipt['carrier'] ?? '')),
            'tracking' => $tracking,
            'tracking_url' => $tracking !== '' ? Receipts::trackingUrl($receipt) : '',
            'expected' => $expected,
            'received' => $received === null ? null : (int) $received,
            'difference' => $received === null ? 0 : (int) $received - $expected,
            'editable' => Receipt::isEditable($status),
            'has_label' => $status !== Receipt::DRAFT && $status !== Receipt::CANCELLED && $status !== '',
            'edit_url' => self::$currentIndex . '&token=' . $this->token . '&receipt=' . urlencode($id),
            'label_url' => $this->labelUrl($id),
        ];
    }

    private function renderEditor(): string
    {
        $id = (string) Tools::getValue('receipt');
        $initial = null;
        $client = Client::fromSettings();
        $back = self::$currentIndex . '&token=' . $this->token;

        try {
            if ($id !== '') {
                if (!Receipt::isId($id)) {
                    throw new ApiException(T::__('Receipt not found.'), 404, 'not_found');
                }

                $receipt = Receipt::normalize($client->inboundReceipt($id));
                Receipts::remember($receipt);
                $initial = $this->receiptForBrowser($receipt);
            } else {
                // Feature detection before offering the form.
                $client->inboundReceipts(['limit' => 1]);
            }
        } catch (ApiException $exception) {
            if ($id === '' && Receipts::isUnsupported($exception)) {
                return $this->renderMessage(Receipts::unsupportedMessage(), 'info', $back, T::__('← All receipts'));
            }

            return $this->renderMessage($exception->status === 404 ? T::__('Receipt not found in SkladX.') : Receipts::errorMessage($exception), 'danger', $back, T::__('← All receipts'));
        }

        $this->context->smarty->assign([
            'skladx_back_url' => $back,
            'skladx_config_json' => json_encode($this->editorConfig($initial), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ]);

        return $this->createTemplate('receipts_editor.tpl')->fetch();
    }

    private function renderMessage(string $message, string $type, string $link = '', string $linkText = ''): string
    {
        $this->context->smarty->assign([
            'skladx_message' => $message,
            'skladx_message_type' => $type,
            'skladx_message_link' => $link,
            'skladx_message_link_text' => $linkText,
        ]);

        return $this->createTemplate('receipts_message.tpl')->fetch();
    }

    /* ------------------------------------------------------------------
     * AJAX (POST, admin token in the URL)
     * ---------------------------------------------------------------- */

    public function ajaxProcessProducts(): void
    {
        $this->guard();
        $this->success(['products' => ProductLookup::search($this->text('q'))]);
    }

    public function ajaxProcessResolve(): void
    {
        $this->guard();
        $code = $this->text('code');
        $product = ProductLookup::resolve($code);

        if ($product === null) {
            $this->error(ValidationException::message(ValidationException::error('code', ValidationException::UNKNOWN_PRODUCT, null, $code)), 404);
        }

        $this->success(['product' => $product]);
    }

    public function ajaxProcessParse(): void
    {
        $this->guard();
        $parsed = BulkParser::parse(mb_substr((string) Tools::getValue('text'), 0, 200000));
        $errors = $parsed['errors'];
        $rows = [];
        $cache = [];

        foreach ($parsed['rows'] as $row) {
            if (!array_key_exists($row['code'], $cache)) {
                $cache[$row['code']] = ProductLookup::resolve($row['code']);
            }

            if ($cache[$row['code']] === null) {
                $errors[] = ValidationException::error('code', ValidationException::UNKNOWN_PRODUCT, $row['line'], $row['code']);
                continue;
            }

            $rows[] = [
                'line' => $row['line'],
                'product' => $cache[$row['code']],
                'quantity' => $row['quantity'],
                'batch' => $row['batch'],
                'expires_at' => $row['expires_at'],
            ];
        }

        usort($errors, static fn (array $a, array $b): int => (int) $a['line'] <=> (int) $b['line']);

        $this->success(['rows' => $rows, 'errors' => (new ValidationException($errors))->localized()]);
    }

    public function ajaxProcessGet(): void
    {
        $this->guard();

        try {
            $receipt = Receipt::normalize(Client::fromSettings()->inboundReceipt($this->receiptId()));
        } catch (ApiException $exception) {
            $this->apiError($exception);
        }

        Receipts::remember($receipt);
        $this->success($this->receiptForBrowser($receipt));
    }

    /**
     * Creates (POST) or updates (PUT) a draft.
     */
    public function ajaxProcessSave(): void
    {
        $this->guard();
        $id = $this->receiptId(false);

        try {
            $payload = ReceiptPayload::build($this->receiptInput(), Settings::CARRIERS);
        } catch (ValidationException $exception) {
            $this->error(T::__('Please fix the highlighted fields.'), 422, $exception->localized());
        }

        Hook::exec('actionSkladxInboundPayload', ['payload' => &$payload, 'id' => $id]);
        $client = Client::fromSettings();

        try {
            if ($id === '') {
                $token = (string) preg_replace('/[^a-z0-9-]/', '', strtolower($this->text('token')));
                $token = strlen($token) >= 8 ? $token : bin2hex(random_bytes(16));
                $receipt = Receipt::normalize($client->createInboundReceipt($payload, sprintf('ps:%s:inbound:%s', Settings::siteHost(), $token)));
            } else {
                $receipt = Receipt::normalize($client->updateInboundReceipt($id, $payload));
            }
        } catch (ApiException $exception) {
            $this->apiError($exception);
        }

        Receipts::remember($receipt);
        $this->success($this->receiptForBrowser($receipt) + ['created' => $id === '']);
    }

    /**
     * "Send to warehouse": draft → announced.
     */
    public function ajaxProcessAnnounce(): void
    {
        $this->guard();
        $client = Client::fromSettings();
        $id = $this->receiptId();

        try {
            ReceiptPayload::assertAnnounceable(Receipt::normalize($client->inboundReceipt($id)));
            $receipt = Receipt::normalize($client->announceInboundReceipt($id));
        } catch (ValidationException $exception) {
            $this->error(T::__('The receipt cannot be sent yet.'), 422, $exception->localized());
        } catch (ApiException $exception) {
            $this->apiError($exception);
        }

        Receipts::remember($receipt);
        $this->success($this->receiptForBrowser($receipt));
    }

    public function ajaxProcessCancel(): void
    {
        $this->guard();
        $employee = $this->context->employee;

        try {
            $receipt = Receipt::normalize(Client::fromSettings()->cancelInboundReceipt($this->receiptId(), T::f('Cancelled in PrestaShop by %s', trim($employee->firstname . ' ' . $employee->lastname))));
        } catch (ApiException $exception) {
            $this->apiError($exception);
        }

        Receipts::remember($receipt);
        $this->success($this->receiptForBrowser($receipt));
    }

    /**
     * Uploads a delivery note or photo to SkladX (nothing is kept in PrestaShop).
     */
    public function ajaxProcessAttach(): void
    {
        $this->guard();
        $id = $this->receiptId();
        $file = isset($_FILES['file']) && is_array($_FILES['file']) ? $_FILES['file'] : null;

        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            $this->error(T::__('The file was not uploaded.'), 400);
        }

        if ((int) $file['size'] > self::MAX_UPLOAD) {
            $this->error(T::__('The file is larger than 10 MB.'), 400);
        }

        $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename((string) $file['name'])) ?: 'file';
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = function_exists('finfo_open') ? (string) (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']) : '';

        if (!isset(self::UPLOAD_MIMES[$extension]) || self::UPLOAD_MIMES[$extension] !== $mime) {
            $this->error(T::__('Only PDF, JPG, PNG and WebP files can be attached.'), 400);
        }

        $client = Client::fromSettings();

        try {
            $client->uploadInboundAttachment($id, (string) $file['tmp_name'], $name, $mime);
            $receipt = Receipt::normalize($client->inboundReceipt($id));
        } catch (ApiException $exception) {
            $this->apiError($exception);
        }

        $this->success($this->receiptForBrowser($receipt) + ['uploaded' => $name]);
    }

    /**
     * Sends products to SkladX right away (before they are put on a receipt).
     */
    public function ajaxProcessSyncProducts(): void
    {
        $this->guard();
        $ids = array_slice(array_values(array_filter(array_map('intval', (array) Tools::getValue('product_ids', [])))), 0, 50);
        $products = [];

        foreach (array_unique($ids) as $idProduct) {
            try {
                ProductSync::sync($idProduct, false, true);
            } catch (RetryLater $exception) {
                $this->error(T::f('SkladX is not reachable right now: %s', $exception->getMessage()), 502);
            }

            $product = new Product($idProduct);

            foreach (ProductSync::units($product) as $idProductAttribute) {
                $info = ProductLookup::info($idProduct, $idProductAttribute);

                if ($info !== null) {
                    $products[$info['sku']] = $info;
                }
            }
        }

        $this->success(['products' => (object) $products]);
    }

    /**
     * Streams the receipt label (PDF inline, ZPL as a download).
     */
    public function ajaxProcessLabel(): void
    {
        if (!$this->access('view')) {
            exit(T::__('You are not allowed to do this.'));
        }

        $id = (string) Tools::getValue('receipt');
        $format = Tools::getValue('format') === 'zpl' ? 'zpl' : 'pdf';

        if (!Receipt::isId($id)) {
            exit(T::__('Receipt not found.'));
        }

        try {
            $label = Client::fromSettings()->inboundReceiptLabel($id, $format);
        } catch (ApiException $exception) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            exit(Receipts::errorMessage($exception));
        }

        $isPdf = str_starts_with($label['body'], '%PDF');
        $file = 'prijemka-' . preg_replace('/[^A-Za-z0-9_-]/', '', $id) . ($isPdf ? '.pdf' : '.zpl');

        header('Content-Type: ' . ($isPdf ? 'application/pdf' : 'application/octet-stream'));
        header('Content-Disposition: ' . ($isPdf ? 'inline' : 'attachment') . '; filename="' . $file . '"');
        header('Content-Length: ' . strlen($label['body']));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo $label['body'];
        exit;
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------------- */

    private function labelUrl(string $id, string $format = 'pdf'): string
    {
        return self::$currentIndex . '&token=' . $this->token . '&ajax=1&action=label&receipt=' . urlencode($id) . '&format=' . $format;
    }

    /**
     * Receipt with display fields and the shop products of its lines.
     *
     * @param array<string, mixed> $receipt normalised receipt
     *
     * @return array{receipt: array<string, mixed>, products: object}
     */
    private function receiptForBrowser(array $receipt): array
    {
        $id = (string) $receipt['id'];
        $receipt['status_text'] = Receipts::statusLabel((string) $receipt['status'], (string) $receipt['status_label']);
        $receipt['tone'] = Receipt::tone((string) $receipt['status']);
        $receipt['tracking_link'] = (string) $receipt['tracking_number'] !== '' ? Receipts::trackingUrl($receipt) : '';
        $receipt['carrier_text'] = Receipts::carrierLabel((string) $receipt['carrier']);
        $receipt['label_url'] = $id !== '' ? $this->labelUrl($id) : '';
        $receipt['label_zpl_url'] = $id !== '' ? $this->labelUrl($id, 'zpl') : '';
        $receipt['edit_url'] = self::$currentIndex . '&token=' . $this->token . '&receipt=' . urlencode($id);

        return [
            'receipt' => $receipt,
            'products' => (object) ProductLookup::bySkus(array_column((array) $receipt['items'], 'sku')),
        ];
    }

    /**
     * Browser configuration (no secrets).
     *
     * @param array<string, mixed>|null $initial
     *
     * @return array<string, mixed>
     */
    private function editorConfig(?array $initial): array
    {
        $carriers = [];

        foreach (Receipts::carrierOptions() as $value => $label) {
            $carriers[] = ['value' => $value, 'label' => $label];
        }

        $base = self::$currentIndex . '&token=' . $this->token;

        return [
            'ajaxUrl' => $base . '&ajax=1',
            'listUrl' => $base,
            'newUrl' => $base . '&new=1',
            'locale' => str_replace('_', '-', (string) $this->context->language->locale),
            'carriers' => $carriers,
            'personal' => ReceiptPayload::CARRIER_PERSONAL,
            'other' => ReceiptPayload::CARRIER_OTHER,
            'maxUpload' => self::MAX_UPLOAD,
            'initial' => $initial,
            'i18n' => SkladX\PrestaShop\Inbound\EditorTexts::all(),
        ];
    }

    /**
     * Form input decoded from the `receipt` JSON field.
     *
     * @return array<string, mixed>
     */
    private function receiptInput(): array
    {
        $raw = json_decode((string) Tools::getValue('receipt'), true);
        $raw = is_array($raw) ? $raw : [];
        $input = [];

        foreach (['name', 'reference', 'expected_at', 'carrier', 'carrier_other', 'tracking_number', 'warehouse_code'] as $key) {
            $input[$key] = is_scalar($raw[$key] ?? null) ? strip_tags((string) $raw[$key]) : '';
        }

        $input['note'] = is_scalar($raw['note'] ?? null) ? strip_tags((string) $raw['note']) : '';
        $input['is_blind'] = !empty($raw['is_blind']);
        $input['items'] = [];

        foreach (is_array($raw['items'] ?? null) ? array_values($raw['items']) : [] as $item) {
            $item = is_array($item) ? $item : [];
            $input['items'][] = [
                'sku' => is_scalar($item['sku'] ?? null) ? strip_tags((string) $item['sku']) : '',
                'quantity' => is_scalar($item['quantity'] ?? null) ? (string) $item['quantity'] : '',
                'batch' => is_scalar($item['batch'] ?? null) ? strip_tags((string) $item['batch']) : '',
                'expires_at' => is_scalar($item['expires_at'] ?? null) ? strip_tags((string) $item['expires_at']) : '',
            ];
        }

        return $input;
    }

    private function receiptId(bool $required = true): string
    {
        $id = $this->text('id');

        if ($id === '' && !$required) {
            return '';
        }

        if (!Receipt::isId($id)) {
            $this->error(T::__('Receipt not found.'), 400);
        }

        return $id;
    }

    private function text(string $key): string
    {
        $value = Tools::getValue($key);

        return is_scalar($value) ? trim(strip_tags((string) $value)) : '';
    }

    private function apiError(ApiException $exception): void
    {
        $fields = [];

        foreach ($exception->fieldErrors() as $field => $messages) {
            $fields[] = [
                'field' => (string) $field,
                'line' => preg_match('/^items\.(\d+)\./', (string) $field, $m) ? (int) $m[1] + 1 : null,
                'message' => implode(' ', $messages),
            ];
        }

        $status = $exception->status >= 400 && $exception->status < 600 ? $exception->status : 502;

        $this->error(Receipts::errorMessage($exception), $status, $fields, $exception->errorCode);
    }

    private function guard(): void
    {
        if (!$this->access('edit')) {
            $this->error(T::__('You are not allowed to do this.'), 403);
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->error('POST required.', 405);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function success(array $data): void
    {
        $this->respond(200, ['success' => true, 'data' => $data]);
    }

    /**
     * @param list<array<string, mixed>> $errors
     */
    private function error(string $message, int $status, array $errors = [], string $code = ''): void
    {
        $this->respond($status, ['success' => false, 'data' => ['message' => $message, 'errors' => $errors, 'code' => $code]]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
