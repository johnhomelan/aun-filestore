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
			<tr><th>Service</th><th>Ports</th></tr>
		</thead>
		<tbody>
			{foreach from=$aServices item=oService}
			<tr><td>
				{assign var="aPorts" value=$oService->getServicePorts()}
				<a href="service?port={$aPorts.0}">{$oService->getName()}</a>
			</td><td>
				{', '|implodemod:$oService->getServicePorts()}
			</td></tr>
			{/foreach}
		</tbody>
	</table>

	<h2>User Management</h2>
	<p><a href="/users" class="btn btn-outline-primary">Manage Users</a></p>

	<h2>Encapsulation Methods</h2>
	<table class="table">
		<thead>
			<tr><th>Encapsulation</th><th>Status</th></tr>
		</thead>
		<tbody>
			{foreach from=$aEncapsulations item=oAdmin}
			<tr>
				<td><a href="encapsulation?type={$oAdmin->getId()}">{$oAdmin->getName()}</a></td>
				<td>{$oAdmin->getStatus()}</td>
			</tr>
			{/foreach}
		</tbody>
	</table>
</div>
{include file='std-foot.tpl'}

