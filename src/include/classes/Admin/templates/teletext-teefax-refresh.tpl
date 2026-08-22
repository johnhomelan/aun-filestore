{include file='std-head.tpl' title="Teletext: Refresh Teefax"}

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Teletext: Refresh Teefax</h2>
        <a href="/service?port=176" class="btn btn-outline-secondary">Back to Service</a>
    </div>

    {if $sMessage eq 'started'}
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            Import started in the background. Pages will appear as it completes — see docs/protocols/teletext.md for how the store directory is laid out.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    {elseif $sMessage eq 'not_started'}
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            Import did not start — either no Teefax channel is configured (<code>teletext_teefax_channel</code>), or an import is already running.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    {/if}

    <p class="text-muted">
        Downloads and converts the Teefax teletext archive into the
        configured channel's page store. This happens automatically on a
        schedule (<code>teletext_teefax_refresh_interval</code>), but you
        can trigger it manually here. Runs as a background process — this
        page returns immediately rather than waiting for it to finish.
    </p>

    <form method="POST" action="/service/teletext/teefax-refresh">
        <button type="submit" class="btn btn-primary">Refresh Teefax Now</button>
    </form>
</div>

{include file='std-foot.tpl'}
