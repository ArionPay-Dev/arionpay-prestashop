<?php
/**
 * Checkout hand-off: turns the cart into an order "Awaiting crypto payment", then goes to the pay page.
 *
 * @author    ArionPay
 * @license   MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class ArionpayValidationModuleFrontController extends ModuleFrontController
{
    /** @var Arionpay */
    public $module;

    public function postProcess()
    {
        $cart = $this->context->cart;
        if (!$cart->id || $cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active || !$this->module->isConfigured()) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1]));
        }
        $authorized = false;
        foreach (Module::getPaymentModules() as $m) {
            if ($m['name'] === $this->module->name) {
                $authorized = true;
                break;
            }
        }
        $customer = new Customer((int) $cart->id_customer);
        if (!$authorized || !Validate::isLoadedObject($customer) || !$this->module->checkCurrency($cart)) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1]));
        }

        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $this->module->validateOrder(
            (int) $cart->id,
            (int) Configuration::get('ARIONPAY_OS_WAITING'),
            $total,
            $this->module->displayName,
            null,
            [],
            (int) $cart->id_currency,
            false,
            $customer->secure_key
        );
        $order = new Order((int) $this->module->currentOrder);
        Tools::redirect($this->context->link->getModuleLink($this->module->name, 'pay', ['id_order' => (int) $order->id, 'key' => $order->secure_key], true));
    }
}
