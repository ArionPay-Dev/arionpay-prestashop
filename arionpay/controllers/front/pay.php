<?php
/**
 * Sends the customer to the ArionPay payment page for an unpaid order (from checkout, or "Pay now" later).
 * The order's secure key is required, so nobody can open another customer's payment.
 *
 * @author    ArionPay
 * @license   MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class ArionpayPayModuleFrontController extends ModuleFrontController
{
    /** @var Arionpay */
    public $module;

    public function postProcess()
    {
        $order = new Order((int) Tools::getValue('id_order'));
        $key = (string) Tools::getValue('key');
        if (!Validate::isLoadedObject($order) || $order->module !== $this->module->name || $key === '' || !hash_equals((string) $order->secure_key, $key)) {
            Tools::redirect($this->context->link->getPageLink('index', true));
        }
        $confirmation = $this->context->link->getPageLink('order-confirmation', true, null, [
            'id_cart' => (int) $order->id_cart,
            'id_module' => (int) $this->module->id,
            'id_order' => (int) $order->id,
            'key' => $order->secure_key,
        ]);
        $state = (int) $order->getCurrentState();
        if (ArionpayOrderSync::isPaid($order) || in_array($state, [(int) Configuration::get('PS_OS_CANCELED'), (int) Configuration::get('PS_OS_REFUND')], true)) {
            Tools::redirect($confirmation);
        }
        try {
            Tools::redirect(ArionpayOrderSync::invoiceUrl($this->module, $order));
        } catch (\Throwable $e) {
            $this->module->log('Could not create an ArionPay invoice for order ' . (int) $order->id . ': ' . $e->getMessage());
            $this->errors[] = $this->module->l('The crypto payment could not be started right now. Please try again in a minute.', 'pay');
            $this->redirectWithNotifications($confirmation);
        }
    }
}
