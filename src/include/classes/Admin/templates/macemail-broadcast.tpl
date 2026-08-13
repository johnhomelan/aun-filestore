{include file='std-head.tpl' title="MaceMail: Send System Message"}

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>MaceMail: Send System Message</h2>
        <a href="/service?port=25" class="btn btn-outline-secondary">Back to Service</a>
    </div>

    {if $sMessage eq 'sent'}
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            Message sent successfully.
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    {/if}

    {if $sError}
        <div class="alert alert-danger" role="alert">
            {$sError|escape}
        </div>
    {/if}

    <p class="text-muted">
        The vintage MaceMail client only understands a fixed set of canned
        messages baked into its own code — there's no room for free text in
        its notification packet, so pick one below rather than typing a
        message. It's sent immediately to every currently logged-on
        MaceMail user.
    </p>

    <form method="POST" action="/service/macemail/broadcast">
        <div class="form-group row">
            <label class="col-sm-3 col-form-label">Message</label>
            <div class="col-sm-9">
                <select class="form-control" name="type" required>
                    {foreach $aMessageTypes as $iType => $sText}
                        <option value="{$iType}">{$sText|escape}</option>
                    {/foreach}
                </select>
            </div>
        </div>
        <div class="form-group row">
            <div class="col-sm-9 offset-sm-3">
                <button type="submit" class="btn btn-warning">Send Message</button>
            </div>
        </div>
    </form>
</div>

{include file='std-foot.tpl'}
