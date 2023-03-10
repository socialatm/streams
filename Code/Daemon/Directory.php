<?php

namespace Code\Daemon;

use Code\Lib\Libzotdir;
use Code\Lib\Channel;

class Directory implements DaemonInterface
{

    public function run(int $argc, array $argv): void
    {

        if ($argc < 2) {
            return;
        }

        $force = false;
        $pushall = true;

        if ($argc > 2) {
            if ($argv[2] === 'force') {
                $force = true;
            }
            if ($argv[2] === 'nopush') {
                $pushall = false;
            }
        }

        logger('directory update', LOGGER_DEBUG);

        $channel = Channel::from_id($argv[1]);
        if (! $channel) {
            return;
        }

        // update the local directory - was optional, but now done regardless

        Libzotdir::local_dir_update($argv[1], $force);

        q(
            "update channel set channel_dirdate = '%s' where channel_id = %d",
            dbesc(datetime_convert()),
            intval($channel['channel_id'])
        );

        // Now update all the connections
        if ($pushall) {
            Run::Summon([ 'Notifier','refresh_all',$channel['channel_id'] ]);
        }
    }
}
