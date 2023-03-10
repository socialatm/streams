<?php

namespace Code\Module;

use App;
use Code\Lib\Libprofile;
use Code\Web\Controller;

require_once('include/conversation.php');

class Block extends Controller
{

    public function init()
    {

        $which = argv(1);
        $profile = 0;
        Libprofile::load($which, $profile);

        if (App::$profile['profile_uid']) {
            head_set_icon(App::$profile['thumb']);
        }
    }


    public function get()
    {

        if (!perm_is_allowed(App::$profile['profile_uid'], get_observer_hash(), 'view_pages')) {
            notice(t('Permission denied.') . EOL);
            return '';
        }

        if (argc() < 3) {
            notice(t('Invalid item.') . EOL);
            return '';
        }

        $channel_address = argv(1);
        $page_id = argv(2);

        $u = q(
            "select channel_id from channel where channel_address = '%s' limit 1",
            dbesc($channel_address)
        );

        if (!$u) {
            notice(t('Channel not found.') . EOL);
            return '';
        }

        if ($_REQUEST['rev']) {
            $revision = " and revision = " . intval($_REQUEST['rev']) . " ";
        } else {
            $revision = " order by revision desc ";
        }

        require_once('include/security.php');
        $sql_options = item_permissions_sql($u[0]['channel_id']);

        $r = q(
            "select item.* from item left join iconfig on item.id = iconfig.iid
			where item.uid = %d and iconfig.cat = 'system' and iconfig.v = '%s' and iconfig.k = 'BUILDBLOCK' and 
			item_type = %d $sql_options $revision limit 1",
            intval($u[0]['channel_id']),
            dbesc($page_id),
            intval(ITEM_TYPE_BLOCK)
        );

        if (!$r) {
            // Check again with no permissions clause to see if it is a permissions issue

            $x = q(
                "select item.* from item left join iconfig on item.id = iconfig.iid
			where item.uid = %d and iconfig.cat = 'system' and iconfig.v = '%s' and iconfig.k = 'BUILDBLOCK' and 
			item_type = %d $revision limit 1",
                intval($u[0]['channel_id']),
                dbesc($page_id),
                intval(ITEM_TYPE_BLOCK)
            );
            if ($x) {
                // Yes, it's there. You just aren't allowed to see it.
                notice(t('Permission denied.') . EOL);
            } else {
                notice(t('Page not found.') . EOL);
            }
            return '';
        }

        xchan_query($r);
        $r = fetch_post_tags($r);

        return prepare_page($r[0]);
    }
}
