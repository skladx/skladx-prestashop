{**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 *}
<div class="skladx-product-tab" data-ajax-url="{$skladx_ajax_url|escape:'html':'UTF-8'}" data-id-product="{$skladx_id_product|intval}">
  <h3><span class="skladx-mark" aria-hidden="true"></span> SkladX</h3>
  {if !$skladx_configured}
    <p class="text-muted">{l s='SkladX is not configured yet.' mod='skladx'}</p>
  {else}
    <p class="text-muted">{l s='SkladX matches products by their reference (SKU). Products with combinations are sent per combination; every combination needs its own reference. EAN, weight, dimensions and price are taken from the product.' mod='skladx'}</p>
    <div class="form-group">
      <label for="skladx-storage-class">{l s='Storage class in the warehouse' mod='skladx'}</label>
      <select id="skladx-storage-class" class="form-control skladx-storage-class" style="max-width: 320px">
        <option value=""{if $skladx_storage_class == ''} selected{/if}>{l s='— Default (set in SkladX) —' mod='skladx'}</option>
        {foreach $skladx_storage_classes as $class}
          <option value="{$class|escape:'html':'UTF-8'}"{if $skladx_storage_class == $class} selected{/if}>{$class|escape:'html':'UTF-8'}</option>
        {/foreach}
        <option value="custom"{if $skladx_custom_class} selected{/if}>{l s='Other code…' mod='skladx'}</option>
      </select>
      <input type="text" class="form-control skladx-storage-class-custom" style="max-width: 320px; margin-top: 6px{if !$skladx_custom_class}; display: none{/if}" value="{if $skladx_custom_class}{$skladx_storage_class|escape:'html':'UTF-8'}{/if}" placeholder="{l s='Storage class code' mod='skladx'}" maxlength="64">
      <small class="form-text text-muted">{l s='ambient = dry storage, chilled = 2–8 °C, frozen, hazardous, hanging_garment = clothes on hangers, oversize, high_value. Codes of classes defined by your warehouse work too.' mod='skladx'}</small>
    </div>
    <table class="table table-sm skladx-units">
      <thead><tr><th>{l s='Product' mod='skladx'}</th><th>{l s='Reference (SKU)' mod='skladx'}</th><th>EAN</th><th>{l s='Sent to SkladX' mod='skladx'}</th></tr></thead>
      <tbody>
        {foreach $skladx_units as $unit}
          <tr>
            <td>{$unit.name|escape:'html':'UTF-8'}</td>
            <td>{if $unit.sku}<code>{$unit.sku|escape:'html':'UTF-8'}</code>{else}<span class="text-danger">{l s='missing – not sent' mod='skladx'}</span>{/if}</td>
            <td>{$unit.ean|escape:'html':'UTF-8'}</td>
            <td>{if $unit.error}<span class="text-danger">{$unit.error|escape:'html':'UTF-8'}</span>{elseif $unit.synced_at}{$unit.synced_at|escape:'html':'UTF-8'}{else}—{/if}</td>
          </tr>
        {/foreach}
      </tbody>
    </table>
    {if !$skladx_sync_enabled}
      <p class="text-warning">{l s='Automatic product sync is turned off in the module settings.' mod='skladx'}</p>
    {/if}
    <p>
      <button type="button" class="btn btn-primary btn-sm skladx-product-save">{l s='Save SkladX settings' mod='skladx'}</button>
      <button type="button" class="btn btn-outline-secondary btn-sm skladx-product-sync">{l s='Sync to SkladX now' mod='skladx'}</button>
      <span class="skladx-product-result" aria-live="polite"></span>
    </p>
  {/if}
</div>
