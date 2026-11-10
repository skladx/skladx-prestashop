{**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 *}
<div class="skladx-inbound">
  <p class="skladx-inbound-back"><a href="{$skladx_back_url|escape:'html':'UTF-8'}">{l s='← All receipts' mod='skladx'}</a></p>
  <div id="skladx-inbound-app" class="skladx-inbound-app">
    <p class="skladx-inbound-loading">{l s='Loading…' mod='skladx'}</p>
    <noscript>{l s='The receipt editor needs JavaScript.' mod='skladx'}</noscript>
  </div>
</div>
<script type="application/json" id="skladx-inbound-config">{$skladx_config_json nofilter}</script>
<script>
  window.skladxInbound = JSON.parse(document.getElementById('skladx-inbound-config').textContent);
</script>
