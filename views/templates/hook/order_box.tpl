{**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 *}
<div class="card skladx-order-box" id="skladx-order-box">
  <div class="card-header">
    <h3 class="card-header-title">
      <span class="skladx-mark" aria-hidden="true"></span>
      SkladX
      {if $skladx_exported && $skladx_row.status_label}
        <span class="badge skladx-status skladx-status--{$skladx_row.status|escape:'html':'UTF-8'}">{$skladx_row.status_label|escape:'html':'UTF-8'}</span>
      {/if}
      {if $skladx_exported && $skladx_row.is_test}
        <span class="badge badge-warning">{l s='Sandbox' mod='skladx'}</span>
      {/if}
    </h3>
  </div>
  <div class="card-body">
    {if !$skladx_configured}
      <p class="text-muted">{l s='SkladX is not configured yet.' mod='skladx'} <a href="{$skladx_settings_url|escape:'html':'UTF-8'}">{l s='Open SkladX settings' mod='skladx'}</a></p>
    {else}
      {if $skladx_exported}
        <dl class="skladx-dl">
          <dt>{l s='SkladX order' mod='skladx'}</dt>
          <dd>{$skladx_row.skladx_number|escape:'html':'UTF-8'} <small class="text-muted">{$skladx_row.skladx_id|escape:'html':'UTF-8'}</small></dd>
          <dt>{l s='Status' mod='skladx'}</dt>
          <dd>{$skladx_row.status_label|escape:'html':'UTF-8'} <small class="text-muted">({$skladx_row.status|escape:'html':'UTF-8'})</small></dd>
          {if $skladx_tracking}
            <dt>{l s='Tracking' mod='skladx'}</dt>
            <dd>
              <code>{$skladx_tracking.number|escape:'html':'UTF-8'}</code>
              {if $skladx_tracking.url}
                <a class="btn btn-outline-secondary btn-sm skladx-track" href="{$skladx_tracking.url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{l s='Track shipment' mod='skladx'}</a>
              {/if}
            </dd>
          {/if}
          {if $skladx_row.carrier}
            <dt>{l s='Carrier' mod='skladx'}</dt>
            <dd>{$skladx_row.carrier|escape:'html':'UTF-8'}</dd>
          {/if}
          {if $skladx_last_sync}
            <dt>{l s='Last sync' mod='skladx'}</dt>
            <dd>{$skladx_last_sync|escape:'html':'UTF-8'}</dd>
          {/if}
        </dl>
        {if $skladx_address_changed}
          <div class="alert alert-warning">{l s='The delivery address was changed after the order was sent to SkladX. SkladX does not take over address changes – contact the warehouse.' mod='skladx'}</div>
        {/if}
      {else}
        {if $skladx_queued || $skladx_row.export_state == 'queued'}
          <p><span class="badge badge-info">{l s='Queued' mod='skladx'}</span> {l s='The order will be sent to SkladX in a moment.' mod='skladx'}</p>
        {elseif $skladx_row.export_state == 'retrying'}
          <p><span class="badge badge-warning">{l s='Retrying' mod='skladx'}</span> {l s='SkladX was not reachable; the order will be sent again automatically.' mod='skladx'}</p>
        {elseif $skladx_row.export_state == 'failed'}
          <p><span class="badge badge-danger">{l s='Not sent' mod='skladx'}</span></p>
        {else}
          <p class="text-muted">{l s='Not sent to SkladX yet. Orders are sent automatically when they reach a state chosen in the module settings.' mod='skladx'}</p>
        {/if}
        {if $skladx_row.export_error}
          <div class="alert alert-danger skladx-error">{$skladx_row.export_error|escape:'html':'UTF-8'}</div>
        {/if}
        {if $skladx_mapping_error}
          <div class="alert alert-warning">{$skladx_mapping_error|escape:'html':'UTF-8'}</div>
        {/if}
      {/if}

      {if $skladx_point}
        <p class="skladx-point"><strong>{l s='Pickup point' mod='skladx'}:</strong> {$skladx_point.name|escape:'html':'UTF-8'} <small class="text-muted">({$skladx_point.id|escape:'html':'UTF-8'})</small><br>{$skladx_point_address|escape:'html':'UTF-8'}</p>
      {/if}

      <form method="post" action="{$skladx_action_url|escape:'html':'UTF-8'}" class="skladx-actions">
        <input type="hidden" name="id_order" value="{$skladx_id_order|intval}">
        {if !$skladx_exported}
          <button type="submit" name="skladx_action" value="send" class="btn btn-primary btn-sm">{if $skladx_row.export_state}{l s='Resend to SkladX' mod='skladx'}{else}{l s='Send to SkladX' mod='skladx'}{/if}</button>
        {else}
          <button type="submit" name="skladx_action" value="refresh" class="btn btn-outline-secondary btn-sm">{l s='Refresh from SkladX' mod='skladx'}</button>
          {if $skladx_cancellable}
            <button type="submit" name="skladx_action" value="cancel" class="btn btn-outline-danger btn-sm" data-skladx-confirm="{l s='Cancel this order in SkladX?' mod='skladx'}">{l s='Cancel in SkladX' mod='skladx'}</button>
          {/if}
        {/if}
      </form>

      {if $skladx_payload}
        <details class="skladx-payload">
          <summary>{l s='Data that will be sent' mod='skladx'}</summary>
          <pre>{$skladx_payload|escape:'html':'UTF-8'}</pre>
        </details>
      {/if}
    {/if}
  </div>
</div>
