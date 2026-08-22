{include file='std-head.tpl' title="File Server Browser"}

<div class="container mt-4">
    <h2><i class="fas fa-folder-open"></i> File Server Browser &mdash; {$oService->getName()|escape}</h2>

    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            {foreach $aBreadcrumbs as $aCrumb}
                {if $aCrumb.path === null}
                    <li class="breadcrumb-item active" aria-current="page">{$aCrumb.label|escape}</li>
                {else}
                    <li class="breadcrumb-item">
                        <a href="/service/fileserver/browse?port={$iPort}&amp;path={$aCrumb.path|escape:'url'}">{$aCrumb.label|escape}</a>
                    </li>
                {/if}
            {/foreach}
        </ol>
    </nav>

    <table class="table table-sm table-hover">
        <thead class="table-light">
            <tr>
                <th>Name</th>
                <th class="text-end">Size</th>
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
                                <a href="/service/fileserver/browse?port={$iPort}&amp;path={$sFullPath|escape:'url'}">
                                    <i class="fas fa-folder text-warning"></i> {$sEntryName|escape}
                                </a>
                            </td>
                            <td>&mdash;</td>
                            <td>&mdash;</td>
                            <td>&mdash;</td>
                        {else}
                            <td>
                                <a href="/service/fileserver/download?port={$iPort}&amp;path={$sFullPath|escape:'url'}">
                                    <i class="fas fa-file text-secondary"></i> {$sEntryName|escape}
                                </a>
                            </td>
                            <td class="text-end">{$oEntry->getSize()|escape}</td>
                            <td><code>{$oEntry->getLoadAddr()|string_format:"%08X"}</code></td>
                            <td><code>{$oEntry->getExecAddr()|string_format:"%08X"}</code></td>
                        {/if}
                    </tr>
                {/foreach}
            {/if}
        </tbody>
    </table>
</div>

{include file='std-foot.tpl'}
