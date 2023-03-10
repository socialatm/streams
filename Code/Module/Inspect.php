<?php

namespace Code\Module;

use App;
use Code\Lib\ObjCache;
use Code\Web\Controller;
use Code\Lib\Activity;

class Inspect extends Controller
{
    public function get()
    {
        $output = EMPTY_STR;

        if (!is_site_admin()) {
            notice(t('Permission denied.') . EOL);
            return $output;
        }

        if (argc() > 2) {
            $item_type = argv(1);
            $item_id = argv(2);
        } elseif (argc() > 1) {
            $item_type = 'item';
            $item_id = argv(1);
        }

        if (!$item_id) {
            App::$error = 404;
            notice(t('Item not found.') . EOL);
        }

        if ($item_type === 'item') {
            $r = q(
                "select * from item where uuid = '%s' or id = %d ",
                dbesc($item_id),
                intval($item_id)
            );

            if ($r) {
                xchan_query($r);
                $items = fetch_post_tags($r);
            }

            if (!$items) {
                return $output;
            }

            foreach ($items as $item) {
                if ($item['obj']) {
                    $item['obj'] = json_decode($item['obj'], true);
                }
                if ($item['target']) {
                    $item['target'] = json_decode($item['target'], true);
                }
                if ($item['attach']) {
                    $item['attach'] = json_decode($item['attach'], true);
                }

                $output .= '<pre>' . print_array($item) . '</pre>' . EOL . EOL;

                $output .= '<pre>' . escape_tags(json_encode(Activity::encode_activity($item, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>' . EOL . EOL;

                $output .= '<pre>' . escape_tags(json_encode(json_decode(ObjCache::Get($item['mid'])),
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>' . EOL . EOL;
            }
        }

        if ($item_type === 'xchan') {
            $items = q(
                "select * from xchan left join hubloc on xchan_hash = hubloc_hash where (hubloc_hash = '%s' or hubloc_addr = '%s') and hubloc_deleted = 0 ",
                dbesc($item_id),
                dbesc($item_id)
            );

            if (!$items) {
                return $output;
            }

            foreach ($items as $item) {
                $output .= '<pre>' . print_array($item) . '</pre>' . EOL . EOL;
            }
        }
        
        return $output;
    }
}
