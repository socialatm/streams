<?php

namespace Code\Module;

use App;
use Code\Web\Controller;
use Code\Render\Theme;
use Code\Lib\Url;

require_once('include/import.php');

/**
 * @brief Module for importing items.
 *
 * Import existing posts and content from an export file.
 */
class Import_items extends Controller
{

    public function post()
    {

        if (!local_channel()) {
            return;
        }

        check_form_security_token_redirectOnErr('/import_items', 'import_items');

        $data = null;

        $src = $_FILES['filename']['tmp_name'];
        $filename = basename($_FILES['filename']['name']);
        $filesize = intval($_FILES['filename']['size']);
        $filetype = $_FILES['filename']['type'];

        if ($src) {
            // This is OS specific and could also fail if your tmpdir isn't very large
            // mostly used for Diaspora which exports gzipped files.

            if (strpos($filename, '.gz')) {
                @rename($src, $src . '.gz');
                @system('gunzip ' . escapeshellarg($src . '.gz'));
            }

            if ($filesize) {
                $data = @file_get_contents($src);
            }
            unlink($src);
        }

        if (!$src) {
            $old_address = ((x($_REQUEST, 'old_address')) ? $_REQUEST['old_address'] : '');

            if (!$old_address) {
                logger('Nothing to import.');
                notice(t('Nothing to import.') . EOL);
                return;
            }

            $email = ((x($_REQUEST, 'email')) ? $_REQUEST['email'] : '');
            $password = ((x($_REQUEST, 'password')) ? $_REQUEST['password'] : '');

            $year = ((x($_REQUEST, 'year')) ? $_REQUEST['year'] : '');

            $channelname = substr($old_address, 0, strpos($old_address, '@'));
            $servername = substr($old_address, strpos($old_address, '@') + 1);

            $scheme = 'https://';
            $api_path = '/api/red/channel/export/items?f=&zap_compat=1&channel=' . $channelname . '&year=' . intval($year);
            $opts = ['http_auth' => $email . ':' . $password];
            $url = $scheme . $servername . $api_path;
            $ret = Url::get($url, $opts);
            if (!$ret['success']) {
                $ret = Url::get('http://' . $servername . $api_path, $opts);
            }
            if ($ret['success']) {
                $data = $ret['body'];
            } else {
                notice(t('Unable to download data from old server') . EOL);
            }
        }

        if (!$data) {
            logger('Empty file.');
            notice(t('Imported file is empty.') . EOL);
            return;
        }

        $data = json_decode($data, true);

        //logger('import: data: ' . print_r($data,true));
        //print_r($data);

        if (!is_array($data)) {
            return;
        }

//      if(array_key_exists('compatibility',$data) && array_key_exists('database',$data['compatibility'])) {
//          $v1 = substr($data['compatibility']['database'],-4);
//          $v2 = substr(DB_UPDATE_VERSION,-4);
//          if($v2 > $v1) {
//              $t = sprintf( t('Warning: Database versions differ by %1$d updates.'), $v2 - $v1 );
//              notice($t . EOL);
//          }
//      }

        $codebase = 'zap';

        if ((!array_path_exists('compatibility/codebase', $data)) || $data['compatibility']['codebase'] !== $codebase) {
            notice(t('Data export format is not compatible with this software'));
            return;
        }

        $channel = App::get_channel();

        if (array_key_exists('item', $data) && $data['item']) {
            import_items($channel, $data['item'], false, ((array_key_exists('relocate', $data)) ? $data['relocate'] : null));
        }

        info(t('Import completed') . EOL);
    }


    /**
     * @brief Generate item import page.
     *
     * @return string with parsed HTML.
     */
    public function get()
    {

        if (!local_channel()) {
            notice(t('Permission denied') . EOL);
            return login();
        }

        $o = replace_macros(Theme::get_template('item_import.tpl'), [
            '$title' => t('Import Items'),
            '$desc' => t('Use this form to import existing posts and content from an export file.'),
            '$label_filename' => t('File to Upload'),
            '$form_security_token' => get_form_security_token('import_items'),
            '$submit' => t('Submit')
        ]);

        return $o;
    }
}
