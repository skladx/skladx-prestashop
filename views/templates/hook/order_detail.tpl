{**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 *}
<section class="skladx-pickup-info box">
  {if $skladx_point}
    <h4>{l s='Pickup point' mod='skladx'}</h4>
    <p>
      <strong>{$skladx_point.name|escape:'html':'UTF-8'}</strong><br>
      {$skladx_point_address|escape:'html':'UTF-8'}
    </p>
  {/if}
  {if $skladx_tracking}
    <h4>{l s='Shipment tracking' mod='skladx'}</h4>
    <p>
      {l s='Tracking number:' mod='skladx'}
      {if $skladx_tracking.url}
        <a href="{$skladx_tracking.url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{$skladx_tracking.number|escape:'html':'UTF-8'}</a>
      {else}
        {$skladx_tracking.number|escape:'html':'UTF-8'}
      {/if}
    </p>
  {/if}
</section>
