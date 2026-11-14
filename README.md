# ArionPay for PrestaShop

Lets your customers pay with Bitcoin, USDT, Litecoin, Ethereum, TRON and more through [ArionPay](https://arionpay.com). The money goes straight to your own wallet: ArionPay never holds it.

File to upload: `arionpay-prestashop.zip`. Works with PrestaShop 1.7.8, 8.x and 9.x.

Tested on real PrestaShop 8.2.9 and 9.0.2 shops (classic theme, PHP 8.3, friendly URLs on): checkout, payment, late payment, payment tolerance, changed order total, "Pay now" after leaving the payment page, and webhook checks.

## What you need

- PHP 7.4 or newer with cURL (or `allow_url_fopen`)
- An ArionPay account with a store, and a wallet connected to it for real payments (test mode works without a wallet)
- Your shop on a public `https://` address, so ArionPay can tell it when a payment arrives

## Install

1. In the back office, go to **Modules > Module Manager**, press **Upload a module** and choose `arionpay-prestashop.zip`.
2. Press **Configure**.
3. Fill in the three keys (see below), keep **Test mode** on, and press **Save**.
4. Press **Check connection**. It should say "Connected: ArionPay accepted these keys."
5. Under **Payment > Preferences**, check that ArionPay is allowed for your currencies, countries and carriers (PrestaShop allows new payment modules everywhere by default).

## Where the keys come from

| Setting | Where in the ArionPay dashboard | Starts with |
| --- | --- | --- |
| API key | Open your store, **API key** | `pk_` |
| API secret | Open your store, **API secret** (asks for your authenticator code) | `sk_` |
| Webhook signing secret | **Webhooks** page, **signing secret** | `whsec_` |

You do not need to paste any address into ArionPay: each invoice tells ArionPay where to send its payment updates (`https://your-shop/module/arionpay/webhook`, shown on the settings page). You can also paste that address on the ArionPay **Webhooks** page if you want to use **Send test webhook** there.

Also check, in your ArionPay store settings, that **Old ArionPay (v1) integration compatibility** is off.

## Try it first

1. Keep **Test mode** on.
2. Buy something in your shop and choose **Pay with crypto (Bitcoin, USDT and more)**.
3. On the ArionPay page, choose a coin and use the test payment buttons. No real money moves.
4. The order should change from **Awaiting crypto payment** to **Payment accepted**. The order page shows a private message "[TEST - no real money] ArionPay invoice ... is paid" and an ArionPay box with the invoice.
5. Turn **Test mode** off before you take real payments.

## How orders change

- At checkout the order is created with the status **Awaiting crypto payment** (added by the module), and the customer goes to the ArionPay page.
- When ArionPay confirms the full payment, the order gets the paid status you chose (default **Payment accepted**). PrestaShop records the payment with the blockchain transaction id, and the customer gets the usual email.
- The order is marked paid only if the paid invoice still matches the order: same currency and the same total. If the order was edited after checkout, it is not marked paid and a private message explains why.
- A payment that is not exact goes to **Payment Radar** in your ArionPay dashboard. A small difference inside your store's payment tolerance (default 1%) is accepted automatically. For anything else you decide; if you accept it, the order is marked paid here too, with a message.
- A late payment (after the invoice expired) still marks the order paid, with a message.
- If more money arrives after an order was paid, you get a private message asking you to check your wallet. The order is not changed.
- If the customer leaves the payment page, the order confirmation page shows a **Pay now with crypto** button. It reopens the same invoice while it is still open, or makes a new one.
- Coming back from the payment page never marks an order paid.

## Good to know

- ArionPay shows only for currencies ArionPay accepts: USD, EUR, GBP, CAD, AUD, CHF, JPY, TRY, AED, INR, BRL, RUB, CNY, SGD, HKD, KRW, MXN, PLN, SEK, NOK and ZAR.
- Refunds are made from your own wallet. The module does not send refunds.
- The module sends ArionPay the order number and reference, amount, currency and your shop name. It does not send the customer's name, email or address.
- Messages about payments are private: only you see them on the order page, under **Messages**.
- **Debug log** writes to **Advanced Parameters > Logs** (lines start with "ArionPay:"). Keys are never written.
- It keeps two small tables, `arionpay_invoice` and `arionpay_event`, and the **Awaiting crypto payment** status. They stay after you uninstall, because old orders use them.

## Build from source

The release zip is made with `python3 build.py`. It copies the [ArionPay PHP library](https://github.com/ArionPay-Dev/arionpay-php) into the plugin first, from a folder named `arionpay-php` next to this one:

```bash
git clone https://github.com/ArionPay-Dev/arionpay-php ../arionpay-php
python3 build.py
```
