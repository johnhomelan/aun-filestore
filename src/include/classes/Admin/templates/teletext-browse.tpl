{include file='std-head.tpl' title="Teletext: Browse Pages"}

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Teletext: Browse Pages</h2>
        <a href="/service?port=176" class="btn btn-outline-secondary">Back to Service</a>
    </div>

    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item{if $sChannel eq ''} active{/if}">
                {if $sChannel eq ''}
                    Channels
                {else}
                    <a href="/service/teletext/browse">Channels</a>
                {/if}
            </li>
            {if $sChannel neq ''}
                <li class="breadcrumb-item{if $sPage eq ''} active{/if}">
                    {if $sPage eq ''}
                        Channel {$sChannel|escape}
                    {else}
                        <a href="/service/teletext/browse?channel={$sChannel|escape:'url'}">Channel {$sChannel|escape}</a>
                    {/if}
                </li>
            {/if}
            {if $sPage neq ''}
                <li class="breadcrumb-item active" aria-current="page">Page {$sPage|escape}</li>
            {/if}
        </ol>
    </nav>

    {if $sChannel eq ''}
        <table class="table table-sm table-hover">
            <thead class="table-light">
                <tr><th>Channel</th><th class="text-end">Pages</th></tr>
            </thead>
            <tbody>
                {if empty($aChannels)}
                    <tr><td colspan="2" class="text-muted text-center"><em>No channels in the store</em></td></tr>
                {else}
                    {foreach $aChannels as $aChannelRow}
                        <tr>
                            <td><a href="/service/teletext/browse?channel={$aChannelRow.channel|escape:'url'}">{$aChannelRow.channel|escape}</a></td>
                            <td class="text-end">{$aChannelRow.page_count|escape}</td>
                        </tr>
                    {/foreach}
                {/if}
            </tbody>
        </table>

    {elseif $sPage eq ''}
        <div class="row">
            {if empty($aPages)}
                <div class="col text-muted"><em>No pages stored for channel {$sChannel|escape}</em></div>
            {else}
                {foreach $aPages as $sPageNumber}
                    <div class="col-2 mb-2">
                        <a class="btn btn-outline-primary w-100" href="/service/teletext/browse?channel={$sChannel|escape:'url'}&amp;page={$sPageNumber|escape:'url'}">{$sPageNumber|escape}</a>
                    </div>
                {/foreach}
            {/if}
        </div>

    {elseif empty($sPageDataUrl)}
        <div class="alert alert-warning">Page {$sPage|escape} was not found on channel {$sChannel|escape}.</div>

    {else}
        {if $aSubpages|@count > 1}
            <ul class="nav nav-tabs mb-3">
                {foreach $aSubpages as $iSubpageOption}
                    <li class="nav-item">
                        <a class="nav-link{if $iSubpageOption eq $iSubpage} active{/if}"
                           href="/service/teletext/browse?channel={$sChannel|escape:'url'}&amp;page={$sPage|escape:'url'}&amp;subpage={$iSubpageOption|escape:'url'}">
                            Subpage {$iSubpageOption|escape}
                        </a>
                    </li>
                {/foreach}
            </ul>
        {/if}

        <canvas class="teletext-canvas" data-teletext-src="{$sPageDataUrl|escape}" style="image-rendering: pixelated; border: 1px solid #444;"></canvas>
    {/if}
</div>

<script src="/static/teletext-render.js" defer></script>

{include file='std-foot.tpl'}
