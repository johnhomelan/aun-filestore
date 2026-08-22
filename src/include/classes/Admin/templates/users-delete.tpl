{include file='std-head.tpl' title="Delete User: `$oUser->getUsername()`"}

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Delete User: {$oUser->getUsername()|escape}</h2>
        <a href="/users" class="btn btn-outline-secondary">Back to Users</a>
    </div>

    {if $sError}
        <div class="alert alert-danger" role="alert">
            {$sError|escape}
        </div>
    {/if}

    <div class="card border-danger">
        <div class="card-body">
            <h5 class="card-title text-danger">Confirm deletion</h5>
            <p class="card-text">
                Are you sure you want to permanently delete the user <strong>{$oUser->getUsername()|escape}</strong>?
                This cannot be undone. Any files belonging to this user will remain on disk.
            </p>
            <dl class="row">
                <dt class="col-sm-3">Username</dt>
                <dd class="col-sm-9">{$oUser->getUsername()|escape}</dd>
                <dt class="col-sm-3">Home Directory</dt>
                <dd class="col-sm-9"><code>{$oUser->getHomedir()|escape}</code></dd>
                <dt class="col-sm-3">Privilege</dt>
                <dd class="col-sm-9">{if $oUser->getPriv() eq 'S'}Sysop{else}User{/if}</dd>
            </dl>
            <form method="POST" action="/users/delete?username={$oUser->getUsername()|escape:'url'}">
                <button type="submit" class="btn btn-danger">Yes, delete this user</button>
                <a href="/users" class="btn btn-outline-secondary ms-2">Cancel</a>
            </form>
        </div>
    </div>
</div>

{include file='std-foot.tpl'}
