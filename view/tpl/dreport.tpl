<div class="generic-content-wrapper">
	<div class="section-title-wrapper">
		{{if $table == 'item'}}
		<div class="dropdown float-end">
			<button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="{{$options}}">
				<i class="fa fa-sort-desc"></i>
			</button>
			<ul class="dropdown-menu">
				<li><a href="dreport/push/?mid={{$mid}}">{{$push}}</a></li>
			</ul>
		</div>
		{{/if}}
		<h2>{{$title}}</h2>
	</div>

	<div>
	<table>
	{{if $entries}}
	{{foreach $entries as $e}}
	<tr>
		<td width="40%">{{$e.name}}</td>
		<td width="20%">{{$e.result}}</td>
		<td width="20%">{{$e.time}}</td>
	</tr>
	{{/foreach}}
	{{/if}}
	</table>
</div>
