<section id="arionpay-payment-return" class="card card-block">
  {if $arionpay_paid}
    <p><strong>{l s='Thank you: your crypto payment for order %s has been received.' sprintf=[$arionpay_reference] mod='arionpay'}</strong></p>
  {else}
    <p>{l s='We have not received the crypto payment for order %s yet.' sprintf=[$arionpay_reference] mod='arionpay'}</p>
    <p>{l s='If you already paid, there is nothing to do: the order is updated as soon as the network confirms the payment. If you have not paid yet, you can pay now.' mod='arionpay'}</p>
    <p><a class="btn btn-primary" href="{$arionpay_pay_url|escape:'html':'UTF-8'}">{l s='Pay now with crypto' mod='arionpay'}</a></p>
  {/if}
  {if $arionpay_test}<p class="text-warning">{l s='Test mode: no real money is taken.' mod='arionpay'}</p>{/if}
</section>
