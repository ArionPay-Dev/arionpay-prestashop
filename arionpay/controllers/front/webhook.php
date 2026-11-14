<?php
/**
 * Webhook endpoint for ArionPay events. Never changes an order unless the signature is valid.
 * ArionPay retries anything that is not 2xx for about 3 days.
 *
 * @author    ArionPay
 * @license   MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class ArionpayWebhookModuleFrontController extends ModuleFrontController
{
    const MAX_BODY_BYTES = 262144;

    /** @var Arionpay */
    public $module;

    /** No theme, no session, no CSRF token: this is a server-to-server call. */
    public $ajax = true;
    public $ssl = true;
    public $display_header = false;
    public $display_footer = false;

    public function postProcess()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->respond(405, ['error' => 'method_not_allowed']);
        }
        $secret = (string) Configuration::get('ARIONPAY_WEBHOOK_SECRET');
        if ($secret === '') {
            $this->module->log('Webhook refused: the webhook signing secret is not set.');
            $this->respond(503, ['error' => 'not_configured']);
        }
        $raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if ($raw === false || strlen($raw) > self::MAX_BODY_BYTES) {
            $this->respond(413, ['error' => 'payload_too_large']);
        }
        $header = isset($_SERVER['HTTP_ARIONPAY_SIGNATURE']) ? (string) $_SERVER['HTTP_ARIONPAY_SIGNATURE'] : '';
        try {
            $event = \ArionPay\Webhook::verify($raw, $header, $secret);
        } catch (\ArionPay\SignatureException $e) {
            $this->module->log($header === '' && isset($_SERVER['HTTP_X_SIGNATURE'])
                ? 'Webhook refused: it has the old format. Turn off "old API (v1) compatibility" for this store in ArionPay.'
                : 'Webhook refused: ' . $e->getMessage());
            $this->respond(401, ['error' => 'invalid_signature']);
        }

        if (\ArionPay\Webhook::isTestEvent($event)) {
            $this->module->log('Verified ArionPay test webhook received (' . $event['type'] . ').');
            $this->respond(200, ['received' => true, 'test_event' => true]);
        }
        if (!in_array($event['type'], \ArionPay\Webhook::EVENT_TYPES, true)) {
            $this->respond(200, ['received' => true, 'ignored' => 'unknown_event']);
        }
        $invoiceId = isset($event['data']['invoice']['id']) ? (string) $event['data']['invoice']['id'] : '';
        if (!preg_match('/^[0-9a-f]{24}$/i', $invoiceId)) {
            $this->respond(400, ['error' => 'malformed_event']);
        }

        $result = ArionpayOrderSync::applyEvent($this->module, $event);
        $this->module->log('Webhook ' . $event['type'] . ' for invoice ' . $invoiceId . ': ' . $result . '.');
        switch ($result) {
            case 'busy':
                header('Retry-After: 30');
                $this->respond(503, ['error' => 'busy']);
                // no break: respond() exits
            case 'mismatch':
                $this->respond(409, ['error' => 'invoice_order_mismatch']);
                // no break
            case 'not_found':
                $this->respond(404, ['error' => 'order_not_found']);
                // no break
            case 'unknown_invoice':
                $this->respond(200, ['received' => true, 'ignored' => 'not_a_prestashop_invoice']);
                // no break
            case 'duplicate':
                $this->respond(200, ['received' => true, 'duplicate' => true]);
                // no break
            default:
                $this->respond(200, ['received' => true]);
        }
    }

    private function respond($status, array $body)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body);
        exit;
    }
}
