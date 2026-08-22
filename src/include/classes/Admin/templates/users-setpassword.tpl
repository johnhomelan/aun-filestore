{include file='std-head.tpl' title="Set Password: `$oUser->getUsername()`"}

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Set Password: {$oUser->getUsername()|escape}</h2>
        <a href="/users" class="btn btn-outline-secondary">Back to Users</a>
    </div>

    {if $sError}
        <div class="alert alert-danger" role="alert">
            {$sError|escape}
        </div>
    {/if}

    <form method="POST" action="/users/setpassword?username={$oUser->getUsername()|escape:'url'}">
        <div class="row mb-3">
            <label class="col-sm-3 col-form-label">Username</label>
            <div class="col-sm-9">
                <p class="form-control-plaintext"><strong>{$oUser->getUsername()|escape}</strong></p>
            </div>
        </div>
        <div class="row mb-3">
            <label class="col-sm-3 col-form-label">New Password</label>
            <div class="col-sm-9">
                <input type="password" class="form-control" name="password" autocomplete="new-password">
                <small class="form-text text-muted">Leave blank to set an empty password (no authentication required for this user).</small>
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-sm-9 offset-sm-3">
                <button type="submit" class="btn btn-primary">Set Password</button>
                <a href="/users" class="btn btn-outline-secondary ms-2">Cancel</a>
            </div>
        </div>
    </form>
</div>

{include file='std-foot.tpl'}
