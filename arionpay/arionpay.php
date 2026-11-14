<?php
/**
 * ArionPay for PrestaShop: crypto payments that go straight to the merchant's own wallet.
 *
 * Flow: checkout creates the order as "Awaiting crypto payment" and sends the customer to the ArionPay payment page.
 * Only a signed ArionPay webhook (invoice.paid) moves the order to the paid status. Whether an invoice is paid is
 * decided by ArionPay alone; this module never compares crypto amounts.
 *
 * @author    ArionPay
 * @license   MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

require_once __DIR__ . '/lib/arionpay-php/autoload.php';
require_once __DIR__ . '/classes/ArionpayOrderSync.php';

class Arionpay extends PaymentModule
{
    const CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'TRY', 'AED', 'INR', 'BRL', 'RUB', 'CNY', 'SGD', 'HKD', 'KRW', 'MXN', 'PLN', 'SEK', 'NOK', 'ZAR'];

    const CONFIG = [
        'ARIONPAY_API_KEY' => '',
        'ARIONPAY_API_SECRET' => '',
        'ARIONPAY_WEBHOOK_SECRET' => '',
        'ARIONPAY_BASE_URL' => 'https://merchant.arionpay.com',
        'ARIONPAY_TEST' => '1',
        'ARIONPAY_PAID_STATE' => '',
        'ARIONPAY_DEBUG' => '0',
    ];

    public function __construct()
    {
        $this->name = 'arionpay';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'ArionPay';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.8.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        $this->controllers = ['validation', 'pay', 'webhook'];
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        parent::__construct();
        $this->displayName = $this->l('ArionPay (crypto)');
        $this->description = $this->l('Accept Bitcoin, USDT, Litecoin, Ethereum, TRON and more. The money goes straight to your own wallet.');
        $this->confirmUninstall = $this->l('Orders already paid stay as they are. Remove ArionPay?');
    }

    public function install()
    {
        if (PHP_VERSION_ID < 70400) {
            $this->_errors[] = $this->l('ArionPay needs PHP 7.4 or newer.');
            return false;
        }
        if (!parent::install() || !$this->registerHook('paymentOptions') || !$this->registerHook('displayPaymentReturn') || !$this->registerHook('displayAdminOrderMainBottom')) {
            return false;
        }
        foreach (self::CONFIG as $key => $default) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $default);
            }
        }
        if (!Configuration::get('ARIONPAY_PAID_STATE')) {
            Configuration::updateValue('ARIONPAY_PAID_STATE', (int) Configuration::get('PS_OS_PAYMENT'));
        }
        return $this->installTables() && $this->installWaitingState();
    }

    public function uninstall()
    {
        // Tables and the order state stay: past orders use the state, and a late payment can still be matched.
        foreach (array_keys(self::CONFIG) as $key) {
            Configuration::deleteByName($key);
        }
        return parent::uninstall();
    }

    private function installTables()
    {
        $db = Db::getInstance();
        return $db->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'arionpay_invoice` (
                `invoice_id` VARCHAR(24) NOT NULL,
                `id_order` INT(10) UNSIGNED NOT NULL,
                `invoice_url` VARCHAR(255) NOT NULL,
                `amount` VARCHAR(32) NOT NULL,
                `currency` VARCHAR(3) NOT NULL,
                `test` TINYINT(1) NOT NULL DEFAULT 0,
                `status` VARCHAR(20) NOT NULL DEFAULT \'new\',
                `order_paid` TINYINT(1) NOT NULL DEFAULT 0,
                `expires_at` DATETIME NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`invoice_id`),
                KEY `id_order` (`id_order`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4')
            && $db->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'arionpay_event` (
                `event_id` VARCHAR(64) NOT NULL,
                `invoice_id` VARCHAR(24) NOT NULL,
                `type` VARCHAR(32) NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`event_id`),
                KEY `invoice_id` (`invoice_id`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    /** The "Awaiting crypto payment" order state, made once and kept (old orders use it). */
    private function installWaitingState()
    {
        $id = (int) Configuration::get('ARIONPAY_OS_WAITING');
        if ($id && Validate::isLoadedObject(new OrderState($id))) {
            return true;
        }
        $state = new OrderState();
        foreach (Language::getLanguages(false) as $lang) {
            $state->name[(int) $lang['id_lang']] = 'Awaiting crypto payment';
        }
        $state->module_name = $this->name;
        $state->color = '#4a6cf7';
        $state->send_email = false;
        $state->logable = false;
        $state->paid = false;
        $state->invoice = false;
        $state->shipped = false;
        $state->delivery = false;
        $state->hidden = false;
        $state->unremovable = true;
        if (!$state->add()) {
            return false;
        }
        @copy(__DIR__ . '/logo.png', _PS_ORDER_STATE_IMG_DIR_ . (int) $state->id . '.gif');
        return Configuration::updateValue('ARIONPAY_OS_WAITING', (int) $state->id);
    }

    // ------------------------------------------------------------------ settings page

    public function getContent()
    {
        $output = '';
        // HelperForm adds a hidden submitArionpay field to every submit, so the check button must be tested first.
        if (Tools::isSubmit('checkArionpay')) {
            $output .= $this->checkConnection();
        } elseif (Tools::isSubmit('submitArionpay')) {
            $output .= $this->saveSettings();
        }
        $this->context->smarty->assign([
            'arionpay_webhook_url' => $this->webhookUrl(),
            'arionpay_not_https' => strpos($this->webhookUrl(), 'https://') !== 0,
        ]);
        return $output . $this->display(__FILE__, 'views/templates/hook/admin_intro.tpl') . $this->renderForm();
    }

    private function saveSettings()
    {
        $errors = [];
        $apiKey = trim((string) Tools::getValue('ARIONPAY_API_KEY'));
        $secret = trim((string) Tools::getValue('ARIONPAY_API_SECRET'));
        $webhookSecret = trim((string) Tools::getValue('ARIONPAY_WEBHOOK_SECRET'));
        $base = rtrim(trim((string) Tools::getValue('ARIONPAY_BASE_URL')), '/');
        $secret = $secret !== '' ? $secret : (string) Configuration::get('ARIONPAY_API_SECRET');
        $webhookSecret = $webhookSecret !== '' ? $webhookSecret : (string) Configuration::get('ARIONPAY_WEBHOOK_SECRET');
        $base = $base !== '' ? $base : 'https://merchant.arionpay.com';
        $paidState = (int) Tools::getValue('ARIONPAY_PAID_STATE');

        if (!preg_match('/^pk_[A-Za-z0-9_-]{8,64}$/', $apiKey)) {
            $errors[] = $this->l('The API key starts with pk_.');
        }
        if (strpos($secret, 'sk_') !== 0) {
            $errors[] = $this->l('The API secret starts with sk_.');
        }
        if (strpos($webhookSecret, 'whsec_') !== 0) {
            $errors[] = $this->l('The webhook signing secret starts with whsec_.');
        }
        if (!preg_match('#^https?://[^\s/]+#i', $base)) {
            $errors[] = $this->l('Enter an ArionPay address that starts with https://.');
        }
        $state = new OrderState($paidState);
        if (!Validate::isLoadedObject($state) || !$state->paid) {
            $errors[] = $this->l('Choose a "paid" order status for paid orders (for example Payment accepted).');
        }
        if ($errors) {
            return $this->displayError(implode('<br>', array_map('htmlspecialchars', $errors)));
        }
        Configuration::updateValue('ARIONPAY_API_KEY', $apiKey);
        Configuration::updateValue('ARIONPAY_API_SECRET', $secret);
        Configuration::updateValue('ARIONPAY_WEBHOOK_SECRET', $webhookSecret);
        Configuration::updateValue('ARIONPAY_BASE_URL', $base);
        Configuration::updateValue('ARIONPAY_TEST', Tools::getValue('ARIONPAY_TEST') ? '1' : '0');
        Configuration::updateValue('ARIONPAY_PAID_STATE', $paidState);
        Configuration::updateValue('ARIONPAY_DEBUG', Tools::getValue('ARIONPAY_DEBUG') ? '1' : '0');
        return $this->displayConfirmation($this->l('Settings saved.'));
    }

    /** A signed read of an invoice that cannot exist answers 404 with good keys and 401 with wrong ones. */
    private function checkConnection()
    {
        try {
            $this->client(15, 1)->getInvoice('000000000000000000000000');
            return $this->displayConfirmation($this->l('Connected: ArionPay accepted these keys.'));
        } catch (\ArionPay\ApiException $e) {
            if ($e->getStatus() === 404) {
                return $this->displayConfirmation($this->l('Connected: ArionPay accepted these keys.'));
            }
            if ($e->getStatus() === 401 || $e->getStatus() === 403) {
                return $this->displayError($this->l('ArionPay refused these keys:') . ' ' . htmlspecialchars($e->getMessage()));
            }
            return $this->displayError($this->l('Could not check the connection:') . ' ' . htmlspecialchars($e->getMessage()));
        } catch (\InvalidArgumentException $e) {
            return $this->displayError($this->l('Save your keys first:') . ' ' . htmlspecialchars($e->getMessage()));
        }
    }

    private function renderForm()
    {
        $states = [];
        foreach (OrderState::getOrderStates((int) $this->context->language->id) as $s) {
            if ($s['paid']) {
                $states[] = ['id' => (int) $s['id_order_state'], 'name' => $s['name']];
            }
        }
        $yesNo = [['id' => 'on', 'value' => 1, 'label' => $this->l('Yes')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]];
        $secretSaved = $this->l('Saved. Leave empty to keep it.');
        $form = [
            'form' => [
                'legend' => ['title' => $this->l('ArionPay settings'), 'icon' => 'icon-cogs'],
                'input' => [
                    ['type' => 'text', 'label' => $this->l('API key'), 'name' => 'ARIONPAY_API_KEY', 'required' => true, 'placeholder' => 'pk_...',
                        'desc' => $this->l('ArionPay dashboard > your store > API key.')],
                    ['type' => 'password', 'label' => $this->l('API secret'), 'name' => 'ARIONPAY_API_SECRET', 'required' => true,
                        'desc' => Configuration::get('ARIONPAY_API_SECRET') ? $secretSaved : $this->l('ArionPay dashboard > your store > API secret (sk_...).')],
                    ['type' => 'password', 'label' => $this->l('Webhook signing secret'), 'name' => 'ARIONPAY_WEBHOOK_SECRET', 'required' => true,
                        'desc' => Configuration::get('ARIONPAY_WEBHOOK_SECRET') ? $secretSaved : $this->l('ArionPay dashboard > Webhooks > signing secret (whsec_...).')],
                    ['type' => 'switch', 'label' => $this->l('Test mode'), 'name' => 'ARIONPAY_TEST', 'is_bool' => true, 'values' => $yesNo,
                        'desc' => $this->l('Test invoices: no real money moves, and you can finish the payment with the test buttons on the ArionPay page. Turn it off before you take real payments.')],
                    ['type' => 'select', 'label' => $this->l('Status when paid'), 'name' => 'ARIONPAY_PAID_STATE', 'options' => ['query' => $states, 'id' => 'id', 'name' => 'name']],
                    ['type' => 'switch', 'label' => $this->l('Debug log'), 'name' => 'ARIONPAY_DEBUG', 'is_bool' => true, 'values' => $yesNo,
                        'desc' => $this->l('Writes what the module does to Advanced Parameters > Logs. Never writes your keys.')],
                    ['type' => 'text', 'label' => $this->l('ArionPay address'), 'name' => 'ARIONPAY_BASE_URL',
                        'desc' => $this->l('Leave it as https://merchant.arionpay.com unless ArionPay tells you otherwise.')],
                ],
                'submit' => ['title' => $this->l('Save'), 'name' => 'submitArionpay'],
                'buttons' => [['type' => 'submit', 'title' => $this->l('Check connection'), 'name' => 'checkArionpay', 'icon' => 'process-icon-refresh', 'class' => 'pull-right']],
            ],
        ];
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->show_toolbar = false;
        $helper->submit_action = 'submitArionpay';
        $helper->tpl_vars = ['fields_value' => [
            'ARIONPAY_API_KEY' => Tools::getValue('ARIONPAY_API_KEY', Configuration::get('ARIONPAY_API_KEY')),
            'ARIONPAY_API_SECRET' => '',
            'ARIONPAY_WEBHOOK_SECRET' => '',
            'ARIONPAY_TEST' => (int) Tools::getValue('ARIONPAY_TEST', Configuration::get('ARIONPAY_TEST')),
            'ARIONPAY_PAID_STATE' => (int) Tools::getValue('ARIONPAY_PAID_STATE', Configuration::get('ARIONPAY_PAID_STATE')),
            'ARIONPAY_DEBUG' => (int) Tools::getValue('ARIONPAY_DEBUG', Configuration::get('ARIONPAY_DEBUG')),
            'ARIONPAY_BASE_URL' => Tools::getValue('ARIONPAY_BASE_URL', Configuration::get('ARIONPAY_BASE_URL') ?: 'https://merchant.arionpay.com'),
        ]];
        return $helper->generateForm([$form]);
    }

    // ------------------------------------------------------------------ checkout

    public function hookPaymentOptions($params)
    {
        if (!$this->active || !$this->isConfigured()) {
            return [];
        }
        $cart = $params['cart'];
        $currency = new Currency((int) $cart->id_currency);
        if (!in_array(strtoupper($currency->iso_code), self::CURRENCIES, true) || !$this->checkCurrency($cart)) {
            return [];
        }
        $this->context->smarty->assign(['arionpay_test' => (bool) Configuration::get('ARIONPAY_TEST')]);
        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->l('Pay with crypto (Bitcoin, USDT and more)'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
            ->setAdditionalInformation($this->fetch('module:arionpay/views/templates/hook/payment_intro.tpl'));
        return [$option];
    }

    public function checkCurrency($cart)
    {
        $currency = new Currency((int) $cart->id_currency);
        $allowed = $this->getCurrency((int) $cart->id_currency);
        if (is_array($allowed)) {
            foreach ($allowed as $c) {
                if ((int) $c['id_currency'] === (int) $currency->id) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Order confirmation page: paid, or not yet with a "Pay now" button (the customer may have left the payment page). */
    public function hookDisplayPaymentReturn($params)
    {
        if (!$this->active) {
            return '';
        }
        $order = isset($params['order']) ? $params['order'] : null;
        if (!$order instanceof Order || $order->module !== $this->name) {
            return '';
        }
        $this->context->smarty->assign([
            'arionpay_paid' => ArionpayOrderSync::isPaid($order),
            'arionpay_reference' => $order->reference,
            'arionpay_pay_url' => $this->context->link->getModuleLink($this->name, 'pay', ['id_order' => (int) $order->id, 'key' => $order->secure_key], true),
            'arionpay_test' => (bool) Configuration::get('ARIONPAY_TEST'),
        ]);
        return $this->fetch('module:arionpay/views/templates/hook/payment_return.tpl');
    }

    /** Back office order page: the ArionPay invoices of this order. */
    public function hookDisplayAdminOrderMainBottom($params)
    {
        $rows = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'arionpay_invoice` WHERE `id_order` = ' . (int) $params['id_order'] . ' ORDER BY `date_add`');
        if (!$rows) {
            return '';
        }
        $this->context->smarty->assign(['arionpay_invoices' => $rows]);
        return $this->display(__FILE__, 'views/templates/hook/admin_order.tpl');
    }

    // ------------------------------------------------------------------ shared helpers

    public function isConfigured()
    {
        return Configuration::get('ARIONPAY_API_KEY') && Configuration::get('ARIONPAY_API_SECRET') && Configuration::get('ARIONPAY_WEBHOOK_SECRET');
    }

    /**
     * @param int $timeout
     * @param int $attempts
     * @return \ArionPay\Client
     */
    public function client($timeout = 20, $attempts = 3)
    {
        return new \ArionPay\Client((string) Configuration::get('ARIONPAY_API_KEY'), (string) Configuration::get('ARIONPAY_API_SECRET'), [
            'baseUrl' => (string) (Configuration::get('ARIONPAY_BASE_URL') ?: 'https://merchant.arionpay.com'),
            'timeout' => $timeout,
            'maxAttempts' => $attempts,
            'appInfo' => 'prestashop/' . _PS_VERSION_ . ' arionpay-prestashop/' . $this->version,
        ]);
    }

    public function webhookUrl()
    {
        return $this->context->link->getModuleLink($this->name, 'webhook', [], true);
    }

    public function log($message)
    {
        if (Configuration::get('ARIONPAY_DEBUG')) {
            PrestaShopLogger::addLog('ArionPay: ' . $message, 1, null, 'Module', (int) $this->id);
        }
    }
}
