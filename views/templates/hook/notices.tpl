{**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 *}
<div class="skladx-notices" data-dismiss-url="{$skladx_dismiss_url|escape:'html':'UTF-8'}">
  {foreach $skladx_notices as $notice}
    <div class="alert alert-{$notice.type|escape:'html':'UTF-8'} skladx-notice" data-id="{$notice.id|escape:'html':'UTF-8'}">
      <button type="button" class="close skladx-notice__dismiss" aria-label="{l s='Dismiss' mod='skladx'}">&times;</button>
      <strong>SkladX:</strong> {$notice.message|escape:'html':'UTF-8'}
      {if $notice.link}<a href="{$notice.link|escape:'html':'UTF-8'}">{$notice.link_text|escape:'html':'UTF-8'}</a>{/if}
    </div>
  {/foreach}
  {if count($skladx_notices) > 1}
    <p class="skladx-notices__all"><a href="#" class="skladx-notice__dismiss-all">{l s='Dismiss all SkladX notices' mod='skladx'}</a></p>
  {/if}
</div>
