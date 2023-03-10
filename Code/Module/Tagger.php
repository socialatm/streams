<?php

namespace Code\Module;

use App;
use Code\Lib\Libsync;
use Code\Web\Controller;
use Code\Lib\Channel;

require_once('include/security.php');
require_once('include/bbcode.php');


class Tagger extends Controller
{

    public function get()
    {

        if (!local_channel()) {
            return;
        }

        $sys = Channel::get_system();

        $observer_hash = get_observer_hash();
        //strip html-tags
        $term = notags(trim($_GET['term']));
        //check if empty
        if (!$term) {
            return;
        }

        $item_id = ((argc() > 1) ? notags(trim(argv(1))) : 0);

        logger('tagger: tag ' . $term . ' item ' . $item_id);

        $r = q(
            "select * from item where id = %d and uid = %d limit 1",
            intval($item_id),
            intval(local_channel())
        );

        if (!$r) {
            $r = q(
                "select * from item where id = %d and uid = %d limit 1",
                intval($item_id),
                intval($sys['channel_id'])
            );
            if (!$r) {
                $r = q(
                    "select * from item where id = %d and item_private = 0 and item_wall = 1",
                    intval($item_id)
                );
            }
            if ($r && local_channel() && (!Channel::is_system(local_channel()))) {
                $r = [copy_of_pubitem(App::get_channel(), $r[0]['mid'])];
                $item_id = (($r) ? $r[0]['id'] : 0);
            }
        }

        if (!$r) {
            notice(t('Post not found.') . EOL);
            return;
        }

        $r = q(
            "SELECT * FROM item left join xchan on xchan_hash = author_xchan WHERE id = %d and uid = %d LIMIT 1",
            intval($item_id),
            intval(local_channel())
        );

        if ((!$item_id) || (!$r)) {
            logger('tagger: no item ' . $item_id);
            return;
        }

        $item = $r[0];

        $owner_uid = $item['uid'];

        switch ($item['resource_type']) {
            case 'photo':
                $targettype = ACTIVITY_OBJ_PHOTO;
                $post_type = t('photo');
                break;
            case 'event':
                $targgettype = ACTIVITY_OBJ_EVENT;
                $post_type = t('event');
                break;
            default:
                $targettype = ACTIVITY_OBJ_NOTE;
                $post_type = t('post');
                if ($item['mid'] != $item['parent_mid']) {
                    $post_type = t('comment');
                }
                break;
        }


        $clean_term = trim($term, '"\' ');

        $links = array(array('rel' => 'alternate', 'type' => 'text/html',
            'href' => z_root() . '/display/?mid=' . gen_link_id($item['mid'])));

        $target = json_encode(array(
            'type' => $targettype,
            'id' => $item['mid'],
            'link' => $links,
            'title' => $item['title'],
            'content' => $item['body'],
            'created' => $item['created'],
            'edited' => $item['edited'],
            'author' => array(
                'name' => $item['xchan_name'],
                'address' => $item['xchan_addr'],
                'guid' => $item['xchan_guid'],
                'guid_sig' => $item['xchan_guid_sig'],
                'link' => array(
                    array('rel' => 'alternate', 'type' => 'text/html', 'href' => $item['xchan_url']),
                    array('rel' => 'photo', 'type' => $item['xchan_photo_mimetype'], 'href' => $item['xchan_photo_m'])),
            ),
        ));

        $tagid = z_root() . '/search?tag=' . $clean_term;
        $objtype = ACTIVITY_OBJ_TAGTERM;

        $obj = json_encode(array(
            'type' => $objtype,
            'id' => $tagid,
            'link' => array(array('rel' => 'alternate', 'type' => 'text/html', 'href' => $tagid)),
            'title' => $clean_term,
            'content' => $clean_term
        ));

        $bodyverb = t('%1$s tagged %2$s\'s %3$s with %4$s');

        // saving here for reference
        // also check out x22d5 and x2317 and x0d6b and x0db8 and x24d0 and xff20 !!!

        $termlink = html_entity_decode('&#x22d5;') . '[zrl=' . z_root() . '/search?tag=' . urlencode($clean_term) . ']' . $clean_term . '[/zrl]';

        $channel = App::get_channel();

        $arr = [];

        $arr['owner_xchan'] = $item['owner_xchan'];
        $arr['author_xchan'] = $channel['channel_hash'];

        $arr['item_origin'] = 1;
        $arr['item_wall'] = ((intval($item['item_wall'])) ? 1 : 0);

        $ulink = '[zrl=' . $channel['xchan_url'] . ']' . $channel['channel_name'] . '[/zrl]';
        $alink = '[zrl=' . $item['xchan_url'] . ']' . $item['xchan_name'] . '[/zrl]';
        $plink = '[zrl=' . $item['plink'] . ']' . $post_type . '[/zrl]';

        $arr['body'] = sprintf($bodyverb, $ulink, $alink, $plink, $termlink);

        $arr['verb'] = ACTIVITY_TAG;
        $arr['tgt_type'] = $targettype;
        $arr['target'] = $target;
        $arr['obj_type'] = $objtype;
        $arr['obj'] = $obj;
        $arr['parent_mid'] = $item['mid'];
        store_item_tag($item['uid'], $item['id'], TERM_OBJ_POST, TERM_COMMUNITYTAG, $clean_term, $tagid);
        $ret = post_activity_item($arr);

        if ($ret['success']) {
            Libsync::build_sync_packet(
                local_channel(),
                [
                    'item' => [encode_item($ret['activity'], true)]
                ]
            );
        }

        killme();
    }
}
