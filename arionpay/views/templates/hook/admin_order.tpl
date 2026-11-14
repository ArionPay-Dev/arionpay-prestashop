<div class="card mt-2">
  <div class="card-header"><h3 class="card-header-title">ArionPay</h3></div>
  <div class="card-body">
    <table class="table">
      <thead><tr><th>{l s='Invoice' mod='arionpay'}</th><th>{l s='Amount' mod='arionpay'}</th><th>{l s='Status' mod='arionpay'}</th><th>{l s='Created (UTC)' mod='arionpay'}</th></tr></thead>
      <tbody>
      {foreach from=$arionpay_invoices item=inv}
        <tr>
          <td><code>{$inv.invoice_id|escape:'html':'UTF-8'}</code>{if $inv.test} <span class="badge badge-warning">TEST</span>{/if}</td>
          <td>{$inv.amount|escape:'html':'UTF-8'} {$inv.currency|escape:'html':'UTF-8'}</td>
          <td>{$inv.status|escape:'html':'UTF-8'}{if $inv.order_paid} ✓{/if}</td>
          <td>{$inv.date_add|escape:'html':'UTF-8'}</td>
        </tr>
      {/foreach}
      </tbody>
    </table>
  </div>
</div>
