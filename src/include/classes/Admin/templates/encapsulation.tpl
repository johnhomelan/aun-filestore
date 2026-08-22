{include file='std-head.tpl' title="Encapsulation: `$oAdmin->getName()`"}

<div class="container">
	<h2>Encapsulation: {$oAdmin->getName()}</h2>

	<dl class="row">
		<dt class="col-sm-3">Name</dt><dd class="col-sm-9">{$oAdmin->getName()}</dd>
		<dt class="col-sm-3">Status</dt><dd class="col-sm-9">{$oAdmin->getStatus()}</dd>
		<dt class="col-sm-3">Description</dt>
		<dd class="col-sm-9">{$oAdmin->getDescription()|nl2br}</dd>
	</dl>

	<ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
	{foreach from=$oAdmin->getEntityTypes() key="sEntityType" item="sEntityTypeName"}
	  <li class="nav-item">
	    <a class="nav-link {if $sEntityTypeName@iteration eq 1}active{/if}" id="pills-{$sEntityType}-tab" data-bs-toggle="pill" href="#pills-{$sEntityType}" role="tab" aria-controls="pills-{$sEntityType}" aria-selected="true">{$sEntityTypeName}</a>
	  </li>
	{/foreach}
	</ul>

	<div class="tab-content" id="pills-tabContent">
	{foreach from=$oAdmin->getEntityTypes() key="sEntityType" item="sEntityTypeName"}
		<div class="tab-pane fade show {if $sEntityTypeName@iteration eq 1}active{/if}" id="pills-{$sEntityType}" role="tabpanel" aria-labelledby="pills-{$sEntityType}-tab">
			{assign value=$oAdmin->getEntities($sEntityType)  var="aEntities"}
			{assign value=$oAdmin->getEntityFields($sEntityType) var="aEntityFields"}
			{if $aEntities|@count eq 0}
				<p class="text-muted">No entries.</p>
			{else}
			<table class="table">
				<thead>
					<tr>
						{foreach from=$aEntityFields key=sField item=sFieldType}
							<th>{$sField|ucfirst}</th>
						{/foreach}
					</tr>
				</thead>
				<tbody>
					{foreach $aEntities as $oEntity}
					<tr>
						{foreach from=$aEntityFields key=sField item=sFieldType}
							<td>{$oEntity->getValue($sField)}</td>
						{/foreach}
					</tr>
					{/foreach}
				</tbody>
			</table>
			{/if}
		</div>
	{/foreach}
	</div>

</div>
{include file='std-foot.tpl'}
