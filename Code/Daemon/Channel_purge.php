<?php

namespace Code\Daemon;

use Code\Lib\Channel;

class Channel_purge implements DaemonInterface
{

    /** @noinspection PhpUnusedParameterInspection */
    public function run(int $argc, array $argv): void
    {

        cli_startup();

        $channel = Channel::from_id($argv[1], true);

        if (!$channel) {
            return;
        }
        if (!$channel['channel_removed']) {
            return;
        }

        do {
            $r = q(
                "select id from item where uid = %d and item_deleted = 0 limit 1000",
                intval($channel['channel_id'])
            );
            if ($r) {
                foreach ($r as $rv) {
                    drop_item($rv['id']);
                }
            }
        } while ($r);
    }
}
