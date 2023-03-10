<div class="profile-match-wrapper">
	<div class="profile-match-photo">
		<a href="{{$entry.url}}">
			<img src="{{$entry.photo}}" alt="{{$entry.name}}" width="80" height="80" title="{{$entry.name}} [{{$entry.profile}}]" />
		</a>
	</div>
	<a href="{{$entry.ignlnk}}" title="{{$entry.ignore}}" class="profile-match-ignore drop-icons btn btn-outline-secondary btn-sm" onclick="return confirmDelete();" ><i class="fa fa-close "></i></a>
	<div class="profile-match-break"></div>
	<div class="profile-match-name">
		<a href="{{$entry.url}}" title="{{$entry.name}}">{{$entry.name}}</a>
	</div>
	<div class="profile-match-end"></div>
	{{if $entry.connlnk}}
	<div class="suggest-connect-btn"><a class="btn btn-sm btn-success" href="{{$entry.connlnk}}" title="{{$entry.conntxt}}"><i class="fa fa-plus connect-icon"></i> {{$entry.conntxt}}</a></div>
	{{/if}}
</div>
