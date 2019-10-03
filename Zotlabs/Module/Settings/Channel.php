<?php

namespace Zotlabs\Module\Settings;

use App;
use Zotlabs\Lib\Libsync;
use Zotlabs\Lib\AccessList;
use Zotlabs\Access\Permissions;
use Zotlabs\Access\PermissionRoles;
use Zotlabs\Access\PermissionLimits;
use Zotlabs\Access\AccessControl;
use Zotlabs\Daemon\Master;
use Zotlabs\Lib\Permcat;

class Channel {


	function post() {

		$channel = App::get_channel();

		check_form_security_token_redirectOnErr('/settings', 'settings');
		
		call_hooks('settings_post', $_POST);
	
		$set_perms = '';
	
		$role = ((x($_POST,'permissions_role')) ? notags(trim($_POST['permissions_role'])) : '');
		$oldrole = get_pconfig(local_channel(),'system','permissions_role');


		if(($role != $oldrole) || ($role === 'custom')) {
	
			if($role === 'custom') {
				$hide_presence    = (((x($_POST,'hide_presence')) && (intval($_POST['hide_presence']) == 1)) ? 1: 0);
				$def_group        = ((x($_POST,'group-selection')) ? notags(trim($_POST['group-selection'])) : '');
				$r = q("update channel set channel_default_group = '%s' where channel_id = %d",
					dbesc($def_group),
					intval(local_channel())
				);	
	
				$global_perms = Permissions::Perms();
	
				foreach($global_perms as $k => $v) {
					PermissionLimits::Set(local_channel(),$k,intval($_POST[$k]));
				}
				$acl = new AccessControl($channel);
				$acl->set_from_array($_POST);
				$x = $acl->get();
	
				$r = q("update channel set channel_allow_cid = '%s', channel_allow_gid = '%s', 
					channel_deny_cid = '%s', channel_deny_gid = '%s' where channel_id = %d",
					dbesc($x['allow_cid']),
					dbesc($x['allow_gid']),
					dbesc($x['deny_cid']),
					dbesc($x['deny_gid']),
					intval(local_channel())
				);
			}
			else {
			   	$role_permissions = PermissionRoles::role_perms($_POST['permissions_role']);
				if(! $role_permissions) {
					notice('Permissions category could not be found.');
					return;
				}
				$hide_presence    = 1 - (intval($role_permissions['online']));
				if($role_permissions['default_collection']) {
					$r = q("select hash from pgrp where uid = %d and gname = '%s' limit 1",
						intval(local_channel()),
						dbesc( t('Friends') )
					);
					if(! $r) {

						AccessList::add(local_channel(), t('Friends'));
						AccessList::member_add(local_channel(),t('Friends'),$channel['channel_hash']);
						$r = q("select hash from pgrp where uid = %d and gname = '%s' limit 1",
							intval(local_channel()),
							dbesc( t('Friends') )
						);
					}
					if($r) {
						q("update channel set channel_default_group = '%s', channel_allow_gid = '%s', channel_allow_cid = '', channel_deny_gid = '', channel_deny_cid = '' where channel_id = %d",
							dbesc($r[0]['hash']),
							dbesc('<' . $r[0]['hash'] . '>'),
							intval(local_channel())
						);
					}
					else {
						notice( sprintf('Default access list \'%s\' not found. Please create and re-submit permission change.', t('Friends')) . EOL);
						return;
					}
				}
				// no default collection
				else {
					q("update channel set channel_default_group = '', channel_allow_gid = '', channel_allow_cid = '', channel_deny_gid = '', 
						channel_deny_cid = '' where channel_id = %d",
							intval(local_channel())
					);
				}

				if($role_permissions['perms_connect']) {	
					$x = Permissions::FilledPerms($role_permissions['perms_connect']);
					$str = Permissions::serialise($x);
					set_abconfig(local_channel(),$channel['channel_hash'],'system','my_perms',$str);

					$autoperms = intval($role_permissions['perms_auto']);
				}	

				if($role_permissions['limits']) {
					foreach($role_permissions['limits'] as $k => $v) {
						PermissionLimits::Set(local_channel(),$k,$v);
					}
				}
				if(array_key_exists('directory_publish',$role_permissions)) {
					$publish = intval($role_permissions['directory_publish']);
				}
			}
	
			set_pconfig(local_channel(),'system','hide_online_status',$hide_presence);
			set_pconfig(local_channel(),'system','permissions_role',$role);
		}

		// The post_comments permission is critical to privacy so we always allow you to set it, no matter what
		// permission role is in place.
		
		$post_comments   = array_key_exists('post_comments',$_POST) ? intval($_POST['post_comments']) : PERMS_SPECIFIC;
		PermissionLimits::Set(local_channel(),'post_comments',$post_comments);

		$publish          = (((x($_POST,'profile_in_directory')) && (intval($_POST['profile_in_directory']) == 1)) ? 1: 0);
		$username         = ((x($_POST,'username'))   ? notags(trim($_POST['username']))     : '');
		$timezone         = ((x($_POST,'timezone_select'))   ? notags(trim($_POST['timezone_select']))     : '');
		$defloc           = ((x($_POST,'defloc'))     ? notags(trim($_POST['defloc']))       : '');
		$openid           = ((x($_POST,'openid_url')) ? notags(trim($_POST['openid_url']))   : '');
		$maxreq           = ((x($_POST,'maxreq'))     ? intval($_POST['maxreq'])             : 0);
		$expire           = ((x($_POST,'expire'))     ? intval($_POST['expire'])             : 0);
		$evdays           = ((x($_POST,'evdays'))     ? intval($_POST['evdays'])             : 3);
		$photo_path       = ((x($_POST,'photo_path')) ? escape_tags(trim($_POST['photo_path'])) : '');
		$attach_path      = ((x($_POST,'attach_path')) ? escape_tags(trim($_POST['attach_path'])) : '');
	
		$channel_menu     = ((x($_POST['channel_menu'])) ? htmlspecialchars_decode(trim($_POST['channel_menu']),ENT_QUOTES) : '');
	
		$expire_items     = ((x($_POST,'expire_items')) ? intval($_POST['expire_items'])	 : 0);
		$expire_starred   = ((x($_POST,'expire_starred')) ? intval($_POST['expire_starred']) : 0);
		$expire_photos    = ((x($_POST,'expire_photos'))? intval($_POST['expire_photos'])	 : 0);
		$expire_network_only    = ((x($_POST,'expire_network_only'))? intval($_POST['expire_network_only'])	 : 0);
	
		$allow_location   = (((x($_POST,'allow_location')) && (intval($_POST['allow_location']) == 1)) ? 1: 0);
	
		$blocktags        = (((x($_POST,'blocktags')) && (intval($_POST['blocktags']) == 1)) ? 0: 1); // this setting is inverted!
		$unkmail          = (((x($_POST,'unkmail')) && (intval($_POST['unkmail']) == 1)) ? 1: 0);
		$cntunkmail       = ((x($_POST,'cntunkmail')) ? intval($_POST['cntunkmail']) : 0);
		$suggestme        = ((x($_POST,'suggestme')) ? intval($_POST['suggestme'])  : 0);  
//		$anymention       = ((x($_POST,'anymention')) ? intval($_POST['anymention'])  : 0);  
		$hyperdrive       = ((x($_POST,'hyperdrive')) ? intval($_POST['hyperdrive'])  : 0);  
		$activitypub      = ((x($_POST,'activitypub')) ? intval($_POST['activitypub'])  : 0);  

		$post_newfriend   = (($_POST['post_newfriend'] == 1) ? 1: 0);
		$post_joingroup   = (($_POST['post_joingroup'] == 1) ? 1: 0);
		$post_profilechange   = (($_POST['post_profilechange'] == 1) ? 1: 0);
		$adult            = (($_POST['adult'] == 1) ? 1 : 0);
		$defpermcat       = ((x($_POST,'defpermcat')) ? notags(trim($_POST['defpermcat'])) : 'default');
	
		$cal_first_day   = (((x($_POST,'first_day')) && intval($_POST['first_day']) >= 0 && intval($_POST['first_day']) < 7) ? intval($_POST['first_day']) : 0);
		$mailhost        = ((array_key_exists('mailhost',$_POST)) ? notags(trim($_POST['mailhost'])) : '');
		$profile_assign  = ((x($_POST,'profile_assign')) ? notags(trim($_POST['profile_assign'])) : '');

		// allow a permission change to over-ride the autoperms setting from the form

		if(! isset($autoperms)) {
			$autoperms        = ((x($_POST,'autoperms')) ? intval($_POST['autoperms'])  : 0);  
		}

	
		$pageflags = $channel['channel_pageflags'];
		$existing_adult = (($pageflags & PAGE_ADULT) ? 1 : 0);
		if($adult != $existing_adult)
			$pageflags = ($pageflags ^ PAGE_ADULT);
	
	
		$notify = 0;
	
		if(x($_POST,'notify1'))
			$notify += intval($_POST['notify1']);
		if(x($_POST,'notify2'))
			$notify += intval($_POST['notify2']);
		if(x($_POST,'notify3'))
			$notify += intval($_POST['notify3']);
		if(x($_POST,'notify4'))
			$notify += intval($_POST['notify4']);
		if(x($_POST,'notify5'))
			$notify += intval($_POST['notify5']);
		if(x($_POST,'notify6'))
			$notify += intval($_POST['notify6']);
		if(x($_POST,'notify7'))
			$notify += intval($_POST['notify7']);
		if(x($_POST,'notify8'))
			$notify += intval($_POST['notify8']);
	
	
		$vnotify = 0;
	
		if(x($_POST,'vnotify1'))
			$vnotify += intval($_POST['vnotify1']);
		if(x($_POST,'vnotify2'))
			$vnotify += intval($_POST['vnotify2']);
		if(x($_POST,'vnotify3'))
			$vnotify += intval($_POST['vnotify3']);
		if(x($_POST,'vnotify4'))
			$vnotify += intval($_POST['vnotify4']);
		if(x($_POST,'vnotify5'))
			$vnotify += intval($_POST['vnotify5']);
		if(x($_POST,'vnotify6'))
			$vnotify += intval($_POST['vnotify6']);
		if(x($_POST,'vnotify7'))
			$vnotify += intval($_POST['vnotify7']);
		if(x($_POST,'vnotify8'))
			$vnotify += intval($_POST['vnotify8']);
		if(x($_POST,'vnotify9'))
			$vnotify += intval($_POST['vnotify9']);
		if(x($_POST,'vnotify10'))
			$vnotify += intval($_POST['vnotify10']);
		if(x($_POST,'vnotify11') && is_site_admin())
			$vnotify += intval($_POST['vnotify11']);
		if(x($_POST,'vnotify12'))
			$vnotify += intval($_POST['vnotify12']);
		if(x($_POST,'vnotify13'))
			$vnotify += intval($_POST['vnotify13']);
		if(x($_POST,'vnotify14'))
			$vnotify += intval($_POST['vnotify14']);
		if(x($_POST,'vnotify15'))
			$vnotify += intval($_POST['vnotify15']);
		if(x($_POST,'vnotify16'))
			$vnotify += intval($_POST['vnotify16']);
	
		$always_show_in_notices = x($_POST,'always_show_in_notices') ? 1 : 0;
		
		$err = '';
	
		$name_change = false;
	
		if($username != $channel['channel_name']) {
			$name_change = true;
			require_once('include/channel.php');
			$err = validate_channelname($username);
			if($err) {
				notice($err);
				return;
			}
		}
	
		if($timezone != $channel['channel_timezone']) {
			if(strlen($timezone))
				date_default_timezone_set($timezone);
		}
	
		set_pconfig(local_channel(),'system','use_browser_location',$allow_location);
		set_pconfig(local_channel(),'system','suggestme', $suggestme);
		set_pconfig(local_channel(),'system','post_newfriend', $post_newfriend);
		set_pconfig(local_channel(),'system','post_joingroup', $post_joingroup);
		set_pconfig(local_channel(),'system','post_profilechange', $post_profilechange);
		set_pconfig(local_channel(),'system','blocktags',$blocktags);
		set_pconfig(local_channel(),'system','channel_menu',$channel_menu);
		set_pconfig(local_channel(),'system','vnotify',$vnotify);
		set_pconfig(local_channel(),'system','always_show_in_notices',$always_show_in_notices);
		set_pconfig(local_channel(),'system','evdays',$evdays);
		set_pconfig(local_channel(),'system','photo_path',$photo_path);
		set_pconfig(local_channel(),'system','attach_path',$attach_path);
		set_pconfig(local_channel(),'system','cal_first_day',$cal_first_day);
		set_pconfig(local_channel(),'system','default_permcat',$defpermcat);
		set_pconfig(local_channel(),'system','email_notify_host',$mailhost);
		set_pconfig(local_channel(),'system','profile_assign',$profile_assign);
//		set_pconfig(local_channel(),'system','anymention',$anymention);
		set_pconfig(local_channel(),'system','hyperdrive',$hyperdrive);
		set_pconfig(local_channel(),'system','activitypub',$activitypub);
		set_pconfig(local_channel(),'system','autoperms',$autoperms);
	
		$r = q("update channel set channel_name = '%s', channel_pageflags = %d, channel_timezone = '%s', channel_location = '%s', channel_notifyflags = %d, channel_max_anon_mail = %d, channel_max_friend_req = %d, channel_expire_days = %d $set_perms where channel_id = %d",
			dbesc($username),
			intval($pageflags),
			dbesc($timezone),
			dbesc($defloc),
			intval($notify),
			intval($unkmail),
			intval($maxreq),
			intval($expire),
			intval(local_channel())
		);   
		if($r)
			info( t('Settings updated.') . EOL);
	
		if(! is_null($publish)) {
			$r = q("UPDATE profile SET publish = %d WHERE is_default = 1 AND uid = %d",
				intval($publish),
				intval(local_channel())
			);
			$r = q("UPDATE xchan SET xchan_hidden = %d WHERE xchan_hash = '%s'",
				intval(1 - $publish),
				intval($channel['channel_hash'])
			);
		}
	
		if($name_change) {
			$r = q("update xchan set xchan_name = '%s', xchan_name_date = '%s' where xchan_hash = '%s'",
				dbesc($username),
				dbesc(datetime_convert()),
				dbesc($channel['channel_hash'])
			);
			$r = q("update profile set fullname = '%s' where uid = %d and is_default = 1",
				dbesc($username),
				intval($channel['channel_id'])
			);
		}
	
		Master::Summon( [ 'Directory', local_channel() ] );
	
		Libsync::build_sync_packet();
	
	
		if($email_changed && App::$config['system']['register_policy'] == REGISTER_VERIFY) {
	
			// FIXME - set to un-verified, blocked and redirect to logout
			// Q: Why? Are we verifying people or email addresses?
			// A: the policy is to verify email addresses
		}
	
		goaway(z_root() . '/settings' );
		return; // NOTREACHED
	}
			
