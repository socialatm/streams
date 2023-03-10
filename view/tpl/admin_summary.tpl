<div class="generic-content-wrapper" id='adminpage'>
    <div class="section-title-wrapper"><h1>{{$title}} - {{$page}}</h1></div>
    <div class="section-content-wrapper">
{{if $adminalertmsg}}
    <p class="alert alert-warning" role="alert">{{$adminalertmsg}}</p>
{{/if}}
{{if $upgrade}}
    <div class="alert alert-warning" role="alert">{{$upgrade}}</div>
    {{if $can_upgrade}}
    <center><a href="admin?upgrade=true" class="btn btn-std btn-primary" style="color: #FFF;">{{$update}}</a></center>
    {{/if}}
</div>
{{/if}}
    <dl>
        <dt>{{$queues.label}}</dt>
        <dd>{{$queues.queue}}</dd>
    </dl>
    <dl>
        <dt>{{$accounts.0}}</dt>
        <dd>{{foreach from=$accounts.1 item=acc name=account}}<span title="{{$acc.label}}">{{$acc.val}} {{$acc.label}}</span>{{if !$smarty.foreach.account.last}} / {{/if}}{{/foreach}}</dd>
    </dl>
    <dl>
        <dt>{{$pending.0}}</dt>
        <dd>{{$pending.1}}</dt>
    </dl>
    <dl>
        <dt>{{$channels.0}}</dt>
        <dd>{{foreach from=$channels.1 item=ch name=chan}}<span title="{{$ch.label}}">{{$ch.val}} {{$ch.label}}</span>{{if !$smarty.foreach.chan.last}} / {{/if}}{{/foreach}}</dd>
    </dl>
    {{if $plugins}}
    <dl>
        <dt>{{$plugins.0}}</dt>
        <dd>
        {{foreach $plugins.1 as $p}} {{$p}} {{/foreach}}
        </dd>
    </dl>
    {{/if}}
    <dl>
        <dt>{{$version.0}}</dt>
        <dd>{{$version.1}} - {{$build}}</dd>
    </dl>
    {{if $vmaster.1}}
    <dl>
        <dt>{{$vmaster.0}}</dt>
        <dd>{{$vmaster.1}}</dd>
    </dl>
    {{/if}}
    {{if $vdev.1}}
    <dl>
        <dt>{{$vdev.0}}</dt>
        <dd>{{$vdev.1}}</dd>
    </dl>
    {{/if}}
    </div>
</div>
