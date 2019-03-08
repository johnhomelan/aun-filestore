{include file='std-head.tpl' title='AUN Server: Admin'}

{if isset($error) AND strlen($error)>0}
<div class="alert alert-danger" role="alert">
{$error}
</div>
{/if}

<div class="container">
	<h2>Registered Services</h2>
	<table class="table">
		<thead>
			<tr><th>Services</th><th>Ports</th></tr>
		</thead>
		<tbody>
			{foreach from=$aServices item=oService}
			<tr><td>
				{assign var="aPorts" value=$oService->getServicePorts()}
				<a href="service?port={$aPorts.0}">{$oService->getName()}</a>
			</td><td>
				{', '|implode:$oService->getServicePorts()}
			</td></tr>
			{/foreach}

		</tbody>
	</table>
</div>
{include file='std-foot.tpl'}

