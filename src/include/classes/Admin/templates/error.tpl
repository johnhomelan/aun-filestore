{include file='std-head.tpl' title='Download iPXE ISO'}
<H1>AUN Filestore</H1>
<form action="download"  method="GET">

<div class="container">

{if isset($error) AND strlen($error)>0}
<div class="alert alert-danger" role="alert">
{$error}
</div>
{/if}

{include file='std-foot.tpl'}

