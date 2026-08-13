{include file='std-head.tpl' title="MaceMail: Unassign Mail Slot"}

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>MaceMail: Unassign Mail Slot</h2>
        <a href="/service?port=25" class="btn btn-outline-secondary">Back to Service</a>
    </div>

    {if $sMessage eq 'unassigned'}
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            Slot unassigned successfully.
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    {/if}

    {if $sError}
        <div class="alert alert-danger" role="alert">
            {$sError|escape}
        </div>
    {/if}

    <table class="table table-striped table-sm">
        <thead class="thead-dark">
            <tr><th>Slot</th><th>Username</th><th>Online</th><th>Last Used</th><th>Action</th></tr>
        </thead>
        <tbody>
            {foreach $aSlots as $aSlot}
            <tr>
                <td>{$aSlot.slot}</td>
                <td>{$aSlot.username|escape}</td>
                <td>{if $aSlot.online}<i class="fas fa-check text-success"></i>{else}<i class="fas fa-times text-danger"></i>{/if}</td>
                <td>{$aSlot.last_used|escape}</td>
                <td>
                    <form method="POST" action="/service/macemail/slots/unassign" class="mb-0">
                        <input type="hidden" name="slot" value="{$aSlot.slot}">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Unassign</button>
                    </form>
                </td>
            </tr>
            {foreachelse}
            <tr><td colspan="5" class="text-center text-muted">No mail slots assigned yet.</td></tr>
            {/foreach}
        </tbody>
    </table>
</div>

{include file='std-foot.tpl'}
