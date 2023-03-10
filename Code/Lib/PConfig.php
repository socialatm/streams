<?php

namespace Code\Lib;

use App;

/**
 * @brief Class for handling channel specific configurations.
 *
 * <b>PConfig</b> is used for channel specific configurations and takes a
 * <i>channel_id</i> as identifier. It stores for example which features are
 * enabled per channel. The storage is of size MEDIUMTEXT.
 *
 * @code{.php}$var = Code\Lib\PConfig::Get('uid', 'category', 'key');
 * // with default value for non existent key
 * $var = Code\Lib\PConfig::Get('uid', 'category', 'unsetkey', 'defaultvalue');@endcode
 *
 * The old (deprecated?) way to access a PConfig value is:
 * @code{.php}$var = get_pconfig(local_channel(), 'category', 'key');@endcode
 */
class PConfig
{

    /**
     * @brief Loads all configuration values of a channel into a cached storage.
     *
     * All configuration values of the given channel are stored in global cache
     * which is available under the global variable App::$config[$uid].
     *
     * @param string $uid
     *  The channel_id
     * @return void|false Nothing or false if $uid is null or false
     */
    public static function Load($uid)
    {
        if (is_null($uid) || $uid === false) {
            return false;
        }

        if (! is_array(App::$config)) {
            btlogger('App::$config not an array');
        }

        if (! array_key_exists($uid, App::$config)) {
            App::$config[$uid] = [];
        }

        if (! is_array(App::$config[$uid])) {
            btlogger('App::$config[$uid] not an array: ' . $uid);
        }

        $r = q(
            "SELECT * FROM pconfig WHERE uid = %d",
            intval($uid)
        );

        if ($r) {
            foreach ($r as $rr) {
                $k = $rr['k'];
                $c = $rr['cat'];
                if (! array_key_exists($c, App::$config[$uid])) {
                    App::$config[$uid][$c] = [];
                    App::$config[$uid][$c]['config_loaded'] = true;
                }
                App::$config[$uid][$c][$k] = $rr['v'];
            }
        }
    }

    /**
     * @brief Get a particular channel's config variable given the category name
     * ($family) and a key.
     *
     * Get a particular channel's config value from the given category ($family)
     * and the $key from a cached storage in App::$config[$uid].
     *
     * Returns false if not set.
     *
     * @param string $uid
     *  The channel_id
     * @param string $family
     *  The category of the configuration value
     * @param string $key
     *  The configuration key to query
     * @param mixed $default (optional, default false)
     *  Default value to return if key does not exist
     * @return mixed Stored value or false if it does not exist
     */
    public static function Get($uid, $family, $key, $default = false)
    {

        if (is_null($uid) || $uid === false) {
            return $default;
        }

        if (! array_key_exists($uid, App::$config)) {
            self::Load($uid);
        }

        if ((! array_key_exists($family, App::$config[$uid])) || (! array_key_exists($key, App::$config[$uid][$family]))) {
            return $default;
        }

        return unserialise(App::$config[$uid][$family][$key]);
    }

    /**
     * @brief Sets a configuration value for a channel.
     *
     * Stores a config value ($value) in the category ($family) under the key ($key)
     * for the channel_id $uid.
     *
     * @param string $uid
     *  The channel_id
     * @param string $family
     *  The category of the configuration value
     * @param string $key
     *  The configuration key to set
     * @param mixed $value
     *  The value to store
     * @return mixed Stored $value or false
     */
    public static function Set($uid, $family, $key, $value)
    {

        // this catches subtle errors where this function has been called
        // with local_channel() when not logged in (which returns false)
        // and throws an error in array_key_exists below.
        // we provide a function backtrace in the logs so that we can find
        // and fix the calling function.

        if (is_null($uid) || $uid === false) {
            btlogger('UID is FALSE!', LOGGER_NORMAL, LOG_ERR);
            return false;
        }

        // manage array value
        $dbvalue = ((is_array($value))  ? serialise($value) : $value);
        $dbvalue = ((is_bool($dbvalue)) ? intval($dbvalue)  : $dbvalue);

        if (self::Get($uid, $family, $key) === false) {
            if (! array_key_exists($uid, App::$config)) {
                App::$config[$uid] = [];
            }
            if (! array_key_exists($family, App::$config[$uid])) {
                App::$config[$uid][$family] = [];
            }

            $ret = q(
                "INSERT INTO pconfig ( uid, cat, k, v ) VALUES ( %d, '%s', '%s', '%s' ) ",
                intval($uid),
                dbesc($family),
                dbesc($key),
                dbesc($dbvalue)
            );
        } else {
            $ret = q(
                "UPDATE pconfig SET v = '%s' WHERE uid = %d and cat = '%s' AND k = '%s'",
                dbesc($dbvalue),
                intval($uid),
                dbesc($family),
                dbesc($key)
            );
        }

        // keep a separate copy for all variables which were
        // set in the life of this page. We need this to
        // synchronise channel clones.

        if (! array_key_exists('transient', App::$config[$uid])) {
            App::$config[$uid]['transient'] = [];
        }
        if (! array_key_exists($family, App::$config[$uid]['transient'])) {
            App::$config[$uid]['transient'][$family] = [];
        }

        App::$config[$uid][$family][$key] = $value;
        App::$config[$uid]['transient'][$family][$key] = $value;

        if ($ret) {
            return $value;
        }

        return $ret;
    }


    /**
     * @brief Deletes the given key from the channel's configuration.
     *
     * Removes the configured value from the stored cache in App::$config[$uid]
     * and removes it from the database.
     *
     * @param string $uid
     *  The channel_id
     * @param string $family
     *  The category of the configuration value
     * @param string $key
     *  The configuration key to delete
     * @return mixed
     */
    public static function Delete($uid, $family, $key)
    {

        if (is_null($uid) || $uid === false) {
            return false;
        }

        $ret = false;

        if (
            array_key_exists($uid, App::$config)
            && is_array(App::$config['uid'])
            && array_key_exists($family, App::$config['uid'])
            && array_key_exists($key, App::$config[$uid][$family])
        ) {
            unset(App::$config[$uid][$family][$key]);
        }

        $ret = q(
            "DELETE FROM pconfig WHERE uid = %d AND cat = '%s' AND k = '%s'",
            intval($uid),
            dbesc($family),
            dbesc($key)
        );

        return $ret;
    }
}
