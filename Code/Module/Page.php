<?php

namespace Code\Module;

use App;
use Code\Render\Comanche;
use Code\Web\Controller;
use Code\Lib\Libprofile;
use Code\Lib\Channel;

require_once('include/conversation.php');


class Page extends Controller
{

    public function init()
    {
        // We need this to make sure the channel theme is always loaded.

        $which = argv(1);
        $profile = 0;
        Libprofile::load($which, $profile);


        if (App::$profile['profile_uid']) {
            head_set_icon(App::$profile['thumb']);
        }

        // load the item here in the init function because we need to extract
        // the page layout and initialise the correct theme.


        $observer = App::get_observer();
        $ob_hash = (($observer) ? $observer['xchan_hash'] : '');


        // perm_is_allowed is denied unconditionally when 'site blocked to unauthenticated members'.
        // This bypasses that restriction for sys channel (public) content

        if ((!perm_is_allowed(App::$profile['profile_uid'], $ob_hash, 'view_pages')) && (!Channel::is_system(App::$profile['profile_uid']))) {
            notice(t('Permission denied.') . EOL);
            return;
        }

        if (argc() < 3) {
            notice(t('Invalid item.') . EOL);
            return;
        }

        $channel_address = argv(1);

        // Always look first for the page name prefixed by the observer language; for instance page/nickname/de/foo
        // followed by page/nickname/foo if that is not found.
        // If your browser language is de and you want to access the default in this case,
        // use page/nickname/-/foo to over-ride the language and access only the page with pagelink of 'foo'

        $page_name = '';
        $ignore_language = false;

        for ($x = 2; $x < argc(); $x++) {
            if ($page_name === '' && argv($x) === '-') {
                $ignore_language = true;
                continue;
            }
            if ($page_name) {
                $page_name .= '/';
            }
            $page_name .= argv($x);
        }


        // The page link title was stored in a urlencoded format
        // php or the browser may/will have decoded it, so re-encode it for our search

        $page_id = urlencode($page_name);
        $lang_page_id = urlencode(App::$language . '/' . $page_name);

        $u = q(
            "select channel_id from channel where channel_address = '%s' limit 1",
            dbesc($channel_address)
        );

        if (!$u) {
            notice(t('Channel not found.') . EOL);
            return;
        }

        if ($_REQUEST['rev']) {
            $revision = " and revision = " . intval($_REQUEST['rev']) . " ";
        } else {
            $revision = " order by revision desc ";
        }

        require_once('include/security.php');
        $sql_options = item_permissions_sql($u[0]['channel_id']);

        $r = null;

        if (!$ignore_language) {
            $r = q(
                "select item.* from item left join iconfig on item.id = iconfig.iid
				where item.uid = %d and iconfig.cat = 'system' and iconfig.v = '%s' and item.item_delayed = 0 
				and iconfig.k = 'WEBPAGE' and item_type = %d  
				$sql_options $revision limit 1",
                intval($u[0]['channel_id']),
                dbesc($lang_page_id),
                intval(ITEM_TYPE_WEBPAGE)
            );
        }
        if (!$r) {
            $r = q(
                "select item.* from item left join iconfig on item.id = iconfig.iid
				where item.uid = %d and iconfig.cat = 'system' and iconfig.v = '%s' and item.item_delayed = 0 
				and iconfig.k = 'WEBPAGE' and item_type = %d 
				$sql_options $revision limit 1",
                intval($u[0]['channel_id']),
                dbesc($page_id),
                intval(ITEM_TYPE_WEBPAGE)
            );
        }
        if (!$r) {
            // no webpage by that name, but we do allow you to load/preview a layout using this module. Try that.
            $r = q(
                "select item.* from item left join iconfig on item.id = iconfig.iid
				where item.uid = %d and iconfig.cat = 'system' and iconfig.v = '%s' and item.item_delayed = 0 
				and iconfig.k = 'PDL' AND item_type = %d $sql_options $revision limit 1",
                intval($u[0]['channel_id']),
                dbesc($page_id),
                intval(ITEM_TYPE_PDL)
            );
        }
        if (!$r) {
            // Check again with no permissions clause to see if it is a permissions issue

            $x = q(
                "select item.* from item left join iconfig on item.id = iconfig.iid
			where item.uid = %d and iconfig.cat = 'system' and iconfig.v = '%s' and item.item_delayed = 0 
			and iconfig.k = 'WEBPAGE' and item_type = %d $revision limit 1",
                intval($u[0]['channel_id']),
                dbesc($page_id),
                intval(ITEM_TYPE_WEBPAGE)
            );

            if ($x) {
                // Yes, it's there. You just aren't allowed to see it.
                notice(t('Permission denied.') . EOL);
            } else {
                notice(t('Page not found.') . EOL);
            }
            return;
        }

        if ($r[0]['title']) {
            App::$page['title'] = escape_tags($r[0]['title']);
        }

        if ($r[0]['item_type'] == ITEM_TYPE_PDL) {
            App::$comanche = new Comanche();
            App::$comanche->parse($r[0]['body']);
            App::$pdl = $r[0]['body'];
        } elseif ($r[0]['layout_mid']) {
            $l = q(
                "select body from item where mid = '%s' and uid = %d limit 1",
                dbesc($r[0]['layout_mid']),
                intval($u[0]['channel_id'])
            );

            if ($l) {
                App::$comanche = new Comanche();
                App::$comanche->parse($l[0]['body']);
                App::$pdl = $l[0]['body'];
            }
        }

        App::$data['webpage'] = $r;
    }

    public function get()
    {

        $r = App::$data['webpage'];
        if (!$r) {
            return '';
        }

        if ($r[0]['item_type'] == ITEM_TYPE_PDL) {
            $r[0]['body'] = t('Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.');
            $r[0]['mimetype'] = 'text/plain';
            $r[0]['title'] = '';
        }

        xchan_query($r);
        $r = fetch_post_tags($r);

        if ($r[0]['mimetype'] === 'application/x-pdl') {
            App::$page['pdl_content'] = true;
        }

        return prepare_page($r[0]);
    }
}
