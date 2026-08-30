{include file='std-head.tpl' title="Teletext: Refresh TV Guide"}

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Teletext: Refresh TV Guide</h2>
        <a href="/service?port=176" class="btn btn-outline-secondary">Back to Service</a>
    </div>

    {if $sMessage eq 'started'}
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            Import started in the background. Pages will appear as it completes — see docs/protocols/teletext.md for how the store directory is laid out.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    {elseif $sMessage eq 'not_started'}
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            Import did not start — either no TV guide channel/source is configured (<code>teletext_tvguide_channel</code>, <code>teletext_tvguide_source</code>), or an import is already running.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    {/if}

    <p class="text-muted">
        Downloads a 2-day (today/tomorrow) EPG from your own TVHeadend
        instance (<code>teletext_tvguide_source</code>) for every configured
        UK Freeview channel and converts it into the configured channel's
        page store. This happens automatically on a schedule
        (<code>teletext_tvguide_refresh_interval</code>), but you can
        trigger it manually here. Runs as a background process — this page
        returns immediately rather than waiting for it to finish.
    </p>

    <form method="POST" action="/service/teletext/tvguide-refresh">
        <button type="submit" class="btn btn-primary">Refresh TV Guide Now</button>
    </form>
</div>

{include file='std-foot.tpl'}
