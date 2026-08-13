{include file='std-head.tpl' title="MaceMail: Force Logoff"}

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>MaceMail: Force Logoff</h2>
        <a href="/service?port=25" class="btn btn-outline-secondary">Back to Service</a>
    </div>

    {if $sMessage eq 'loggedoff'}
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            User logged off successfully.
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
            <tr><th>Username</th><th>Network</th><th>Station</th><th>Action</th></tr>
        </thead>
        <tbody>
            {foreach $aOnline as $aUser}
            <tr>
                <td>{$aUser.username|escape}</td>
                <td>{$aUser.network}</td>
                <td>{$aUser.station}</td>
                <td>
                    <form method="POST" action="/service/macemail/logoff" class="mb-0">
                        <input type="hidden" name="username" value="{$aUser.username|escape}">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Force Logoff</button>
                    </form>
                </td>
            </tr>
            {foreachelse}
            <tr><td colspan="4" class="text-center text-muted">No MaceMail users currently online.</td></tr>
            {/foreach}
        </tbody>
    </table>
</div>

{include file='std-foot.tpl'}
