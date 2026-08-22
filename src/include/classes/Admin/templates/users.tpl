{include file='std-head.tpl' title="Users"}

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Users</h2>
        <a href="/users/create" class="btn btn-success"><i class="fas fa-user-plus"></i> Create User</a>
    </div>

    {if $sMessage eq 'created'}
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            User created successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    {elseif $sMessage eq 'updated'}
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            User updated successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    {elseif $sMessage eq 'deleted'}
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            User deleted successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    {elseif $sMessage eq 'password_changed'}
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            Password changed successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    {elseif $sMessage eq 'notfound'}
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            User not found.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    {/if}

    <table class="table table-striped table-sm">
        <thead class="table-dark">
            <tr>
                <th>Username</th>
                <th>Plugin</th>
                <th>Priv</th>
                <th>Home Dir</th>
                <th>Boot Opt</th>
                <th>Quota (bytes)</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            {foreach $aUsers as $aEntry}
            {assign var="oUser" value=$aEntry.user}
            <tr>
                <td>{$oUser->getUsername()|escape}</td>
                <td><span class="badge bg-secondary">{$aEntry.plugin|escape}</span></td>
                <td>
                    {if $oUser->getPriv() eq 'S'}
                        <span class="badge bg-warning text-dark">Sysop</span>
                    {else}
                        <span class="badge bg-light text-dark">User</span>
                    {/if}
                </td>
                <td><code>{$oUser->getHomedir()|escape}</code></td>
                <td>{$oUser->getBootOpt()}</td>
                <td>{if $oUser->getQuota() eq 0}<em class="text-muted">default</em>{else}{$oUser->getQuota()}{/if}</td>
                <td>
                    <a href="/users/edit?username={$oUser->getUsername()|escape:'url'}" class="btn btn-sm btn-outline-primary me-1">Edit</a>
                    <a href="/users/setpassword?username={$oUser->getUsername()|escape:'url'}" class="btn btn-sm btn-outline-secondary me-1">Set Password</a>
                    <a href="/users/delete?username={$oUser->getUsername()|escape:'url'}" class="btn btn-sm btn-outline-danger">Delete</a>
                </td>
            </tr>
            {foreachelse}
            <tr>
                <td colspan="7" class="text-center text-muted">No users found.</td>
            </tr>
            {/foreach}
        </tbody>
    </table>
</div>

{include file='std-foot.tpl'}
