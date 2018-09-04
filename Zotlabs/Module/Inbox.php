<?php
namespace Zotlabs\Module;

use Zotlabs\Web\HTTPSig;
use Zotlabs\Lib\ActivityStreams;
use Zotlabs\Lib\Activity;
use Zotlabs\Web\Controller;

require_once('include/items.php');

class Inbox extends Controller {

	function post() {

		$sys_disabled = ((get_config('system','disable_discover_tab') || get_config('system','disable_activitypub_discover_tab'))  ? true : false);

		$is_public = false;

		if(argc() == 1 || argv(1) === '[public]') {
			$is_public = true;
		}
		else {
			$channels = [ channelx_by_nick(argv(1)) ];
		}

		$data = file_get_contents('php://input');
		if(! $data)
			return;

		logger('inbox_activity: ' . jindent($data), LOGGER_DATA);

		HTTPSig::verify($data);

		$AS = new ActivityStreams($data);

		//logger('debug: ' . $AS->debug());

		if(! $AS->is_valid())
			return;

		$observer_hash = $AS->actor['id'];
		if(! $observer_hash)
			return;

		if(is_array($AS->actor) && array_key_exists('id',$AS->actor)) {
			Activity::actor_store($AS->actor['id'],$AS->actor);
		}

		if(is_array($AS->obj) && in_array($AS->obj['type'],[ 'Application','Group','Service','Person','Service' ])) {
			Activity::actor_store($AS->obj['id'],$AS->obj);
		}

		if(is_array($AS->obj['actor']) && array_key_exists('id',$AS->obj['actor']) && $AS->obj['actor']['id'] !== $AS->actor['id']) {
			Activity::actor_store($AS->obj['actor']['id'],$AS->obj['actor']);
		}

		if($is_public) {

			if($AS->type === 'Follow' && $AS->obj && $AS->obj['type'] === 'Person') {
				$channels = q("SELECT * from channel where channel_address = '%s' and channel_removed = 0 ",
				dbesc(basename($AS->obj['id']))
				);
			}
			else {
				// deliver to anybody following $AS->actor

				$channels = q("SELECT * from channel where channel_id in ( SELECT abook_channel from abook left join xchan on abook_xchan = xchan_hash WHERE xchan_network = 'activitypub' and xchan_hash = '%s' ) and channel_removed = 0 ",
					dbesc($observer_hash)
				);
			}

			if($channels === false)
				$channels = [];

			if(in_array(ACTIVITY_PUBLIC_INBOX,$AS->recips)) {

				// look for channels with send_stream = PERMS_PUBLIC

				$r = q("select * from channel where channel_id in (select uid from pconfig where cat = 'perm_limits' and k = 'send_stream' and v = '1' ) and channel_removed = 0 ");
				if($r) {
					$channels = array_merge($channels,$r);
				}

				if(! $sys_disabled) {
					$channels[] = get_sys_channel();
				}

			}

		}

		if(! $channels) {
			logger('no deliveries on this site');
			return;
		}

		$saved_recips = [];
		foreach( [ 'to', 'cc', 'audience' ] as $x ) {
			if(array_key_exists($x,$AS->data)) {
				$saved_recips[$x] = $AS->data[$x];
			}
		}
		$AS->set_recips($saved_recips);


		foreach($channels as $channel) {

			switch($AS->type) {
				case 'Follow':
					if($AS->obj & $AS->obj['type'] === 'Person') {
						// do follow activity
						Activity::follow($channel,$AS);
						continue;
					}
					break;
				case 'Accept':
					if($AS->obj & $AS->obj['type'] === 'Follow') {
						// do follow activity
						Activity::follow($channel,$AS);
						continue;
					}
					break;

				case 'Reject':

				default:
					break;

			}


			// These activities require permissions		

			$item = null;

			switch($AS->type) {
				case 'Create':
				case 'Update':
				case 'Like':
				case 'Dislike':
				case 'Announce':
					$item = Activity::decode_note($AS);
					break;
				case 'Undo':
					if($AS->obj & $AS->obj['type'] === 'Follow') {
						// do unfollow activity
						Activity::unfollow($channel,$AS);
						break;
					}
				case 'Delete':
				case 'Add':
				case 'Remove':
				default:
					break;

			}

			if($item) {
				Activity::store($channel,$observer_hash,$AS,$item);
			}

		}


		http_status_exit(200,'OK');
	}

	function get() {

	}

}



