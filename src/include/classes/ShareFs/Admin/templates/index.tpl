{include file='std-head.tpl' title='ShareFS Admin'}

<div class="container">
	<h2>ShareFS / Access+ Components</h2>
	<table class="table">
		<thead>
			<tr><th>Component</th><th>Status</th></tr>
		</thead>
		<tbody>
			{foreach from=$aComponents item=oAdmin}
			<tr>
				<td><a href="component?type={$oAdmin->getId()}">{$oAdmin->getName()}</a></td>
				<td>{$oAdmin->getStatus()}</td>
			</tr>
			{/foreach}
		</tbody>
	</table>
</div>
{include file='std-foot.tpl'}
