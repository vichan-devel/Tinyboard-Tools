$(document).ready(function(){
	var enable_fa = function() {
		$('p.intro label').each(function() {
			if($(this).children('a.capcode').length == 0) {
				var id = $(this).parent().children('a.post_no:eq(1)').text();
				
				if($(this).children('a.email').length != 0)
					var p = $(this).children('a.email');
				else
					var p = $(this);
				
				old_info[id] = {'name': p.children('span.name').text(), 'trip': p.children('span.trip').text()};
				
				p.children('span.name').text('Anonymous');
				if(p.children('span.trip').length != 0)
					p.children('span.trip').text('');
			}
		});
	};
	
	var disable_fa = function() {
		$('p.intro label').each(function() {
			if($(this).children('a.capcode').length == 0) {
				var id = $(this).parent().children('a.post_no:eq(1)').text();
				
				if(old_info[id]) {
					if($(this).children('a.email').length != 0)
						var p = $(this).children('a.email');
					else
						var p = $(this);
					
					p.children('span.name').text(old_info[id]['name']);
					if(p.children('span.trip').length != 0)
						p.children('span.trip').text(old_info[id]['trip']);
				}
			}
		});
	};
	
	old_info = {};
	forced_anon = localStorage['forcedanon'] ? true : false;
	
	$('hr:first').before('<div id="forced-anon" style="text-align:right"><a class="unimportant" href="javascript:void(0)">-</a></div>');
	$('div#forced-anon a').text('Forced anonymity (' + (forced_anon ? 'enabled' : 'disabled') + ')');
	
	$('div#forced-anon a').click(function() {
		forced_anon = !forced_anon;
		
		if(forced_anon) {
			$('div#forced-anon a').text('Forced anonymity (enabled)');
			localStorage.forcedanon = true;
			enable_fa();
		} else {
			$('div#forced-anon a').text('Forced anonymity (disabled)');
			delete localStorage.forcedanon;
			disable_fa();
		}
		
		return false;
	});
	
	if(forced_anon)
		enable_fa();
	
});
