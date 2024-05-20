{include file='std-head.tpl' title="Service: `$oService->getName()`"}

<div class="container">
	<h2>Service: {$oService->getName()}</h2>

	{if !is_object($oAdmin)}
		<div class="alert alert-danger" role="alert">
		This service does not provide and admin interface.
		</div>
	{else}
		<dl class="row">
			<dt class="col-sm-3">Name</dt><dd class="col-sm-9">{$oAdmin->getName()}</dd>

			<dt class="col-sm-3">Enabled</dt><dd class="col-sm-9"><input type="checkbox" {if !$oAdmin->isDisabled()}checked{/if} data-toggle="toggle" data-onstyle="success"></dd>

			<dt class="col-sm-3">Description</dt><dd class="col-sm-9">{$oAdmin->getDescription()}</dd>

			<dt class="col-sm-3">Status</dt><dd class="col-sm-9">{$oAdmin->getStatus()}</dd>

			<dt class="col-sm-3">Service Ports</dt><dd class="col-sm-9">{', '|implodemod:$oService->getServicePorts()}</dd>
		</dl>


		<ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
		{foreach from=$oAdmin->getEntityTypes() key="sEntityType" item="sEntityTypeName"}
		  <li class="nav-item">
		    <a class="nav-link {if $sEntityTypeName@iteration eq 1}active{/if}" id="pills-{$sEntityType}-tab" data-toggle="pill" href="#pills-{$sEntityType}" role="tab" aria-controls="pills-{$sEntityType}" aria-selected="true">{$sEntityTypeName}</a>
		  </li>
		{/foreach}
		</ul>
		<div class="tab-content" id="pills-tabContent">
		{foreach from=$oAdmin->getEntityTypes() key="sEntityType" item="sEntityTypeName"}
			<div class="tab-pane fade show {if $sEntityTypeName@iteration eq 1}active{/if} " id="pills-{$sEntityType}" role="tabpanel" aria-labelledby="pills-{$sEntityType}-tab">
				{assign value=$oAdmin->getEntities($sEntityType)  var="aEntities"}
				{assign value=$oAdmin->getEntityFields($sEntityType) var="aEntityFields"}
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
								{if $sFieldType=='datetime'}
									<td>{$oEntity->getValue($sField)|date_format:"%H:%M:%S %D"}</td>
								{elseif $sFieldType=='bool'}
									<td>{if $oEntity->getValue($sField)}<i class="fas fa-check text-success"></i>{else}<i class="fas fa-times text-danger"></i>{/if}</td>
								{else}
									<td>{$oEntity->getValue($sField)}</td>
								{/if}
							{/foreach}
						</tr>
						{/foreach}
					</tbody>
				</table>			
			</div>	
		{/foreach}
		</div>
	{/if}
	
</div>
{include file='std-foot.tpl'}