	function get() {
	
		require_once('include/acl_selectors.php');
		require_once('include/permissions.php');


		$yes_no = [ t('No'), t('Yes') ];
	
	
		$p = q("SELECT * FROM profile WHERE is_default = 1 AND uid = %d LIMIT 1",
			intval(local_channel())
		);
		if(count($p))
			$profile = $p[0];
	
		load_pconfig(local_channel(),'expire');
	
		$channel = App::get_channel();
	
		$global_perms = Permissions::Perms();

		$permiss = [];
	
		$perm_opts = [
			array( t('Nobody except yourself'), 0),
			array( t('Only those you specifically allow'), PERMS_SPECIFIC), 
			array( t('Approved connections'), PERMS_CONTACTS),
			array( t('Any connections'), PERMS_PENDING),
			array( t('Anybody on this website'), PERMS_SITE),
			array( t('Anybody in this network'), PERMS_NETWORK),
			array( t('Anybody authenticated'), PERMS_AUTHED),
			array( t('Anybody on the internet'), PERMS_PUBLIC)
		];
	
		$limits = PermissionLimits::Get(local_channel());
		$anon_comments = get_config('system','anonymous_comments',true);
	
		foreach($global_perms as $k => $perm) {
			$options = [];
			$can_be_public = ((strstr($k,'view') || ($k === 'post_comments' && $anon_comments)) ? true : false);
			foreach($perm_opts as $opt) {
				if($opt[1] == PERMS_PUBLIC && (! $can_be_public))
					continue;
				$options[$opt[1]] = $opt[0];
			}
			if($k === 'view_stream') {
				$options = [$perm_opts[7][1] => $perm_opts[7][0]];
			}
			if($k === 'post_comments') {
				$comment_perms = [ $k, $perm, $limits[$k],'',$options ];
			}
			else {
				$permiss[] = array($k,$perm,$limits[$k],'',$options);			
			}
		}
		
		//		logger('permiss: ' . print_r($permiss,true));
	
		$username   = $channel['channel_name'];
		$nickname   = $channel['channel_address'];
		$timezone   = $channel['channel_timezone'];
		$notify     = $channel['channel_notifyflags'];
		$defloc     = $channel['channel_location'];
	
		$maxreq     = $channel['channel_max_friend_req'];
		$expire     = $channel['channel_expire_days'];
		$adult_flag = intval($channel['channel_pageflags'] & PAGE_ADULT);
		$sys_expire = get_config('system','default_expire_days');
	
		$hide_presence = intval(get_pconfig(local_channel(), 'system','hide_online_status'));

		$expire_items = get_pconfig(local_channel(), 'expire','items');
		$expire_items = (($expire_items===false)? '1' : $expire_items); // default if not set: 1
		
		$expire_notes = get_pconfig(local_channel(), 'expire','notes');
		$expire_notes = (($expire_notes===false)? '1' : $expire_notes); // default if not set: 1
	
		$expire_starred = get_pconfig(local_channel(), 'expire','starred');
		$expire_starred = (($expire_starred===false)? '1' : $expire_starred); // default if not set: 1

		
		$expire_photos = get_pconfig(local_channel(), 'expire','photos');
		$expire_photos = (($expire_photos===false)? '0' : $expire_photos); // default if not set: 0
	
		$expire_network_only = get_pconfig(local_channel(), 'expire','network_only');
		$expire_network_only = (($expire_network_only===false)? '0' : $expire_network_only); // default if not set: 0
	
	
		$suggestme = get_pconfig(local_channel(), 'system','suggestme');
		$suggestme = (($suggestme===false)? '0': $suggestme); // default if not set: 0
	
		$post_newfriend = get_pconfig(local_channel(), 'system','post_newfriend');
		$post_newfriend = (($post_newfriend===false)? '0': $post_newfriend); // default if not set: 0
	
		$post_joingroup = get_pconfig(local_channel(), 'system','post_joingroup');
		$post_joingroup = (($post_joingroup===false)? '0': $post_joingroup); // default if not set: 0
	
		$post_profilechange = get_pconfig(local_channel(), 'system','post_profilechange');
		$post_profilechange = (($post_profilechange===false)? '0': $post_profilechange); // default if not set: 0
	
		$blocktags  = get_pconfig(local_channel(),'system','blocktags');
		$blocktags = (($blocktags===false) ? '0' : $blocktags);
		
		$timezone = date_default_timezone_get();
	
		$opt_tpl = get_markup_template("field_checkbox.tpl");
		if(get_config('system','publish_all')) {
			$profile_in_dir = '<input type="hidden" name="profile_in_directory" value="1" />';
		}
		else {
			$profile_in_dir = replace_macros($opt_tpl,array(
				'$field' 	=> array('profile_in_directory', t('Publish your default profile in the network directory'), $profile['publish'], '', $yes_no),
			));
		}
	
		$suggestme = replace_macros($opt_tpl,array(
				'$field' 	=> array('suggestme',  t('Allow us to suggest you as a potential friend to new members?'), $suggestme, '', $yes_no),
	
		));
	
		$subdir = ((strlen(App::get_path())) ? '<br />' . t('or') . ' ' . z_root() . '/channel/' . $nickname : '');

		$webbie = $nickname . '@' . App::get_hostname();
		$intl_nickname = unpunify($nickname) . '@' . unpunify(App::get_hostname());
		
		$prof_addr = replace_macros(get_markup_template('channel_settings_header.tpl'),array(
			'$desc' => t('Your channel address is'),
			'$nickname' => (($intl_nickname === $webbie) ? $webbie : $intl_nickname . '&nbsp;(' . $webbie . ')'),
			'$compat' => t('Friends using compatible applications can use this address to connect with you.'),
			'$subdir' => $subdir,
			'$davdesc' => t('Your files/photos are accessible as a network drive via WebDAV at'),
			'$davpath' => z_root() . '/dav/' . $nickname,
			'$windows' => t('(Windows)'),
			'$other' => t('(other platforms)'),
			'$or' => t('or'),
			'$davspath' => 'davs://' . App::get_hostname() . '/dav/' . $nickname,
			'$basepath' => App::get_hostname()
		));


		$pcat = new Permcat(local_channel());
		$pcatlist = $pcat->listing();
		$permcats = [];
		if($pcatlist) {
			foreach($pcatlist as $pc) {
				$permcats[$pc['name']] = $pc['localname'];
			}
		}

		$default_permcat = get_pconfig(local_channel(),'system','default_permcat','default');

	
		$acl = new AccessControl($channel);
		$perm_defaults = $acl->get();
	
		$group_select = AccessList::select(local_channel(),$channel['channel_default_group']);
	
		require_once('include/menu.php');
		$m1 = menu_list(local_channel());
		$menu = false;
		if($m1) {
			$menu = array();
			$current = get_pconfig(local_channel(),'system','channel_menu');
			$menu[] = array('name' => '', 'selected' => ((! $current) ? true : false));
			foreach($m1 as $m) {
				$menu[] = array('name' => htmlspecialchars($m['menu_name'],ENT_COMPAT,'UTF-8'), 'selected' => (($m['menu_name'] === $current) ? ' selected="selected" ' : false));
			}
		}
	
		$evdays = get_pconfig(local_channel(),'system','evdays');
		if(! $evdays)
			$evdays = 3;
	
		$permissions_role = get_pconfig(local_channel(),'system','permissions_role');
		if(! $permissions_role)
			$permissions_role = 'custom';

		if(in_array($permissions_role,['forum','repository'])) {	
			$autoperms = replace_macros(get_markup_template('field_checkbox.tpl'), [
				'$field' =>  [ 'autoperms',t('Automatic membership approval'), ((get_pconfig(local_channel(),'system','autoperms',0)) ? 1 : 0), t('If enabled, connection requests will be approved without your interaction'), $yes_no ]]);
		}
		else {
			$autoperms  = '<input type="hidden" name="autoperms"  value="' . intval(get_pconfig(local_channel(),'system','autoperms'))  . '" />';
		}

		$hyperdrive = [ 'hyperdrive', t('Enable hyperdrive'), ((get_pconfig(local_channel(),'system','hyperdrive',true)) ? 1 : 0), t('Import public third-party conversations in which your connections participate.'), $yes_no ];

		if (get_config('system','activitypub')) {
			$apconfig = true;
			$activitypub = replace_macros(get_markup_template('field_checkbox.tpl'), [ '$field' => [ 'activitypub', t('Enable ActivityPub protocol'), ((get_pconfig(local_channel(),'system','activitypub',true)) ? 1 : 0), t('ActivityPub is not completely compatible with some of this software\'s features.'), $yes_no ]]);
		}
		else {
			$apconfig = false;
			$activitypub = '<input type="hidden" name="activitypub" value="1" >' . EOL;
		}

		$apheader = t('ActivityPub');
		$apdoc = t('ActivityPub is an emerging internet standard for social communications and is offered by a growing number of software applications. ') . t('It has a large number of existing users, however many applications disagree on the precise specifications for privacy, message delivery, and account migration - and there are many conflicting and even broken implementations. It is still very much a work in progress, and privacy and data integrity are much less predictable if you use it. ') . EOL . t('Your system administrator has allowed this experimental service on this website. You are free to make your own decision.');

		$permissions_set = (($permissions_role != 'custom') ? true : false);

		$perm_roles = PermissionRoles::roles();

		$vnotify = get_pconfig(local_channel(),'system','vnotify');
		$always_show_in_notices = get_pconfig(local_channel(),'system','always_show_in_notices');
		if($vnotify === false)
			$vnotify = (-1);

		$plugin = [ 'basic' => '', 'security' => '', 'notify' => '', 'misc' => '' ];
		call_hooks('channel_settings',$plugin);

		$disable_discover_tab = intval(get_config('system','disable_discover_tab',1)) == 1;
		$site_firehose = intval(get_config('system','site_firehose',0)) == 1;


		$o .= replace_macros(get_markup_template('settings.tpl'), [
			'$ptitle' 	=> t('Channel Settings'),	
			'$submit' 	=> t('Submit'),
			'$baseurl' => z_root(),
			'$uid' => local_channel(),
			'$form_security_token' => get_form_security_token("settings"),
			'$nickname_block' => $prof_addr,
			'$h_basic' 	=> t('Basic Settings'),
			'$username' => array('username',  t('Full name'), $username,''),
			'$email' 	=> array('email', t('Email Address'), $email, ''),
			'$timezone' => array('timezone_select' , t('Your timezone'), $timezone, t('This is important for showing the correct time on shared events'), get_timezones()),
			'$defloc'	=> array('defloc', t('Default post location'), $defloc, t('Optional geographical location to display on your posts')),
			'$allowloc' => array('allow_location', t('Obtain post location from your web browser or device'), ((get_pconfig(local_channel(),'system','use_browser_location')) ? 1 : ''), '', $yes_no),
			
			'$adult'    => array('adult', t('Adult content'), $adult_flag, t('This channel frequently or regularly publishes adult content. (Please tag any adult material and/or nudity with #NSFW)'), $yes_no),
	
			'$h_prv' 	=> t('Security and Privacy'),
			'$permissions_set' => $permissions_set,
			'$perms_set_msg' => t('Your permissions are already configured. Click to view/adjust'),
	
			'$hide_presence' => array('hide_presence', t('Hide my online presence'),$hide_presence, t('Prevents displaying in your profile that you are online'), $yes_no),
	
			'$permiss_arr' => $permiss,
			'$comment_perms' => $comment_perms,


			'$blocktags' => array('blocktags',t('Allow others to tag your posts'), 1-$blocktags, t('Often used by the community to retro-actively flag inappropriate content'), $yes_no),
	
			'$lbl_p2macro' => t('Channel Permission Limits'),
	
			'$expire' => array('expire',t('Expire other channel content after this many days'),$expire, t('0 or blank to use the website limit.') . ' ' . ((intval($sys_expire)) ? sprintf( t('This website expires after %d days.'),intval($sys_expire)) : t('This website does not expire imported content.')) . ' ' . t('The website limit takes precedence if lower than your limit.')),
			'$maxreq' 	=> array('maxreq', t('Maximum Friend Requests/Day:'), intval($channel['channel_max_friend_req']) , t('May reduce spam activity')),
			'$permissions' => t('Default Access List'),
			'$permdesc' => t("(click to open/close)"),
			'$aclselect' => populate_acl($perm_defaults, false, \Zotlabs\Lib\PermissionDescription::fromDescription(t('Use my default audience setting for the type of object published'))),
			'$profseltxt' => t('Profile to assign new connections'),
			'$profselect' => ((feature_enabled(local_channel(),'multi_profiles')) ? contact_profile_assign(get_pconfig(local_channel(),'system','profile_assign','')) : ''),

			'$allow_cid' => acl2json($perm_defaults['allow_cid']),
			'$allow_gid' => acl2json($perm_defaults['allow_gid']),
			'$deny_cid' => acl2json($perm_defaults['deny_cid']),
			'$deny_gid' => acl2json($perm_defaults['deny_gid']),
			'$suggestme' => $suggestme,
			'$group_select' => $group_select,
			'$role' => array('permissions_role' , t('Channel role and privacy'), $permissions_role, '', $perm_roles),
			'$defpermcat' => [ 'defpermcat', t('Default Permissions Group'), $default_permcat, '', $permcats ],	
			'$permcat_enable' => feature_enabled(local_channel(),'permcats'),
			'$profile_in_dir' => $profile_in_dir,
			'$hide_friends' => $hide_friends,
			'$hide_wall' => $hide_wall,
			'$unkmail' => $unkmail,		
			'$cntunkmail' 	=> array('cntunkmail', t('Maximum private messages per day from unknown people:'), intval($channel['channel_max_anon_mail']) ,t("Useful to reduce spamming")),
			
			'$autoperms' => $autoperms,			
//			'$anymention' => $anymention,			
			'$hyperdrive' => $hyperdrive,
			'$activitypub' => $activitypub,
			'$apconfig' => $apconfig,
			'$apheader' => $apheader,
			'$apdoc' => $apdoc,
			'$h_not' 	=> t('Notifications'),
			'$activity_options' => t('By default post a status message when:'),
			'$post_newfriend' => array('post_newfriend',  t('accepting a friend request'), $post_newfriend, '', $yes_no),
			'$post_joingroup' => array('post_joingroup',  t('joining a forum/community'), $post_joingroup, '', $yes_no),
			'$post_profilechange' => array('post_profilechange',  t('making an <em>interesting</em> profile change'), $post_profilechange, '', $yes_no),
			'$lbl_not' 	=> t('Send a notification email when:'),
			'$notify1'	=> array('notify1', t('You receive a connection request'), ($notify & NOTIFY_INTRO), NOTIFY_INTRO, '', $yes_no),
//			'$notify2'	=> array('notify2', t('Your connections are confirmed'), ($notify & NOTIFY_CONFIRM), NOTIFY_CONFIRM, '', $yes_no),
			'$notify3'	=> array('notify3', t('Someone writes on your profile wall'), ($notify & NOTIFY_WALL), NOTIFY_WALL, '', $yes_no),
			'$notify4'	=> array('notify4', t('Someone writes a followup comment'), ($notify & NOTIFY_COMMENT), NOTIFY_COMMENT, '', $yes_no),
//			'$notify5'	=> array('notify5', t('You receive a private message'), ($notify & NOTIFY_MAIL), NOTIFY_MAIL, '', $yes_no),
//			'$notify6'  => array('notify6', t('You receive a friend suggestion'), ($notify & NOTIFY_SUGGEST), NOTIFY_SUGGEST, '', $yes_no),
			'$notify7'  => array('notify7', t('You are tagged in a post'), ($notify & NOTIFY_TAGSELF), NOTIFY_TAGSELF, '', $yes_no),
//			'$notify8'  => array('notify8', t('You are poked/prodded/etc. in a post'), ($notify & NOTIFY_POKE), NOTIFY_POKE, '', $yes_no),
			
			'$notify9'  => array('notify9', t('Someone likes your post/comment'), ($notify & NOTIFY_LIKE), NOTIFY_LIKE, '', $yes_no),
			
	
			'$lbl_vnot' 	=> t('Show visual notifications including:'),
	
			'$vnotify1'	=> array('vnotify1', t('Unseen network activity'), ($vnotify & VNOTIFY_NETWORK), VNOTIFY_NETWORK, '', $yes_no),
			'$vnotify2'	=> array('vnotify2', t('Unseen channel activity'), ($vnotify & VNOTIFY_CHANNEL), VNOTIFY_CHANNEL, '', $yes_no),
//			'$vnotify3'	=> array('vnotify3', t('Unseen private messages'), ($vnotify & VNOTIFY_MAIL), VNOTIFY_MAIL, t('Recommended'), $yes_no),
			'$vnotify4'	=> array('vnotify4', t('Upcoming events'), ($vnotify & VNOTIFY_EVENT), VNOTIFY_EVENT, '', $yes_no),
			'$vnotify5'	=> array('vnotify5', t('Events today'), ($vnotify & VNOTIFY_EVENTTODAY), VNOTIFY_EVENTTODAY, '', $yes_no),
			'$vnotify6'  => array('vnotify6', t('Upcoming birthdays'), ($vnotify & VNOTIFY_BIRTHDAY), VNOTIFY_BIRTHDAY, t('Not available in all themes'), $yes_no),
			'$vnotify7'  => array('vnotify7', t('System (personal) notifications'), ($vnotify & VNOTIFY_SYSTEM), VNOTIFY_SYSTEM, '', $yes_no),
			'$vnotify8'  => array('vnotify8', t('System info messages'), ($vnotify & VNOTIFY_INFO), VNOTIFY_INFO, t('Recommended'), $yes_no),
			'$vnotify9'  => array('vnotify9', t('System critical alerts'), ($vnotify & VNOTIFY_ALERT), VNOTIFY_ALERT, t('Recommended'), $yes_no),
			'$vnotify10'  => array('vnotify10', t('New connections'), ($vnotify & VNOTIFY_INTRO), VNOTIFY_INTRO, t('Recommended'), $yes_no),
			'$vnotify11'  => ((is_site_admin()) ? array('vnotify11', t('System Registrations'), ($vnotify & VNOTIFY_REGISTER), VNOTIFY_REGISTER, '', $yes_no) : array()),
//			'$vnotify12'  => array('vnotify12', t('Unseen shared files'), ($vnotify & VNOTIFY_FILES), VNOTIFY_FILES, '', $yes_no),
			'$vnotify13'  => (($disable_discover_tab && !$site_firehose) ? array() : array('vnotify13', t('Unseen public activity'), ($vnotify & VNOTIFY_PUBS), VNOTIFY_PUBS, '', $yes_no)),
			'$vnotify14'	=> array('vnotify14', t('Unseen likes and dislikes'), ($vnotify & VNOTIFY_LIKE), VNOTIFY_LIKE, '', $yes_no),
			'$vnotify15'	=> array('vnotify15', t('Unseen forum posts'), ($vnotify & VNOTIFY_FORUMS), VNOTIFY_FORUMS, '', $yes_no),
			'$vnotify16'	=> ((is_site_admin()) ? array('vnotify16', t('Reported content'), ($vnotify & VNOTIFY_REPORTS), VNOTIFY_REPORTS, '', $yes_no) : [] ),
			'$mailhost' => [ 'mailhost', t('Email notification hub (hostname)'), get_pconfig(local_channel(),'system','email_notify_host',App::get_hostname()), sprintf( t('If your channel is mirrored to multiple locations, set this to your preferred location. This will prevent duplicate email notifications. Example: %s'),App::get_hostname()) ],
			'$always_show_in_notices'  => array('always_show_in_notices', t('Show new wall posts, private messages and connections under Notices'), $always_show_in_notices, 1, '', $yes_no),
	
			'$evdays' => array('evdays', t('Notify me of events this many days in advance'), $evdays, t('Must be greater than 0')),			
			'$basic_addon' => $plugin['basic'],
			'$sec_addon'  => $plugin['security'],
			'$notify_addon' => $plugin['notify'],
			'$misc_addon' => $plugin['misc'],
			'$lbl_time' => t('Date and time'),
			'$miscdoc' => t('This section is reserved for use by optional addons and apps to provide additional settings.'), 
			'$h_advn' => t('Advanced Account/Page Type Settings'),
			'$h_descadvn' => t('Change the behaviour of this account for special situations'),
			'$pagetype' => $pagetype,
			'$lbl_misc' => t('Miscellaneous'),
			'$photo_path' => array('photo_path', t('Default photo upload folder name'), get_pconfig(local_channel(),'system','photo_path'), t('%Y - current year, %m -  current month')),
			'$attach_path' => array('attach_path', t('Default file upload folder name'), get_pconfig(local_channel(),'system','attach_path'), t('%Y - current year, %m -  current month')),
			'$menus' => $menu,			
			'$menu_desc' => t('Personal menu to display in your channel pages'),
			'$removeme' => t('Remove Channel'),
			'$removechannel' => t('Remove this channel.'),
			'$cal_first_day' => array('first_day', t('Calendar week begins on'), intval(get_pconfig(local_channel(),'system','cal_first_day')), t('This varies by country/culture'),
				[   0 => t('Sunday'),
					1 => t('Monday'),
					2 => t('Tuesday'),
					3 => t('Wednesday'),
					4 => t('Thursday'),
					5 => t('Friday'),
					6 => t('Saturday')
				]),
		]);
	
		call_hooks('settings_form',$o);	
		return $o;
	}
}
