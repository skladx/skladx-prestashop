{**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 *}
<div class="skladx-inbound">
  <div class="skladx-inbound-head">
    <h2 class="skladx-page-title">{l s='SkladX receipts' mod='skladx'}</h2>
    <a href="{$skladx_new_url|escape:'html':'UTF-8'}" class="btn btn-primary">{l s='New receipt' mod='skladx'}</a>
  </div>

  {if $skladx_error}
    <div class="alert alert-danger">{$skladx_error|escape:'html':'UTF-8'}</div>
    {if $skladx_stale}<div class="alert alert-info">{l s='Showing the last known state of your receipts.' mod='skladx'}</div>{/if}
  {/if}

  <p class="skladx-inbound-filters">
    <a href="{$skladx_list_url|escape:'html':'UTF-8'}" class="btn btn-sm {if $skladx_status == ''}btn-primary{else}btn-default{/if}">{l s='All statuses' mod='skladx'}</a>
    {foreach $skladx_status_labels as $value => $label}
      <a href="{$skladx_list_url|escape:'html':'UTF-8'}&amp;status={$value|escape:'url'}" class="btn btn-sm {if $skladx_status == $value}btn-primary{else}btn-default{/if}">{$label|escape:'html':'UTF-8'}</a>
    {/foreach}
  </p>

  <table class="table table-striped skladx-inbound-table">
    <thead>
      <tr>
        <th>{l s='Number' mod='skladx'}</th>
        <th>{l s='Name' mod='skladx'}</th>
        <th>{l s='Status' mod='skladx'}</th>
        <th>{l s='Expected delivery' mod='skladx'}</th>
        <th>{l s='Carrier / tracking' mod='skladx'}</th>
        <th class="num">{l s='Pieces' mod='skladx'}</th>
        <th class="num">{l s='Received' mod='skladx'}</th>
        <th><span class="sr-only">{l s='Actions' mod='skladx'}</span></th>
      </tr>
    </thead>
    <tbody>
      {if !$skladx_receipts}
        <tr><td colspan="8">{l s='No receipts yet. Create one to announce goods you are sending to the warehouse.' mod='skladx'}</td></tr>
      {/if}
      {foreach $skladx_receipts as $receipt}
        <tr>
          <td><a href="{$receipt.edit_url|escape:'html':'UTF-8'}"><strong>{$receipt.number|escape:'html':'UTF-8'}</strong></a></td>
          <td>{$receipt.name|escape:'html':'UTF-8'}</td>
          <td><span class="skladx-badge skladx-badge--{$receipt.tone|escape:'html':'UTF-8'}">{$receipt.status_text|escape:'html':'UTF-8'}</span></td>
          <td>{$receipt.expected_at|escape:'html':'UTF-8'}</td>
          <td>
            {$receipt.carrier|escape:'html':'UTF-8'}
            {if $receipt.tracking}<br>{if $receipt.tracking_url}<a href="{$receipt.tracking_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">{$receipt.tracking|escape:'html':'UTF-8'}</a>{else}<code>{$receipt.tracking|escape:'html':'UTF-8'}</code>{/if}{/if}
          </td>
          <td class="num">{$receipt.expected|intval}</td>
          <td class="num">
            {if $receipt.received !== null}
              {$receipt.received|intval}
              {if $receipt.difference != 0}<span class="skladx-diff {if $receipt.difference < 0}is-minus{else}is-plus{/if}">({if $receipt.difference > 0}+{/if}{$receipt.difference|intval})</span>{/if}
            {else}—{/if}
          </td>
          <td class="skladx-inbound-actions">
            <a href="{$receipt.edit_url|escape:'html':'UTF-8'}">{if $receipt.editable}{l s='Edit' mod='skladx'}{else}{l s='Open' mod='skladx'}{/if}</a>
            {if $receipt.has_label}<a href="{$receipt.label_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{l s='Label' mod='skladx'}</a>{/if}
          </td>
        </tr>
      {/foreach}
    </tbody>
  </table>

  {if $skladx_first_url || $skladx_next_url}
    <div class="skladx-inbound-pages">
      {if $skladx_first_url}<a class="btn btn-default" href="{$skladx_first_url|escape:'html':'UTF-8'}">{l s='« First page' mod='skladx'}</a>{/if}
      {if $skladx_next_url}<a class="btn btn-default" href="{$skladx_next_url|escape:'html':'UTF-8'}">{l s='Next page »' mod='skladx'}</a>{/if}
    </div>
  {/if}
</div>
