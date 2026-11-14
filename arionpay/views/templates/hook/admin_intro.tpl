<div class="panel">
  <h3><img src="{$module_dir|escape:'html':'UTF-8'}logo.png" alt="" width="20" height="20" style="vertical-align:middle"> ArionPay</h3>
  <p>{l s='Your customers pay with Bitcoin, USDT, Litecoin, Ethereum, TRON and more. The money goes straight to your own wallet: ArionPay never holds it.' mod='arionpay'}</p>
  <p>{l s='In your ArionPay dashboard, open your store: copy the API key (pk_...) and the API secret (sk_...). On the Webhooks page, copy the signing secret (whsec_...). Paste them below, keep Test mode on, save, then press Check connection.' mod='arionpay'}</p>
  <p><strong>{l s='Payment updates:' mod='arionpay'}</strong> <code>{$arionpay_webhook_url|escape:'html':'UTF-8'}</code><br>
  {l s='Nothing to paste: every invoice tells ArionPay to send its updates to this address. To use "Send test webhook" in the ArionPay dashboard, you can also paste it on the Webhooks page there.' mod='arionpay'}</p>
  {if $arionpay_not_https}<div class="alert alert-warning">{l s='Your shop address does not start with https://. ArionPay only sends payment updates to https addresses, so orders will not be marked paid until your shop uses https.' mod='arionpay'}</div>{/if}
  <p class="help-block">{l s='How orders change: at checkout the order gets the status "Awaiting crypto payment". Only when ArionPay confirms the full payment does it get the paid status. If the paid amount or currency does not match the order total, the order is not marked paid and a private message explains why. Coming back from the payment page never marks an order paid.' mod='arionpay'}</p>
</div>
