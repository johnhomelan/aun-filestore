{include file='std-head.tpl' title="MaceMail: Assign Mail Slot"}

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>MaceMail: Assign Mail Slot</h2>
        <a href="/service?port=25" class="btn btn-outline-secondary">Back to Service</a>
    </div>

    {if $sMessage eq 'assigned'}
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            Slot assigned successfully.
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    {/if}

    {if $sError}
        <div class="alert alert-danger" role="alert">
            {$sError|escape}
        </div>
    {/if}

    <form method="POST" action="/service/macemail/slots/assign">
        <div class="form-group row">
            <label class="col-sm-3 col-form-label">Slot Number</label>
            <div class="col-sm-9">
                <input type="number" class="form-control" name="slot" min="0" required>
                <small class="form-text text-muted">A user's slot number is what they type into the MaceMail terminal client to log on.</small>
            </div>
        </div>
        <div class="form-group row">
            <label class="col-sm-3 col-form-label">Filestore Username</label>
            <div class="col-sm-9">
                <input type="text" class="form-control" name="username" placeholder="e.g. ALICE" required>
                <small class="form-text text-muted">Must already exist as a filestore user — MaceMail has no user database of its own.</small>
            </div>
        </div>
        <div class="form-group row">
            <div class="col-sm-9 offset-sm-3">
                <button type="submit" class="btn btn-success">Assign Slot</button>
            </div>
        </div>
    </form>

    <h4 class="mt-4">Current Assignments</h4>
    <table class="table table-striped table-sm">
        <thead class="thead-dark">
            <tr><th>Slot</th><th>Username</th><th>Online</th><th>Last Used</th></tr>
        </thead>
        <tbody>
            {foreach $aSlots as $aSlot}
            <tr>
                <td>{$aSlot.slot}</td>
                <td>{$aSlot.username|escape}</td>
                <td>{if $aSlot.online}<i class="fas fa-check text-success"></i>{else}<i class="fas fa-times text-danger"></i>{/if}</td>
                <td>{$aSlot.last_used|escape}</td>
            </tr>
            {foreachelse}
            <tr><td colspan="4" class="text-center text-muted">No mail slots assigned yet.</td></tr>
            {/foreach}
        </tbody>
    </table>
</div>

{include file='std-foot.tpl'}
