<div id="vcard" class="vcard h-card widget">
<div id="profile-photo-wrapper"><a href="{{$link}}"><img class="vcard-photo photo u-photo" src="{{$photo}}" alt="{{$name}}" /></a></div>
{{if $connect}}
<div class="connect-btn-wrapper"><a href="follow?f=&url={{$follow}}" rel="nofollow noopener" class="btn form-control btn-success btn-sm"><i class="fa fa-plus"></i> {{$connect}}</a></div>
{{/if}}
{{if $profdm}}
<div class="profdm-btn-wrapper"><a href="{{$profdm_url}}" class="btn btn-block btn-success btn-sm"><i class="fa fa-envelope"></i> {{$profdm}}</a></div>
{{/if}}

<div class="fn p-name">{{$name}}</div>
</div>



