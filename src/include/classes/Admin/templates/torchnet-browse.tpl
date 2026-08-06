{include file='std-head.tpl' title="TorchNet File Browser"}

<div class="container mt-4">
    <h2><i class="fas fa-hdd"></i> TorchNet File Browser &mdash; {$oService->getName()|escape}</h2>

    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            {foreach $aBreadcrumbs as $aCrumb}
                {if is_null($aCrumb.path)}
                    <li class="breadcrumb-item active" aria-current="page">{$aCrumb.label|escape}</li>
                {else}
                    <li class="breadcrumb-item">
                        <a href="/service/torchnet/browse?port={$iPort}&amp;path={$aCrumb.path|escape:'url'}">{$aCrumb.label|escape}</a>
                    </li>
                {/if}
            {/foreach}
        </ol>
    </nav>

    {if $sCurrentPath == ''}

        <h4 class="mb-3">Configured Drives</h4>
        <div class="row">
            {foreach $aDrives as $sLetter => $sDrivePath}
                <div class="col-sm-2 mb-3">
                    <a href="/service/torchnet/browse?port={$iPort}&amp;path={$sDrivePath|escape:'url'}"
                       class="btn btn-outline-primary btn-block">
                        <i class="fas fa-hdd fa-2x"></i><br>Drive {$sLetter|escape}
                    </a>
                </div>
            {/foreach}
        </div>

    {else}

        <table class="table table-sm table-hover">
            <thead class="thead-light">
                <tr>
                    <th>Name</th>
                    <th class="text-right">Size</th>
                    <th>Load addr</th>
                    <th>Exec addr</th>
                </tr>
            </thead>
            <tbody>
                {if empty($aEntries)}
                    <tr>
                        <td colspan="4" class="text-muted text-center"><em>Empty directory</em></td>
                    </tr>
                {else}
                    {foreach $aEntries as $oEntry}
                        {assign var="sEntryName" value=$oEntry->getEconetName()}
                        {assign var="sFullPath" value="`$sCurrentPath`.`$sEntryName`"}
                        <tr>
                            {if $oEntry->isDir()}
                                <td>
                                    <a href="/service/torchnet/browse?port={$iPort}&amp;path={$sFullPath|escape:'url'}">
                                        <i class="fas fa-folder text-warning"></i> {$sEntryName|escape}
                                    </a>
                                </td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                            {else}
                                <td>
                                    <a href="/service/torchnet/download?port={$iPort}&amp;path={$sFullPath|escape:'url'}">
                                        <i class="fas fa-file text-secondary"></i> {$sEntryName|escape}
                                    </a>
                                </td>
                                <td class="text-right">{$oEntry->getSize()|escape}</td>
                                <td><code>{$oEntry->getLoadAddr()|string_format:"%08X"}</code></td>
                                <td><code>{$oEntry->getExecAddr()|string_format:"%08X"}</code></td>
                            {/if}
                        </tr>
                    {/foreach}
                {/if}
            </tbody>
        </table>

    {/if}
</div>

{include file='std-foot.tpl'}
