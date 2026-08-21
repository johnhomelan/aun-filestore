{include file='std-head.tpl' title='ShareFS Admin: Error'}

<div class="container">
{if isset($error) AND strlen($error)>0}
<div class="alert alert-danger" role="alert">
{$error}
</div>
{/if}
</div>

{include file='std-foot.tpl'}
