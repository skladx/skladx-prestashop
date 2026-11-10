{**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 *}
<div class="skladx-config">
  {foreach $skladx_messages as $message}
    <div class="alert alert-{$message.type|escape:'html':'UTF-8'}">{$message.text|escape:'html':'UTF-8'}</div>
  {/foreach}

  <form method="post" action="{$skladx_action|escape:'html':'UTF-8'}" class="form-horizontal">

    {* Connection *}
    <div class="panel">
      <div class="panel-heading skladx-config-head">
        <img src="{$skladx_logo|escape:'html':'UTF-8'}" alt=""> {l s='SkladX connection' mod='skladx'} <small class="text-muted">v{$skladx_version|escape:'html':'UTF-8'}</small>
      </div>

      <div class="form-group">
        <label class="control-label col-lg-3" for="SKLADX_API_URL">{l s='API base URL' mod='skladx'}</label>
        <div class="col-lg-6">
          <input type="url" name="SKLADX_API_URL" id="SKLADX_API_URL" value="{$skladx_api_url|escape:'html':'UTF-8'}" placeholder="https://skladx.sk/api/v1" class="form-control">
          <p class="help-block">{l s='Your SkladX URL including /api/v1.' mod='skladx'}</p>
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3" for="SKLADX_API_KEY">{l s='API key' mod='skladx'}</label>
        <div class="col-lg-6">
          <input type="password" name="SKLADX_API_KEY" id="SKLADX_API_KEY" value="" autocomplete="new-password" placeholder="{if $skladx_api_key_masked}{$skladx_api_key_masked|escape:'html':'UTF-8'}{else}skx_live_…{/if}" class="form-control">
          <p class="help-block">{l s='From the SkladX client portal (Integrations → API keys). skx_test_… = sandbox, skx_live_… = production. Leave empty to keep the saved key. The key is never shown again and never logged.' mod='skladx'}</p>
          <p class="help-block">{l s='Required scopes:' mod='skladx'} <code>orders:read orders:write products:write events:read webhooks:write pickup_points:read stock:read inbound:read inbound:write</code></p>
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3" for="SKLADX_WEBHOOK_SECRET">{l s='Webhook signing secret' mod='skladx'}</label>
        <div class="col-lg-6">
          <input type="password" name="SKLADX_WEBHOOK_SECRET" id="SKLADX_WEBHOOK_SECRET" value="" autocomplete="new-password" placeholder="{if $skladx_webhook_secret_masked}{$skladx_webhook_secret_masked|escape:'html':'UTF-8'}{else}whsec_…{/if}" class="form-control">
          <p class="help-block">{l s='Filled in automatically by "Register webhook in SkladX". Leave empty to keep the saved secret.' mod='skladx'}{if $skladx_webhook_id} {l s='Registered webhook:' mod='skladx'} <code>{$skladx_webhook_id|escape:'html':'UTF-8'}</code>{/if}</p>
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3">{l s='Webhook endpoint' mod='skladx'}</label>
        <div class="col-lg-9">
          <p class="form-control-static skladx-url">{$skladx_webhook_url|escape:'html':'UTF-8'}</p>
          {if $skladx_webhook_problem}<p class="text-warning">{$skladx_webhook_problem|escape:'html':'UTF-8'}</p>{/if}
        </div>
      </div>
      {if $skladx_info}
        <div class="form-group">
          <label class="control-label col-lg-3">{l s='Connected as' mod='skladx'}</label>
          <div class="col-lg-9">
            <p class="form-control-static">
              {$skladx_info_client|escape:'html':'UTF-8'} · {$skladx_info_environment|escape:'html':'UTF-8'}{if $skladx_info_sandbox} <span class="label label-warning">{l s='Sandbox' mod='skladx'}</span>{/if}
              {if $skladx_missing_scopes}<br><span class="text-warning">{l s='Missing scopes:' mod='skladx'} {', '|implode:$skladx_missing_scopes|escape:'html':'UTF-8'}</span>{/if}
            </p>
          </div>
        </div>
      {/if}
      <div class="form-group">
        <div class="col-lg-9 col-lg-offset-3 skladx-buttons">
          <button type="submit" name="skladxTestConnection" class="btn btn-default">{l s='Test connection' mod='skladx'}</button>
          <button type="submit" name="skladxRegisterWebhook" class="btn btn-default">{l s='Register webhook in SkladX' mod='skladx'}</button>
          <p class="help-block">{l s='Save the settings before using these buttons.' mod='skladx'}</p>
        </div>
      </div>
    </div>

    {* Orders *}
    <div class="panel">
      <div class="panel-heading"><i class="icon-shopping-cart"></i> {l s='Orders' mod='skladx'}</div>
      <div class="form-group">
        <label class="control-label col-lg-3">{l s='Send orders in states' mod='skladx'}</label>
        <div class="col-lg-9">
          <div class="skladx-checklist">
            {foreach $skladx_order_states as $state}
              <label><input type="checkbox" name="SKLADX_EXPORT_STATES[]" value="{$state.id_order_state|intval}"{if in_array($state.id_order_state, $skladx_export_states)} checked{/if}> {$state.name|escape:'html':'UTF-8'}</label>
            {/foreach}
          </div>
          <p class="help-block">{l s='An order is sent to SkladX once, when it first reaches one of these states (retries with backoff when SkladX is not reachable).' mod='skladx'}</p>
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3">{l s='Cash on delivery payment modules' mod='skladx'}</label>
        <div class="col-lg-9">
          {foreach $skladx_payment_modules as $payment}
            <label class="checkbox-inline"><input type="checkbox" name="SKLADX_COD_MODULES[]" value="{$payment.name|escape:'html':'UTF-8'}"{if in_array($payment.name, $skladx_cod_modules)} checked{/if}> {$payment.display_name|escape:'html':'UTF-8'} <small class="text-muted">({$payment.name|escape:'html':'UTF-8'})</small></label>
          {/foreach}
          <p class="help-block">{l s='Orders paid with these modules are sent with the order total as cash on delivery amount.' mod='skladx'}</p>
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3">{l s='SkladX status → order state' mod='skladx'}</label>
        <div class="col-lg-6">
          <table class="table table-condensed">
            <tbody>
              {foreach $skladx_statuses as $status}
                <tr>
                  <td><code>{$status|escape:'html':'UTF-8'}</code></td>
                  <td>
                    <select name="SKLADX_STATUS_MAP[{$status|escape:'html':'UTF-8'}]" class="form-control input-sm">
                      <option value="0">{l s='— keep state (message only) —' mod='skladx'}</option>
                      {foreach $skladx_order_states as $state}
                        <option value="{$state.id_order_state|intval}"{if isset($skladx_status_map[$status]) && $skladx_status_map[$status] == $state.id_order_state} selected{/if}>{$state.name|escape:'html':'UTF-8'}</option>
                      {/foreach}
                    </select>
                  </td>
                </tr>
              {/foreach}
            </tbody>
          </table>
          <p class="help-block">{l s='Every SkladX status change is added to the order messages; mapped statuses also change the order state (customer e-mails of that state are sent).' mod='skladx'}</p>
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3">{l s='Tracking e-mail' mod='skladx'}</label>
        <div class="col-lg-9">
          <label class="checkbox-inline"><input type="checkbox" name="SKLADX_TRACKING_EMAIL" value="1"{if $skladx_tracking_email} checked{/if}> {l s='Send the "In transit" e-mail with the tracking link when SkladX reports a tracking number' mod='skladx'}</label>
          <p class="help-block">{l s='The tracking number is stored on the order carrier (visible to the customer in the order history). The link uses the carrier tracking URL; it is filled in automatically for mapped carriers without one.' mod='skladx'}</p>
        </div>
      </div>
    </div>

    {* Carriers *}
    <div class="panel">
      <div class="panel-heading"><i class="icon-truck"></i> {l s='Carriers and pickup points' mod='skladx'}</div>
      <table class="table">
        <thead>
          <tr>
            <th>{l s='Carrier' mod='skladx'}</th>
            <th>{l s='SkladX carrier' mod='skladx'}</th>
            <th>{l s='Pickup point' mod='skladx'}</th>
          </tr>
        </thead>
        <tbody>
          {foreach $skladx_carriers as $carrier}
            <tr>
              <td>{$carrier.name|escape:'html':'UTF-8'}{if !$carrier.active} <span class="label label-default">{l s='inactive' mod='skladx'}</span>{/if} <small class="text-muted">#{$carrier.id_reference|intval}</small></td>
              <td>
                <select name="SKLADX_CARRIER_MAP[{$carrier.id_reference|intval}]" class="form-control input-sm" style="max-width: 260px">
                  <option value="">{l s='— not mapped —' mod='skladx'}</option>
                  {foreach $skladx_carrier_codes as $code}
                    <option value="{$code|escape:'html':'UTF-8'}"{if $carrier.code == $code} selected{/if}>{$skladx_carrier_names[$code]|escape:'html':'UTF-8'} ({$code|escape:'html':'UTF-8'})</option>
                  {/foreach}
                </select>
              </td>
              <td>
                {if $carrier.foreign_module}
                  <span class="text-muted">{l s='Managed by module %s' sprintf=[$carrier.foreign_module] mod='skladx'}</span>
                {else}
                  <input type="checkbox" name="SKLADX_PICKUP_CARRIERS[]" value="{$carrier.id_reference|intval}"{if $carrier.pickup} checked{/if}>
                {/if}
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
      <p class="help-block">{l s='Ticking "Pickup point" attaches the carrier to this module so the checkout shows the pickup point picker under it and requires a choice (prices still come from the price ranges of the carrier). The point is validated by SkladX (exists, active, accepts cash on delivery).' mod='skladx'}</p>
      <div class="form-group">
        <label class="control-label col-lg-3">{l s='Pickup point picker' mod='skladx'}</label>
        <div class="col-lg-9">
          <label class="checkbox-inline"><input type="checkbox" name="SKLADX_PICKUP_PICKER" value="1"{if $skladx_pickup_picker} checked{/if}> {l s='Show the picker at checkout (points from SkladX, search, near me, opening hours, map)' mod='skladx'}</label>
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3" for="SKLADX_PACKETA_WIDGET_KEY">{l s='Packeta widget API key' mod='skladx'}</label>
        <div class="col-lg-6">
          <input type="text" name="SKLADX_PACKETA_WIDGET_KEY" id="SKLADX_PACKETA_WIDGET_KEY" value="{$skladx_packeta_key|escape:'html':'UTF-8'}" class="form-control">
          <p class="help-block">{l s='Optional. When set, Packeta carriers open the official Packeta widget instead of the built-in picker; the chosen point is still verified with SkladX. The key is public (used in the browser).' mod='skladx'}</p>
        </div>
      </div>
    </div>

    {* Products & stock *}
    <div class="panel">
      <div class="panel-heading"><i class="icon-archive"></i> {l s='Products and stock' mod='skladx'}</div>
      <div class="form-group">
        <label class="control-label col-lg-3">{l s='Product sync' mod='skladx'}</label>
        <div class="col-lg-9">
          <label class="checkbox-inline"><input type="checkbox" name="SKLADX_PRODUCT_SYNC" value="1"{if $skladx_product_sync} checked{/if}> {l s='Send products to SkladX when they are saved (reference = SKU, name, EAN-13, weight, dimensions, price, storage class)' mod='skladx'}</label>
          <p class="help-block">{l s='Products without a reference are skipped; combinations are sent one by one. The storage class is set in the SkladX section of the product page (Modules tab).' mod='skladx'}</p>
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3">{l s='Stock sync' mod='skladx'}</label>
        <div class="col-lg-9">
          <label class="checkbox-inline"><input type="checkbox" name="SKLADX_STOCK_SYNC" value="1"{if $skladx_stock_sync} checked{/if}> {l s='Take stock levels from SkladX (SkladX is the source of truth)' mod='skladx'}</label>
          <p class="help-block">{l s='Available quantity = SkladX on hand − reserved, minus units of shop orders that are not in SkladX yet. Turning it on takes over all levels once.' mod='skladx'}</p>
        </div>
      </div>
      <div class="form-group">
        <div class="col-lg-9 col-lg-offset-3 skladx-buttons">
          <button type="submit" name="skladxSyncProducts" class="btn btn-default">{l s='Sync all products' mod='skladx'}</button>
          <button type="submit" name="skladxPullStock" class="btn btn-default">{l s='Take over stock from SkladX now' mod='skladx'}</button>
          {if $skladx_receipts_url}<a href="{$skladx_receipts_url|escape:'html':'UTF-8'}" class="btn btn-link">{l s='SkladX receipts' mod='skladx'} →</a>{/if}
        </div>
      </div>
    </div>

    {* Queue *}
    <div class="panel">
      <div class="panel-heading"><i class="icon-cogs"></i> {l s='Queue, cron and events' mod='skladx'}</div>
      <div class="form-group">
        <label class="control-label col-lg-3">{l s='Cron URL' mod='skladx'}</label>
        <div class="col-lg-9">
          <p class="form-control-static skladx-url">{$skladx_cron_url|escape:'html':'UTF-8'}</p>
          <p class="help-block">{l s='Call it every minute from the server cron, e.g.' mod='skladx'} <code>* * * * * curl -fsS "{$skladx_cron_url|escape:'html':'UTF-8'}" &gt;/dev/null</code>. {l s='Without cron, jobs run right after the request that created them and on back office page loads. Keep the URL secret.' mod='skladx'}</p>
          <p class="help-block">{l s='Last cron run:' mod='skladx'} {if $skladx_last_cron}{$skladx_last_cron|date_format:'%Y-%m-%d %H:%M:%S'}{else}{l s='never' mod='skladx'}{/if} · {l s='Last queue run:' mod='skladx'} {if $skladx_last_run}{$skladx_last_run|date_format:'%Y-%m-%d %H:%M:%S'}{else}{l s='never' mod='skladx'}{/if}</p>
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3">{l s='Queue' mod='skladx'}</label>
        <div class="col-lg-9">
          <p class="form-control-static skladx-stats">
            <span>{l s='Waiting' mod='skladx'}: <strong>{$skladx_queue_counts.pending|intval}</strong></span>
            <span>{l s='Running' mod='skladx'}: <strong>{$skladx_queue_counts.running|intval}</strong></span>
            <span>{l s='Done' mod='skladx'}: <strong>{$skladx_queue_counts.done|intval}</strong></span>
            <span>{l s='Failed' mod='skladx'}: <strong>{$skladx_queue_counts.failed|intval}</strong></span>
          </p>
          {if $skladx_queue_failures}
            <table class="table table-condensed">
              <thead><tr><th>{l s='Job' mod='skladx'}</th><th>{l s='Reference' mod='skladx'}</th><th>{l s='Attempts' mod='skladx'}</th><th>{l s='Error' mod='skladx'}</th><th>{l s='Date' mod='skladx'}</th></tr></thead>
              <tbody>
                {foreach $skladx_queue_failures as $job}
                  <tr><td>{$job.job|escape:'html':'UTF-8'}</td><td>{$job.ref|escape:'html':'UTF-8'}</td><td>{$job.attempts|intval}</td><td>{$job.last_error|escape:'html':'UTF-8'}</td><td>{$job.date_upd|escape:'html':'UTF-8'}</td></tr>
                {/foreach}
              </tbody>
            </table>
          {/if}
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3">{l s='Received events' mod='skladx'}</label>
        <div class="col-lg-9">
          {if $skladx_events}
            <table class="table table-condensed">
              <thead><tr><th>{l s='Event' mod='skladx'}</th><th>{l s='Source' mod='skladx'}</th><th>{l s='Status' mod='skladx'}</th><th>{l s='Received' mod='skladx'}</th></tr></thead>
              <tbody>
                {foreach $skladx_events as $event}
                  <tr><td><code>{$event.type|escape:'html':'UTF-8'}</code> <small class="text-muted">{$event.event_id|escape:'html':'UTF-8'}</small></td><td>{$event.source|escape:'html':'UTF-8'}</td><td>{$event.status|escape:'html':'UTF-8'}{if $event.error} <span class="text-danger">{$event.error|escape:'html':'UTF-8'}</span>{/if}</td><td>{$event.received_at|escape:'html':'UTF-8'}</td></tr>
                {/foreach}
              </tbody>
            </table>
          {else}
            <p class="form-control-static text-muted">{l s='No events received yet.' mod='skladx'}</p>
          {/if}
          <p class="help-block">{l s='Webhooks are verified (HMAC-SHA256, 5 minute tolerance) and deduplicated by event id. Every 10 minutes the module also fetches missed events from SkladX.' mod='skladx'}</p>
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3">{l s='Debug log' mod='skladx'}</label>
        <div class="col-lg-9">
          <label class="checkbox-inline"><input type="checkbox" name="SKLADX_DEBUG" value="1"{if $skladx_debug} checked{/if}> {l s='Log API calls (secrets are masked)' mod='skladx'}</label>
          <p class="help-block">{l s='Log file:' mod='skladx'} <code>{$skladx_log_file|escape:'html':'UTF-8'}</code>. {l s='Errors are always logged, also to Advanced parameters → Logs.' mod='skladx'}</p>
        </div>
      </div>
      <div class="form-group">
        <div class="col-lg-9 col-lg-offset-3 skladx-buttons">
          <button type="submit" name="skladxRunQueue" class="btn btn-default">{l s='Process queue now' mod='skladx'}</button>
          <button type="submit" name="skladxPollEvents" class="btn btn-default">{l s='Fetch missed events now' mod='skladx'}</button>
          {if $skladx_queue_counts.failed > 0}<button type="submit" name="skladxRetryFailed" class="btn btn-default">{l s='Retry failed jobs' mod='skladx'}</button>{/if}
        </div>
      </div>
      <div class="panel-footer">
        <button type="submit" name="submitSkladxSettings" class="btn btn-default pull-right"><i class="process-icon-save"></i> {l s='Save' mod='skladx'}</button>
      </div>
    </div>
  </form>
</div>
