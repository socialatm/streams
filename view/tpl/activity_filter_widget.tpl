<div class="widget">
	<h3 class="d-flex justify-content-between align-items-center">
		<div onclick="openClose('actfilt'); return true;">
		{{$title}}
		{{if ! $reset}}
		<i class="fa fa-fw fa-caret-down fakelink"></i>
		{{/if}}
		</div>
		{{if $reset}}
		<a href="{{$reset.url}}" class="text-muted" title="{{$reset.title}}">
			<i class="fa fa-fw fa-{{$reset.icon}}"></i>
		</a>
		{{/if}}
	</h3>
	<div id="actfilt" style="display: none;">
	{{if $name}}
	<div class="notifications-textinput">
		<form method="get" action="{{$name.url}}" role="search">
			<div class="text-muted notifications-textinput-filter"><i class="fa fa-fw fa-user"></i></div>
			<input id="xchan" type="hidden" value="" name="xchan" />
			<input id="xchan-filter" class="form-control form-control-sm{{if $name.sel}} {{$name.sel}}{{/if}}" autocomplete="off" autofill="off" type="text" value="" placeholder="{{$name.label}}" name="name" title="" />
		</form>
	</div>
	<script>
		$("#xchan-filter").name_autocomplete(baseurl + '/acl', 'z', true, function(data) {
			$("#xchan").val(data.xid);
		});
	</script>
	{{/if}}
	{{$content}}
	</div>
</div>
