{if $sAction eq 'create'}
    {include file='std-head.tpl' title="Create User"}
{else}
    {include file='std-head.tpl' title="Edit User: `$oUser->getUsername()`"}
{/if}

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        {if $sAction eq 'create'}
            <h2>Create User</h2>
        {else}
            <h2>Edit User: {$oUser->getUsername()|escape}</h2>
        {/if}
        <a href="/users" class="btn btn-outline-secondary">Back to Users</a>
    </div>

    {if $sError}
        <div class="alert alert-danger" role="alert">
            {$sError|escape}
        </div>
    {/if}

    <form method="POST" action="{$sActionUrl|escape:'html'}">
        {if $sAction eq 'create'}
            <div class="form-group row">
                <label class="col-sm-3 col-form-label">Username</label>
                <div class="col-sm-9">
                    <input type="text" class="form-control" name="username"
                           value="{$aPost.username|default:''|escape}"
                           placeholder="e.g. ALICE" maxlength="10" required>
                    <small class="form-text text-muted">Letters and numbers only, max 10 characters. Stored in upper case.</small>
                </div>
            </div>
            <div class="form-group row">
                <label class="col-sm-3 col-form-label">Home Directory</label>
                <div class="col-sm-9">
                    <input type="text" class="form-control" name="homedir"
                           value="{$aPost.homedir|default:''|escape}"
                           placeholder="e.g. $.HOME.ALICE" required>
                </div>
            </div>
            <div class="form-group row">
                <label class="col-sm-3 col-form-label">Unix UID</label>
                <div class="col-sm-9">
                    <input type="number" class="form-control" name="unixuid"
                           value="{$aPost.unixuid|default:'5000'|escape}" min="1" required>
                </div>
            </div>
        {else}
            <div class="form-group row">
                <label class="col-sm-3 col-form-label">Username</label>
                <div class="col-sm-9">
                    <p class="form-control-plaintext"><strong>{$oUser->getUsername()|escape}</strong></p>
                </div>
            </div>
            <div class="form-group row">
                <label class="col-sm-3 col-form-label">Home Directory</label>
                <div class="col-sm-9">
                    <p class="form-control-plaintext"><code>{$oUser->getHomedir()|escape}</code></p>
                </div>
            </div>
        {/if}

        <div class="form-group row">
            <label class="col-sm-3 col-form-label">Privilege</label>
            <div class="col-sm-9">
                {if $sAction eq 'create'}
                    {assign var="sCurrentPriv" value=$aPost.priv|default:'U'}
                {else}
                    {assign var="sCurrentPriv" value=$oUser->getPriv()}
                {/if}
                <select class="form-control" name="priv">
                    <option value="U" {if $sCurrentPriv eq 'U'}selected{/if}>User</option>
                    <option value="S" {if $sCurrentPriv eq 'S'}selected{/if}>Sysop (System Manager)</option>
                </select>
            </div>
        </div>

        <div class="form-group row">
            <label class="col-sm-3 col-form-label">Boot Option</label>
            <div class="col-sm-9">
                {if $sAction eq 'create'}
                    {assign var="iCurrentOpt" value=$aPost.bootopt|default:0}
                {else}
                    {assign var="iCurrentOpt" value=$oUser->getBootOpt()}
                {/if}
                <select class="form-control" name="bootopt">
                    <option value="0" {if $iCurrentOpt eq 0}selected{/if}>0 — No action</option>
                    <option value="1" {if $iCurrentOpt eq 1}selected{/if}>1 — Load BASIC</option>
                    <option value="2" {if $iCurrentOpt eq 2}selected{/if}>2 — Load and run</option>
                    <option value="3" {if $iCurrentOpt eq 3}selected{/if}>3 — Load and exec</option>
                </select>
            </div>
        </div>

        <div class="form-group row">
            <label class="col-sm-3 col-form-label">Disc Quota (bytes)</label>
            <div class="col-sm-9">
                {if $sAction eq 'create'}
                    {assign var="iCurrentQuota" value=$aPost.quota|default:0}
                {else}
                    {assign var="iCurrentQuota" value=$oUser->getQuota()}
                {/if}
                <input type="number" class="form-control" name="quota"
                       value="{$iCurrentQuota}" min="0">
                <small class="form-text text-muted">0 = use the global default (<code>vfs_default_disc_free</code>). Quotas are reported to legacy admin tools but not enforced.</small>
            </div>
        </div>

        <div class="form-group row">
            <div class="col-sm-9 offset-sm-3">
                {if $sAction eq 'create'}
                    <button type="submit" class="btn btn-success">Create User</button>
                {else}
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                {/if}
                <a href="/users" class="btn btn-outline-secondary ml-2">Cancel</a>
            </div>
        </div>
    </form>
</div>

{include file='std-foot.tpl'}
