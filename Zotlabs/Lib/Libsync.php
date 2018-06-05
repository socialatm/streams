<?php

namespace Zotlabs\Lib;

use Zotlabs\Lib\Libzot;



class Libsync {

	/**
	 * @brief Builds and sends a sync packet.
	 *
	 * Send a zot packet to all hubs where this channel is duplicated, refreshing
	 * such things as personal settings, channel permissions, address book updates, etc.
	 *
	 * @param int $uid (optional) default 0
	 * @param array $packet (optional) default null
	 * @param boolean $groups_changed (optional) default false
	 */
	static function build_sync_packet($uid = 0, $packet = null, $groups_changed = false) {

		logger('build_sync_packet');

		$keychange = (($packet && array_key_exists('keychange',$packet)) ? true : false);
		if($keychange) {
			logger('keychange sync');
		}

		if(! $uid)
			$uid = local_channel();

		if(! $uid)
			return;

		$r = q("select * from channel where channel_id = %d limit 1",
			intval($uid)
		);
		if(! $r)
			return;

		$channel = $r[0];

		// don't provide these in the export 

		unset($channel['channel_active']);
		unset($channel['channel_password']);
		unset($channel['channel_salt']);


		if(intval($channel['channel_removed']))
			return;

		$h = q("select hubloc.*, site.site_crypto from hubloc left join site on site_url = hubloc_url where hubloc_hash = '%s' and hubloc_deleted = 0",
			dbesc(($keychange) ? $packet['keychange']['old_hash'] : $channel['channel_hash'])
		);

		if(! $h)
			return;

		$synchubs = array();

		foreach($h as $x) {
			if($x['hubloc_host'] == \App::get_hostname())
				continue;

			$y = q("select site_dead from site where site_url = '%s' limit 1",
				dbesc($x['hubloc_url'])
			);

			if((! $y) || ($y[0]['site_dead'] == 0))
				$synchubs[] = $x;
		}

		if(! $synchubs)
			return;

		$r = q("select xchan_guid, xchan_guid_sig from xchan where xchan_hash  = '%s' limit 1",
			dbesc($channel['channel_hash'])
		);
		if(! $r)
			return;

		$env_recips = array();
		$env_recips[] = array('guid' => $r[0]['xchan_guid'],'guid_sig' => $r[0]['xchan_guid_sig']);

		if($packet)
			logger('packet: ' . print_r($packet, true),LOGGER_DATA, LOG_DEBUG);

		$info = (($packet) ? $packet : array());
		$info['type'] = 'channel_sync';
		$info['encoding'] = 'red'; // note: not zot, this packet is very platform specific
		$info['relocate'] = ['channel_address' => $channel['channel_address'], 'url' => z_root() ];

		if(array_key_exists($uid,App::$config) && array_key_exists('transient',App::$config[$uid])) {
			$settings = App::$config[$uid]['transient'];
			if($settings) {
				$info['config'] = $settings;
			}
		}

		if($channel) {
			$info['channel'] = array();
			foreach($channel as $k => $v) {

				// filter out any joined tables like xchan

				if(strpos($k,'channel_') !== 0)
					continue;

				// don't pass these elements, they should not be synchronised


				$disallowed = [
					'channel_id','channel_account_id','channel_primary','channel_address',
					'channel_deleted','channel_removed','channel_system'
				];

				if(! $keychange) {
					$disallowed[] = 'channel_prvkey';
				}

				if(in_array($k,$disallowed))
					continue;

				$info['channel'][$k] = $v;
			}
		}

		if($groups_changed) {
			$r = q("select hash as collection, visible, deleted, gname as name from groups where uid = %d",
				intval($uid)
			);
			if($r)
				$info['collections'] = $r;

			$r = q("select groups.hash as collection, group_member.xchan as member from groups left join group_member on groups.id = group_member.gid where group_member.uid = %d",
				intval($uid)
			);
			if($r)
				$info['collection_members'] = $r;
		}

		$interval = ((get_config('system','delivery_interval') !== false)
			? intval(get_config('system','delivery_interval')) : 2 );

		logger('Packet: ' . print_r($info,true), LOGGER_DATA, LOG_DEBUG);

		$total = count($synchubs);

		foreach($synchubs as $hub) {
			$hash = random_string();
			$n = Libzot::build_packet($channel,'notify',$env_recips,json_encode($info),$hub['hubloc_sitekey'],$hub['site_crypto'],$hash);
			queue_insert(array(
				'hash'       => $hash,
				'account_id' => $channel['channel_account_id'],
				'channel_id' => $channel['channel_id'],
				'posturl'    => $hub['hubloc_callback'],
				'notify'     => $n,
				'msg'        => json_encode($info)
			));


			$x = q("select count(outq_hash) as total from outq where outq_delivered = 0");
			if(intval($x[0]['total']) > intval(get_config('system','force_queue_threshold',300))) {
				logger('immediate delivery deferred.', LOGGER_DEBUG, LOG_INFO);
				update_queue_item($hash);
				continue;
			}


			\Zotlabs\Daemon\Master::Summon(array('Deliver', $hash));
			$total = $total - 1;

			if($interval && $total)
				@time_sleep_until(microtime(true) + (float) $interval);
		}
	}

