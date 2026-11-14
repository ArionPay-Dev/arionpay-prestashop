<?php
/**
 * Invoices for PrestaShop orders, and the order changes a verified ArionPay event makes.
 *
 * Only verified data reaches applyEvent(): a webhook whose signature was checked. Whether an invoice is paid is decided
 * by ArionPay alone; this class never compares crypto amounts.
 *
 * @author    ArionPay
 * @license   MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class ArionpayOrderSync
{
    /**
     * The payment page address for this order: the open invoice when it still fits, otherwise a new one.
     *
     * @return string
     * @throws \ArionPay\ApiException
     */
    public static function invoiceUrl(Arionpay $module, Order $order)
    {
        $amount = self::orderAmount($order);
        $currency = strtoupper((new Currency((int) $order->id_currency))->iso_code);
        $test = (bool) Configuration::get('ARIONPAY_TEST');
        $db = Db::getInstance();

        $latest = $db->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'arionpay_invoice` WHERE `id_order` = ' . (int) $order->id . ' ORDER BY `date_add` DESC');
        if ($latest && $latest['status'] === 'new' && $latest['amount'] === $amount && $latest['currency'] === $currency && (bool) $latest['test'] === $test
            && $latest['expires_at'] && strtotime($latest['expires_at'] . ' UTC') > time() + 300) {
            return (string) $latest['invoice_url'];
        }

        $attempt = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'arionpay_invoice` WHERE `id_order` = ' . (int) $order->id) + 1;
        $site = substr(hash('sha256', _COOKIE_KEY_ . '|' . _DB_NAME_ . '|' . _DB_PREFIX_), 0, 12);
        $link = Context::getContext()->link;
        $confirmation = $link->getPageLink('order-confirmation', true, (int) $order->id_lang, [
            'id_cart' => (int) $order->id_cart,
            'id_module' => (int) $module->id,
            'id_order' => (int) $order->id,
            'key' => $order->secure_key,
        ]);
        $shop = new Shop((int) $order->id_shop);
        $invoice = $module->client()->createInvoice([
            'amount' => $amount,
            'currency' => $currency,
            'orderId' => (string) $order->id,
            'description' => Tools::substr(sprintf('Order %s at %s', $order->reference, $shop->name), 0, 500),
            'successUrl' => $confirmation,
            'cancelUrl' => $confirmation,
            'callbackUrl' => $module->webhookUrl(),
            'metadata' => ['prestashop_order_id' => (int) $order->id, 'prestashop_reference' => $order->reference, 'platform' => 'prestashop'],
            'idempotencyKey' => 'prestashop-' . $site . '-' . (int) $order->id . '-' . $attempt,
            'test' => $test,
        ]);

        $expires = !empty($invoice['expiresAt']) ? gmdate('Y-m-d H:i:s', (int) strtotime((string) $invoice['expiresAt'])) : null;
        $db->insert('arionpay_invoice', [
            'invoice_id' => pSQL((string) $invoice['id']),
            'id_order' => (int) $order->id,
            'invoice_url' => pSQL((string) $invoice['invoiceUrl']),
            'amount' => pSQL($amount),
            'currency' => pSQL($currency),
            'test' => $test ? 1 : 0,
            'status' => 'new',
            'expires_at' => $expires ? pSQL($expires) : null,
            'date_add' => gmdate('Y-m-d H:i:s'),
            'date_upd' => gmdate('Y-m-d H:i:s'),
        ], true, false, Db::INSERT_IGNORE);
        self::note($order, ($test ? '[TEST - no real money] ' : '') . sprintf('Customer sent to ArionPay to pay invoice %s (%s %s).', $invoice['id'], $amount, $currency));
        $module->log('Invoice ' . $invoice['id'] . ' created for order ' . (int) $order->id . ' (' . $amount . ' ' . $currency . ($test ? ', test' : '') . ').');
        return (string) $invoice['invoiceUrl'];
    }

    /** Order total in the order's currency, as a fixed-decimal string. */
    public static function orderAmount(Order $order)
    {
        $currency = new Currency((int) $order->id_currency);
        $precision = isset($currency->precision) ? (int) $currency->precision : 2;
        return number_format((float) $order->total_paid, $precision, '.', '');
    }

    /** Paid by this module, or the order has reached a paid state (PrestaShop's own record). */
    public static function isPaid(Order $order)
    {
        $applied = Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'arionpay_invoice` WHERE `id_order` = ' . (int) $order->id . ' AND `order_paid` = 1');
        return (bool) $applied || (bool) $order->hasBeenPaid();
    }

    /**
     * Apply one verified event under a per-order lock.
     *
     * @return string processed, duplicate, busy, unknown_invoice, mismatch or not_found
     */
    public static function applyEvent(Arionpay $module, array $event)
    {
        $db = Db::getInstance();
        $invoice = $event['data']['invoice'];
        $invoiceId = (string) (isset($invoice['id']) ? $invoice['id'] : '');
        $row = $db->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'arionpay_invoice` WHERE `invoice_id` = \'' . pSQL($invoiceId) . '\'');
        if (!$row) {
            return 'unknown_invoice';
        }
        $orderId = (int) $row['id_order'];
        $metadata = isset($invoice['metadata']) && is_array($invoice['metadata']) ? $invoice['metadata'] : [];
        if ((int) (isset($metadata['prestashop_order_id']) ? $metadata['prestashop_order_id'] : 0) !== $orderId
            || (string) (isset($invoice['orderId']) ? $invoice['orderId'] : '') !== (string) $orderId
            || (bool) (isset($invoice['test']) ? $invoice['test'] : false) !== (bool) $row['test']) {
            return 'mismatch';
        }

        $lock = pSQL('arionpay_order_' . _DB_NAME_ . '_' . $orderId);
        if ((int) $db->getValue('SELECT GET_LOCK(\'' . $lock . '\', 10)', false) !== 1) {
            return 'busy';
        }
        try {
            if ($db->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'arionpay_event` WHERE `event_id` = \'' . pSQL((string) $event['id']) . '\'', false)) {
                return 'duplicate';
            }
            $order = new Order($orderId);
            if (!Validate::isLoadedObject($order)) {
                return 'not_found';
            }
            $db->update('arionpay_invoice', ['status' => pSQL(Tools::substr((string) (isset($invoice['status']) ? $invoice['status'] : ''), 0, 20)), 'date_upd' => gmdate('Y-m-d H:i:s')], '`invoice_id` = \'' . pSQL($invoiceId) . '\'');
            $latest = $db->getValue('SELECT `invoice_id` FROM `' . _DB_PREFIX_ . 'arionpay_invoice` WHERE `id_order` = ' . $orderId . ' ORDER BY `date_add` DESC', false);
            $isCurrent = $latest === $invoiceId;

            switch ((string) $event['type']) {
                case 'invoice.paid':
                    self::onPaid($module, $order, $invoice, $row);
                    break;
                case 'invoice.pending':
                    if ($isCurrent && !self::isPaid($order)) {
                        self::note($order, self::text('ArionPay invoice {id}: {paid} {asset} was seen on the network and is waiting for confirmations. Do not ship yet.', $invoice));
                    }
                    break;
                case 'invoice.partial':
                    if ($isCurrent && !self::isPaid($order)) {
                        self::note($order, self::text('ArionPay invoice {id}: partial payment of {paid} of {due} {asset}. The invoice stays open for the rest. Do not ship yet.', $invoice));
                    }
                    break;
                case 'invoice.expired':
                    if ($isCurrent && !self::isPaid($order)) {
                        self::note($order, self::text('ArionPay invoice {id} expired without full payment. The order is not paid. A late payment can still arrive; if ArionPay accepts it, this order is updated. The customer can also pay again from the order confirmation page.', $invoice));
                    }
                    break;
                case 'invoice.needs_review':
                    self::note($order, self::text('ArionPay invoice {id} needs your review in the ArionPay dashboard: {reason} The order status was not changed.', $invoice));
                    break;
            }

            $db->insert('arionpay_event', [
                'event_id' => pSQL(Tools::substr((string) $event['id'], 0, 64)),
                'invoice_id' => pSQL($invoiceId),
                'type' => pSQL(Tools::substr((string) $event['type'], 0, 32)),
                'date_add' => gmdate('Y-m-d H:i:s'),
            ], false, false, Db::INSERT_IGNORE);
            return 'processed';
        } finally {
            $db->getValue('SELECT RELEASE_LOCK(\'' . $lock . '\')', false);
        }
    }

    private static function onPaid(Arionpay $module, Order $order, array $invoice, array $row)
    {
        if (self::isPaid($order)) {
            self::note($order, self::text('ArionPay reports invoice {id} as paid, but this order was already paid. If it was paid another way, this may be a double payment: check your wallet and refund the customer if needed.', $invoice));
            return;
        }
        if ((int) $order->getCurrentState() === (int) Configuration::get('PS_OS_REFUND')) {
            self::note($order, self::text('ArionPay reports invoice {id} as paid, but this order is refunded. The order was not changed.', $invoice));
            return;
        }

        // The invoice must still match the order: same currency, same total to the cent (not a payment tolerance:
        // it catches an order whose total was edited after the customer was sent to pay).
        $expected = self::orderAmount($order);
        $currency = strtoupper((new Currency((int) $order->id_currency))->iso_code);
        $precision = strlen((string) strrchr($expected, '.')) ? strlen((string) strrchr($expected, '.')) - 1 : 0;
        $paid = isset($invoice['amountFiat']) && is_numeric($invoice['amountFiat']) ? number_format((float) $invoice['amountFiat'], $precision, '.', '') : '';
        $paidCurrency = strtoupper((string) (isset($invoice['currency']) ? $invoice['currency'] : ''));
        if ($paidCurrency !== $currency || $paid !== $expected || $row['amount'] !== $expected) {
            self::note($order, sprintf('ArionPay invoice %s is paid (%s %s), but that does not match this order\'s total (%s %s), so the order was NOT marked paid. Please check the order and the invoice.', $invoice['id'], $paid !== '' ? $paid : '?', $paidCurrency, $expected, $currency));
            return;
        }

        $stateId = (int) (Configuration::get('ARIONPAY_PAID_STATE') ?: Configuration::get('PS_OS_PAYMENT'));
        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState($stateId, $order, true);
        $history->addWithemail(true);

        // the transaction id on the payment PrestaShop recorded for this state change
        $txid = '';
        if (!empty($invoice['transactions']) && is_array($invoice['transactions'])) {
            $last = end($invoice['transactions']);
            $txid = is_array($last) && isset($last['txid']) && is_scalar($last['txid']) ? Tools::substr((string) $last['txid'], 0, 254) : '';
        }
        $payments = OrderPayment::getByOrderReference($order->reference);
        if ($payments) {
            $payment = end($payments);
            if ($payment instanceof OrderPayment && !$payment->transaction_id) {
                $payment->transaction_id = $txid !== '' ? $txid : (string) $invoice['id'];
                $payment->payment_method = $module->displayName;
                $payment->update();
            }
        }

        Db::getInstance()->update('arionpay_invoice', ['order_paid' => 1], '`invoice_id` = \'' . pSQL((string) $invoice['id']) . '\'');
        $parts = [self::text('ArionPay invoice {id} is paid: {paid} {asset} received on {network}. Transaction: {tx}.', $invoice)];
        if (!empty($invoice['late'])) {
            $parts[] = 'Paid late: the payment arrived after the invoice expired.';
        }
        if (!empty($invoice['manual'])) {
            $parts[] = 'You accepted this payment by hand in the ArionPay dashboard.';
        }
        if (!empty($invoice['overpaid'])) {
            $parts[] = 'The customer paid more than the invoice amount.';
        }
        if (!empty($invoice['underpaidWithinTolerance'])) {
            $parts[] = 'The customer paid slightly less, and ArionPay accepted it under your store\'s payment tolerance.';
        }
        if (!empty($invoice['needsReview'])) {
            $parts[] = 'ArionPay flagged this invoice for review: see your ArionPay dashboard.';
        }
        self::note($order, implode(' ', $parts));
    }

    /** A private message on the order (back office > order > Messages). The customer does not see it. */
    public static function note(Order $order, $text)
    {
        $customer = new Customer((int) $order->id_customer);
        $threadId = (int) CustomerThread::getIdCustomerThreadByEmailAndIdOrder($customer->email, (int) $order->id);
        if (!$threadId) {
            $thread = new CustomerThread();
            $thread->id_contact = 0;
            $thread->id_customer = (int) $order->id_customer;
            $thread->id_shop = (int) $order->id_shop;
            $thread->id_order = (int) $order->id;
            $thread->id_lang = (int) $order->id_lang;
            $thread->email = $customer->email;
            $thread->status = 'open';
            $thread->token = Tools::passwdGen(12);
            if (!$thread->add()) {
                return;
            }
            $threadId = (int) $thread->id;
        }
        $message = new CustomerMessage();
        $message->id_customer_thread = $threadId;
        $message->id_employee = 0;
        $message->message = Tools::substr(strip_tags((string) $text), 0, 1600);
        $message->private = 1;
        $message->add();
    }

    /** Fill a message with invoice values: {id} {paid} {due} {asset} {network} {reason} {tx}. */
    private static function text($template, array $invoice)
    {
        $s = function ($key) use ($invoice) {
            return isset($invoice[$key]) && is_scalar($invoice[$key]) ? Tools::substr(strip_tags((string) $invoice[$key]), 0, 300) : '';
        };
        $tx = '';
        if (!empty($invoice['transactions']) && is_array($invoice['transactions'])) {
            $last = end($invoice['transactions']);
            $tx = is_array($last) && isset($last['txid']) && is_scalar($last['txid']) ? (string) $last['txid'] : '';
        }
        $text = strtr($template, [
            '{id}' => $s('id'),
            '{paid}' => $s('paidCrypto'),
            '{due}' => $s('amountCrypto'),
            '{asset}' => $s('asset'),
            '{network}' => $s('network'),
            '{reason}' => $s('reviewReason') !== '' ? $s('reviewReason') : '-',
            '{tx}' => $tx !== '' ? $tx : '-',
        ]);
        return (!empty($invoice['test']) ? '[TEST - no real money] ' : '') . $text;
    }
}
