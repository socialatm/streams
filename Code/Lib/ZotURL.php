<?php

namespace Code\Lib;

use Code\Web\HTTPSig;
use Code\Lib\Channel;
use Code\Lib\Url;

class ZotURL
{

    public static function fetch($url, $channel, $hub = null)
    {

        $ret = [ 'success' => false ];

        if (strpos($url, 'x-zot:') !== 0) {
            return $ret;
        }


        if (! $url) {
            return $ret;
        }

        $portable_url = substr($url, 6);
        $u = explode('/', $portable_url);
        $portable_id = $u[0];

        $hosts = self::lookup($portable_id, $hub);

        if (! $hosts) {
            return $ret;
        }

        foreach ($hosts as $h) {
            $newurl = $h . '/id/' . $portable_url;

            $m = parse_url($newurl);

            if ($channel && $m) {
                $headers = [
					'Accept'           => 'application/x-nomad+json, application/x-zot+json', 
					'Content-Type'     => 'application/x-nomad+json',
                    'X-Zot-Token'      => random_string(),
                    'Digest'           => HTTPSig::generate_digest_header($data),
                    'Host'             => $m['host'],
                    '(request-target)' => 'post ' . get_request_string($newurl)
                ];
                $h = HTTPSig::create_sig($headers, $channel['channel_prvkey'], Channel::url($channel), false);
            } else {
                $h = [ 'Accept: application/x-nomad+json, application/x-zot+json' ];
            }

            $x = Url::get($newurl, [ 'headers' => $h  ]);
            if ($x['success']) {
                return $x;
            }
        }

        return $ret;
    }

    public static function is_zoturl($s)
    {

        if (strpos($url, 'x-zot:') === 0) {
            return true;
        }
        return false;
    }


    public static function lookup($portable_id, $hub)
    {

        $r = q(
            "select * from hubloc left join site on hubloc_url = site_url where hubloc_hash = '%s' and hubloc_deleted = 0 and site_dead = 0 order by hubloc_primary desc",
            dbesc($portable_id)
        );

        if (! $r) {
            // extend to network lookup

            $path = '/q/' . $portable_id;

            // first check sending hub since they have recently communicated with this object

            if ($hub) {
                $x = Url::get($hub['hubloc_url'] . $path);
                $u = self::parse_response($x);
                if ($u) {
                    return $u;
                }
            }

            // If this fails, fallback on directory servers

            return false;
        }
        return ids_to_array($r, 'hubloc_url');
    }


    public static function parse_response($arr)
    {
        if (! $arr['success']) {
            return false;
        }
        $a = json_decode($arr['body'], true);
        if ($a['success'] && array_key_exists('results', $a) && is_array($a['results']) && count($a['results'])) {
            foreach ($a['results'] as $b) {
                $m = discover_by_webbie($b);
                if ($m) {
                    return([ $b ]);
                }
            }
        }
        return false;
    }
}

