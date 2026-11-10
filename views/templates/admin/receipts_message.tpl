{**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 *}
<div class="skladx-inbound">
  <h2 class="skladx-page-title">{l s='SkladX receipts' mod='skladx'}</h2>
  <div class="alert alert-{$skladx_message_type|escape:'html':'UTF-8'}">
    {$skladx_message|escape:'html':'UTF-8'}
    {if $skladx_message_link}<br><a href="{$skladx_message_link|escape:'html':'UTF-8'}">{$skladx_message_link_text|escape:'html':'UTF-8'}</a>{/if}
  </div>
</div>
