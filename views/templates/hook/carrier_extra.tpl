{**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 *}
<div class="skladx-pickup" data-carrier="{$skladx_carrier|escape:'html':'UTF-8'}" data-id-carrier="{$skladx_id_carrier|intval}" data-city="{$skladx_city|escape:'html':'UTF-8'}" data-postcode="{$skladx_postcode|escape:'html':'UTF-8'}" data-country="{$skladx_country|escape:'html':'UTF-8'}"{if $skladx_point} data-point="{$skladx_point.id|escape:'html':'UTF-8'}"{/if}>
  <p class="skladx-pickup__chosen"{if !$skladx_point} hidden{/if}>
    {if $skladx_point}
      <strong>{l s='Pickup point:' mod='skladx'}</strong>
      {$skladx_point.name|escape:'html':'UTF-8'}{if $skladx_point_address}, <span class="skladx-pickup__address">{$skladx_point_address|escape:'html':'UTF-8'}</span>{/if}
    {/if}
  </p>
  <p class="skladx-pickup__required">{l s='Please choose a pickup point for the selected shipping method.' mod='skladx'}</p>
  <button type="button" class="btn btn-outline-primary btn-sm skladx-pickup__button" data-skladx-pickup-open="{$skladx_carrier|escape:'html':'UTF-8'}">
    {if $skladx_point}{l s='Change' mod='skladx'}{else}{l s='Choose pickup point' mod='skladx'}{/if}
  </button>
</div>