	/**
	 * @brief
	 *
	 * @param array $sender
	 * @param array $arr
	 * @param array $deliveries
	 * @return array
	 */

	static function process_channel_sync_delivery($sender, $arr, $deliveries) {

		require_once('include/import.php');

		/** @FIXME this will sync red structures (channel, pconfig and abook).
			Eventually we need to make this application agnostic. */

		$result = [];

		$keychange = ((array_key_exists('keychange',$arr)) ? true : false);

		foreach ($deliveries as $d) {
			$r = q("select * from channel where channel_hash = '%s' limit 1",
				dbesc(($keychange) ? $arr['keychange']['old_hash'] : $d['hash'])
			);

			if (! $r) {
				$result[] = array($d['hash'],'not found');
				continue;
			}

			$channel = $r[0];

			$max_friends = service_class_fetch($channel['channel_id'],'total_channels');
			$max_feeds = account_service_class_fetch($channel['channel_account_id'],'total_feeds');

			if($channel['channel_hash'] != $sender['hash']) {
				logger('Possible forgery. Sender ' . $sender['hash'] . ' is not ' . $channel['channel_hash']);
				$result[] = array($d['hash'],'channel mismatch',$channel['channel_name'],'');
				continue;
			}

			if($keychange) {
				// verify the keychange operation
				if(! Libzot::verify($arr['channel']['channel_pubkey'],$arr['keychange']['new_sig'],$channel['channel_prvkey'])) {
					logger('sync keychange: verification failed');
					continue;
				}

				$sig = Libzot::sign($channel['channel_guid'],$arr['channel']['channel_prvkey']);
				$hash = Libzot::make_xchan_hash($channel['channel_guid'],$arr['channel']['channel_pubkey']);


				$r = q("update channel set channel_prvkey = '%s', channel_pubkey = '%s', channel_guid_sig = '%s',
					channel_hash = '%s' where channel_id = %d",
					dbesc($arr['channel']['channel_prvkey']),
					dbesc($arr['channel']['channel_pubkey']),
					dbesc($sig),
					dbesc($hash),
					intval($channel['channel_id'])
				);
				if(! $r) {
					logger('keychange sync: channel update failed');
					continue;
 				}

				$r = q("select * from channel where channel_id = %d",
					intval($channel['channel_id'])
				);

				if(! $r) {
					logger('keychange sync: channel retrieve failed');
					continue;
				}

				$channel = $r[0];

				$h = q("select * from hubloc where hubloc_hash = '%s' and hubloc_url = '%s' ",
					dbesc($arr['keychange']['old_hash']),
					dbesc(z_root())
				);

				if($h) {
					foreach($h as $hv) {
						$hv['hubloc_guid_sig'] = $sig;
						$hv['hubloc_hash']     = $hash;
						$hv['hubloc_url_sig']  = Libzot::sign(z_root(),$channel['channel_prvkey']);
						hubloc_store_lowlevel($hv);
					}
				}

				$x = q("select * from xchan where xchan_hash = '%s' ",
					dbesc($arr['keychange']['old_hash'])
				);

				$check = q("select * from xchan where xchan_hash = '%s'",
					dbesc($hash)
				);

				if(($x) && (! $check)) {
					$oldxchan = $x[0];
					foreach($x as $xv) {
						$xv['xchan_guid_sig']  = $sig;
						$xv['xchan_hash']      = $hash;
						$xv['xchan_pubkey']    = $channel['channel_pubkey'];
						xchan_store_lowlevel($xv);
						$newxchan = $xv;
					}
				}

				$a = q("select * from abook where abook_xchan = '%s' and abook_self = 1",
					dbesc($arr['keychange']['old_hash'])
				);

				if($a) {
					q("update abook set abook_xchan = '%s' where abook_id = %d",
						dbesc($hash),
						intval($a[0]['abook_id'])
					);
				}

				xchan_change_key($oldxchan,$newxchan,$arr['keychange']);

				// keychange operations can end up in a confused state if you try and sync anything else
				// besides the channel keys, so ignore any other packets.

				continue;
			}

			// if the clone is active, so are we

			if(substr($channel['channel_active'],0,10) !== substr(datetime_convert(),0,10)) {
				q("UPDATE channel set channel_active = '%s' where channel_id = %d",
					dbesc(datetime_convert()),
					intval($channel['channel_id'])
				);
			}

			if(array_key_exists('config',$arr) && is_array($arr['config']) && count($arr['config'])) {
				foreach($arr['config'] as $cat => $k) {
					foreach($arr['config'][$cat] as $k => $v)
						set_pconfig($channel['channel_id'],$cat,$k,$v);
				}
			}

			if(array_key_exists('obj',$arr) && $arr['obj'])
				sync_objs($channel,$arr['obj']);

			if(array_key_exists('likes',$arr) && $arr['likes'])
				import_likes($channel,$arr['likes']);

			if(array_key_exists('app',$arr) && $arr['app'])
				sync_apps($channel,$arr['app']);
	
			if(array_key_exists('chatroom',$arr) && $arr['chatroom'])
				sync_chatrooms($channel,$arr['chatroom']);

			if(array_key_exists('conv',$arr) && $arr['conv'])
				import_conv($channel,$arr['conv']);

			if(array_key_exists('mail',$arr) && $arr['mail'])
				sync_mail($channel,$arr['mail']);
	
			if(array_key_exists('event',$arr) && $arr['event'])
				sync_events($channel,$arr['event']);

			if(array_key_exists('event_item',$arr) && $arr['event_item'])
				sync_items($channel,$arr['event_item'],((array_key_exists('relocate',$arr)) ? $arr['relocate'] : null));

			if(array_key_exists('item',$arr) && $arr['item'])
				sync_items($channel,$arr['item'],((array_key_exists('relocate',$arr)) ? $arr['relocate'] : null));
	
			// deprecated, maintaining for a few months for upward compatibility
			// this should sync webpages, but the logic is a bit subtle

			if(array_key_exists('item_id',$arr) && $arr['item_id'])
				sync_items($channel,$arr['item_id']);

			if(array_key_exists('menu',$arr) && $arr['menu'])
				sync_menus($channel,$arr['menu']);
	
			if(array_key_exists('file',$arr) && $arr['file'])
				sync_files($channel,$arr['file']);

			if(array_key_exists('wiki',$arr) && $arr['wiki'])
				sync_items($channel,$arr['wiki'],((array_key_exists('relocate',$arr)) ? $arr['relocate'] : null));

			if(array_key_exists('channel',$arr) && is_array($arr['channel']) && count($arr['channel'])) {

				$remote_channel = $arr['channel'];
				$remote_channel['channel_id'] = $channel['channel_id'];
				translate_channel_perms_inbound($remote_channel);


				if(array_key_exists('channel_pageflags',$arr['channel']) && intval($arr['channel']['channel_pageflags'])) {
					// These flags cannot be sync'd.
					// remove the bits from the incoming flags.

					// These correspond to PAGE_REMOVED and PAGE_SYSTEM on redmatrix

					if($arr['channel']['channel_pageflags'] & 0x8000)
						$arr['channel']['channel_pageflags'] = $arr['channel']['channel_pageflags'] - 0x8000;
					if($arr['channel']['channel_pageflags'] & 0x1000)
						$arr['channel']['channel_pageflags'] = $arr['channel']['channel_pageflags'] - 0x1000;
				}

				$disallowed = [
					'channel_id',        'channel_account_id',  'channel_primary',   'channel_prvkey',
					'channel_address',   'channel_notifyflags', 'channel_removed',   'channel_deleted',
					'channel_system',    'channel_r_stream',    'channel_r_profile', 'channel_r_abook',
					'channel_r_storage', 'channel_r_pages',     'channel_w_stream',  'channel_w_wall',
					'channel_w_comment', 'channel_w_mail',      'channel_w_like',    'channel_w_tagwall',
					'channel_w_chat',    'channel_w_storage',   'channel_w_pages',   'channel_a_republish',
					'channel_a_delegate'
				];

				$clean = array();
				foreach($arr['channel'] as $k => $v) {
					if(in_array($k,$disallowed))
						continue;
					$clean[$k] = $v;
				}
				if(count($clean)) {
					foreach($clean as $k => $v) {
						$r = dbq("UPDATE channel set " . dbesc($k) . " = '" . dbesc($v)
							. "' where channel_id = " . intval($channel['channel_id']) );
					}
				}
			}

			if(array_key_exists('abook',$arr) && is_array($arr['abook']) && count($arr['abook'])) {
				$total_friends = 0;
				$total_feeds = 0;

				$r = q("select abook_id, abook_feed from abook where abook_channel = %d",
					intval($channel['channel_id'])
				);
				if($r) {
					// don't count yourself
					$total_friends = ((count($r) > 0) ? count($r) - 1 : 0);
					foreach($r as $rr)
						if(intval($rr['abook_feed']))
							$total_feeds ++;
				}


				$disallowed = array('abook_id','abook_account','abook_channel','abook_rating','abook_rating_text','abook_not_here');

				foreach($arr['abook'] as $abook) {

					$abconfig = null;

					if(array_key_exists('abconfig',$abook) && is_array($abook['abconfig']) && count($abook['abconfig']))
						$abconfig = $abook['abconfig'];

					if(! array_key_exists('abook_blocked',$abook)) {
						// convert from redmatrix
						$abook['abook_blocked']     = (($abook['abook_flags'] & 0x0001) ? 1 : 0);
						$abook['abook_ignored']     = (($abook['abook_flags'] & 0x0002) ? 1 : 0);
						$abook['abook_hidden']      = (($abook['abook_flags'] & 0x0004) ? 1 : 0);
						$abook['abook_archived']    = (($abook['abook_flags'] & 0x0008) ? 1 : 0);
						$abook['abook_pending']     = (($abook['abook_flags'] & 0x0010) ? 1 : 0);
						$abook['abook_unconnected'] = (($abook['abook_flags'] & 0x0020) ? 1 : 0);
						$abook['abook_self']        = (($abook['abook_flags'] & 0x0080) ? 1 : 0);
						$abook['abook_feed']        = (($abook['abook_flags'] & 0x0100) ? 1 : 0);
					}

					$clean = array();
					if($abook['abook_xchan'] && $abook['entry_deleted']) {
						logger('Removing abook entry for ' . $abook['abook_xchan']);

						$r = q("select abook_id, abook_feed from abook where abook_xchan = '%s' and abook_channel = %d and abook_self = 0 limit 1",
							dbesc($abook['abook_xchan']),
							intval($channel['channel_id'])
						);
						if($r) {
							contact_remove($channel['channel_id'],$r[0]['abook_id']);
							if($total_friends)
								$total_friends --;
							if(intval($r[0]['abook_feed']))
								$total_feeds --;
						}
						continue;
					}

					// Perform discovery if the referenced xchan hasn't ever been seen on this hub.
					// This relies on the undocumented behaviour that red sites send xchan info with the abook
					// and import_author_xchan will look them up on all federated networks

					if($abook['abook_xchan'] && $abook['xchan_addr']) {
						$h = Libzot::get_hublocs($abook['abook_xchan']);
						if(! $h) {
							$xhash = import_author_xchan(encode_item_xchan($abook));
							if(! $xhash) {
								logger('Import of ' . $abook['xchan_addr'] . ' failed.');
								continue;
							}
						}
					}

					foreach($abook as $k => $v) {
						if(in_array($k,$disallowed) || (strpos($k,'abook') !== 0))
							continue;
						$clean[$k] = $v;
					}

					if(! array_key_exists('abook_xchan',$clean))
						continue;

					if(array_key_exists('abook_instance',$clean) && $clean['abook_instance'] && strpos($clean['abook_instance'],z_root()) === false) {
						$clean['abook_not_here'] = 1;
					}


					$r = q("select * from abook where abook_xchan = '%s' and abook_channel = %d limit 1",
						dbesc($clean['abook_xchan']),
						intval($channel['channel_id'])
					);

					// make sure we have an abook entry for this xchan on this system

					if(! $r) {
						if($max_friends !== false && $total_friends > $max_friends) {
							logger('total_channels service class limit exceeded');
							continue;
						}
						if($max_feeds !== false && intval($clean['abook_feed']) && $total_feeds > $max_feeds) {
							logger('total_feeds service class limit exceeded');
							continue;
						}
						abook_store_lowlevel(
							[
								'abook_xchan'   => $clean['abook_xchan'],
								'abook_account' => $channel['channel_account_id'],
								'abook_channel' => $channel['channel_id']
							]
						);
						$total_friends ++;
						if(intval($clean['abook_feed']))
							$total_feeds ++;
					}

					if(count($clean)) {
						foreach($clean as $k => $v) {
							if($k == 'abook_dob')
								$v = dbescdate($v);

							$r = dbq("UPDATE abook set " . dbesc($k) . " = '" . dbesc($v)
							. "' where abook_xchan = '" . dbesc($clean['abook_xchan']) . "' and abook_channel = " . intval($channel['channel_id']));
						}
					}

					// This will set abconfig vars if the sender is using old-style fixed permissions
					// using the raw abook record as passed to us. New-style permissions will fall through
					// and be set using abconfig

					translate_abook_perms_inbound($channel,$abook);

					if($abconfig) {
						/// @fixme does not handle sync of del_abconfig
						foreach($abconfig as $abc) {
							set_abconfig($channel['channel_id'],$abc['xchan'],$abc['cat'],$abc['k'],$abc['v']);
						}
					}
				}
			}

			// sync collections (privacy groups) oh joy...

			if(array_key_exists('collections',$arr) && is_array($arr['collections']) && count($arr['collections'])) {
				$x = q("select * from groups where uid = %d",
					intval($channel['channel_id'])
				);
				foreach($arr['collections'] as $cl) {
					$found = false;
					if($x) {
						foreach($x as $y) {
							if($cl['collection'] == $y['hash']) {
								$found = true;
								break;
							}
						}
						if($found) {
							if(($y['gname'] != $cl['name'])
								|| ($y['visible'] != $cl['visible'])
								|| ($y['deleted'] != $cl['deleted'])) {
								q("update groups set gname = '%s', visible = %d, deleted = %d where hash = '%s' and uid = %d",
									dbesc($cl['name']),
									intval($cl['visible']),
									intval($cl['deleted']),
									dbesc($cl['collection']),
									intval($channel['channel_id'])
								);
							}
							if(intval($cl['deleted']) && (! intval($y['deleted']))) {
								q("delete from group_member where gid = %d",
									intval($y['id'])
								);
							}
						}
					}
					if(! $found) {
						$r = q("INSERT INTO groups ( hash, uid, visible, deleted, gname )
							VALUES( '%s', %d, %d, %d, '%s' ) ",
							dbesc($cl['collection']),
							intval($channel['channel_id']),
							intval($cl['visible']),
							intval($cl['deleted']),
							dbesc($cl['name'])
						);
					}

					// now look for any collections locally which weren't in the list we just received.
					// They need to be removed by marking deleted and removing the members.
					// This shouldn't happen except for clones created before this function was written.

					if($x) {
						$found_local = false;
						foreach($x as $y) {
							foreach($arr['collections'] as $cl) {
								if($cl['collection'] == $y['hash']) {
									$found_local = true;
									break;
								}
							}
							if(! $found_local) {
								q("delete from group_member where gid = %d",
									intval($y['id'])
								);
								q("update groups set deleted = 1 where id = %d and uid = %d",
									intval($y['id']),
									intval($channel['channel_id'])
								);
							}
						}
					}
				}

				// reload the group list with any updates
				$x = q("select * from groups where uid = %d",
					intval($channel['channel_id'])
				);

				// now sync the members

				if(array_key_exists('collection_members', $arr)
					&& is_array($arr['collection_members'])
					&& count($arr['collection_members'])) {

					// first sort into groups keyed by the group hash
					$members = array();
					foreach($arr['collection_members'] as $cm) {
						if(! array_key_exists($cm['collection'],$members))
							$members[$cm['collection']] = array();

						$members[$cm['collection']][] = $cm['member'];
					}

					// our group list is already synchronised
					if($x) {
						foreach($x as $y) {
	
							// for each group, loop on members list we just received
							if(isset($y['hash']) && isset($members[$y['hash']])) {
								foreach($members[$y['hash']] as $member) {
									$found = false;
									$z = q("select xchan from group_member where gid = %d and uid = %d and xchan = '%s' limit 1",
										intval($y['id']),
										intval($channel['channel_id']),
										dbesc($member)
									);
									if($z)
										$found = true;
	
									// if somebody is in the group that wasn't before - add them
	
									if(! $found) {
										q("INSERT INTO group_member (uid, gid, xchan)
											VALUES( %d, %d, '%s' ) ",
											intval($channel['channel_id']),
											intval($y['id']),
											dbesc($member)
										);
									}
								}
							}
	
							// now retrieve a list of members we have on this site
							$m = q("select xchan from group_member where gid = %d and uid = %d",
								intval($y['id']),
								intval($channel['channel_id'])
							);
							if($m) {
								foreach($m as $mm) {
									// if the local existing member isn't in the list we just received - remove them
									if(! in_array($mm['xchan'],$members[$y['hash']])) {
										q("delete from group_member where xchan = '%s' and gid = %d and uid = %d",
											dbesc($mm['xchan']),
											intval($y['id']),
											intval($channel['channel_id'])
										);
									}
								}
							}
						}
					}
				}
			}

			if(array_key_exists('profile',$arr) && is_array($arr['profile']) && count($arr['profile'])) {

				$disallowed = array('id','aid','uid','guid');

				foreach($arr['profile'] as $profile) {
	
					$x = q("select * from profile where profile_guid = '%s' and uid = %d limit 1",
						dbesc($profile['profile_guid']),
						intval($channel['channel_id'])
					);
					if(! $x) {
						profile_store_lowlevel(
							[
								'aid'          => $channel['channel_account_id'],
								'uid'          => $channel['channel_id'],
								'profile_guid' => $profile['profile_guid'],
							]
						);
	
						$x = q("select * from profile where profile_guid = '%s' and uid = %d limit 1",
							dbesc($profile['profile_guid']),
							intval($channel['channel_id'])
						);
						if(! $x)
							continue;
					}
					$clean = array();
					foreach($profile as $k => $v) {
						if(in_array($k,$disallowed))
							continue;

						if($profile['is_default'] && in_array($k,['photo','thumb']))
							continue;

						if($k === 'name')
							$clean['fullname'] = $v;
						elseif($k === 'with')
							$clean['partner'] = $v;
						elseif($k === 'work')
							$clean['employment'] = $v;
						elseif(array_key_exists($k,$x[0]))
							$clean[$k] = $v;

						/**
						 * @TODO
						 * We also need to import local photos if a custom photo is selected
						 */

						if((strpos($profile['thumb'],'/photo/profile/l/') !== false) || intval($profile['is_default'])) {
							$profile['photo'] = z_root() . '/photo/profile/l/' . $channel['channel_id'];
							$profile['thumb'] = z_root() . '/photo/profile/m/' . $channel['channel_id'];
						}
						else {
							$profile['photo'] = z_root() . '/photo/' . basename($profile['photo']);
							$profile['thumb'] = z_root() . '/photo/' . basename($profile['thumb']);
						}
					}

					if(count($clean)) {
						foreach($clean as $k => $v) {
							$r = dbq("UPDATE profile set " . TQUOT . dbesc($k) . TQUOT . " = '" . dbesc($v)
							. "' where profile_guid = '" . dbesc($profile['profile_guid'])
							. "' and uid = " . intval($channel['channel_id']));
						}
					}
				}
			}

			$addon = ['channel' => $channel, 'data' => $arr];
			/**
			 * @hooks process_channel_sync_delivery
			 *   Called when accepting delivery of a 'sync packet' containing structure and table updates from a channel clone.
			 *   * \e array \b channel
			 *   * \e array \b data
			 */
			call_hooks('process_channel_sync_delivery', $addon);

			// we should probably do this for all items, but usually we only send one.

			if(array_key_exists('item',$arr) && is_array($arr['item'][0])) {
				$DR = new \Zotlabs\Lib\DReport(z_root(),$d['hash'],$d['hash'],$arr['item'][0]['message_id'],'channel sync processed');
				$DR->addto_recipient($channel['channel_name'] . ' <' . channel_reddress($channel) . '>');
			}
			else
				$DR = new \Zotlabs\Lib\DReport(z_root(),$d['hash'],$d['hash'],'sync packet','channel sync delivered');

			$result[] = $DR->get();
		}

		return $result;
	}

}