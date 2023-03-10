<?php

namespace Code\Module;

/**
 * @file Code/Module/Setup.php
 *
 * @brief Controller for the initial setup/installation.
 *
 * @todo This setup module could need some love and improvements.
 */

use App;
use DBA;
use PDO;
use Code\Web\Controller;
use Code\Lib\Channel;
use Code\Lib\Url;
use Code\Render\Theme;
use Code\Storage\Stdio;

/**
 * @brief Initialisation for the setup module.
 *
 */
class Setup extends Controller
{

    private $install_wizard_pass = 1;

    /**
     * {@inheritDoc}
     * @see \\Code\\Web\\Controller::init()
     */

    public function init()
    {

        // Ensure that if somebody hasn't read the install documentation and doesn't have all
        // the required modules or has a totally borked shared hosting provider and they can't
        // figure out what the hell is going on - that we at least spit out an error message which
        // we can inquire about when they write to tell us that our software doesn't work.

        // The worst thing we can do at this point is throw a white screen of death and rely on
        // them knowing about servers and php modules and logfiles enough so that we can guess
        // at the source of the problem. As ugly as it may be, we need to throw a technically worded
        // PHP error message in their face. Once installation is complete, application errors will
        // throw a white screen because these error messages divulge information which can
        // potentially be useful to hackers.

        // We can only set E_WARNING once the code has been thoroughly cleansed of referencing
        // undeclared or unset variables which were once legal or accepted in PHP.

        error_reporting(E_ERROR | E_PARSE);
        ini_set('log_errors', '0');
        ini_set('display_errors', '1');

        // $baseurl/setup/testrewrite to test if rewrite in .htaccess is working

        if (argc() == 2 && argv(1) == "testrewrite") {
            echo 'ok';
            killme();
        }

        if (x($_POST, 'pass')) {
            $this->install_wizard_pass = intval($_POST['pass']);
        } else {
            $this->install_wizard_pass = 1;
        }
    }

    /**
     * @brief Handle the actions of the different setup steps.
     *
     */
    public function post()
    {

        switch ($this->install_wizard_pass) {
            case 1:
            case 2:
                return;
            case 3:
                $dbhost = trim($_POST['dbhost']);
                $dbport = intval(trim($_POST['dbport']));
                $dbuser = trim($_POST['dbuser']);
                $dbpass = trim($_POST['dbpass']);
                $dbdata = trim($_POST['dbdata']);
                $dbtype = intval(trim($_POST['dbtype']));

                require_once('include/dba/dba_driver.php');

                $db = DBA::dba_factory($dbhost, $dbport, $dbuser, $dbpass, $dbdata, $dbtype, true);

                if (!DBA::$dba->connected) {
                    echo 'Database Connect failed: ' . DBA::$dba->error;
                    killme();
                }
                return;

            case 4:
                $urlpath = App::get_path();
                $dbhost = trim($_POST['dbhost']);
                $dbport = intval(trim($_POST['dbport']));
                $dbuser = trim($_POST['dbuser']);
                $dbpass = trim($_POST['dbpass']);
                $dbdata = trim($_POST['dbdata']);
                $dbtype = intval(trim($_POST['dbtype']));
                $phpath = trim($_POST['phpath']);
                $timezone = trim($_POST['timezone']);
                $adminmail = trim($_POST['adminmail']);
                $siteurl = trim($_POST['siteurl']);
                $sitename = trim($_POST['sitename']);

                if ($siteurl != z_root()) {
                    $test = Url::get($siteurl . '/setup/testrewrite');
                    if ((!$test['success']) || ($test['body'] !== 'ok')) {
                        App::$data['url_fail'] = true;
                        App::$data['url_error'] = $test['error'];
                        return;
                    }
                }

                if (!DBA::$dba->connected) {
                    // connect to db
                    $db = DBA::dba_factory($dbhost, $dbport, $dbuser, $dbpass, $dbdata, $dbtype, true);
                }

                if (!DBA::$dba->connected) {
                    echo 'CRITICAL: DB not connected.';
                    killme();
                }

                $txt = replace_macros(Theme::get_email_template('htconfig.tpl'), [
                    '$dbhost' => $dbhost,
                    '$dbport' => $dbport,
                    '$dbuser' => $dbuser,
                    '$dbpass' => $dbpass,
                    '$dbdata' => $dbdata,
                    '$dbtype' => $dbtype,
                    '$timezone' => $timezone,
                    '$sitename' => $sitename,
                    '$siteurl' => $siteurl,
                    '$site_id' => random_string(),
                    '$phpath' => $phpath,
                    '$adminmail' => $adminmail
                ]);

                $result = file_put_contents('.htconfig.php', $txt);
                if (!$result) {
                    App::$data['txt'] = $txt;
                }

                $errors = $this->load_database($db);

                if ($errors) {
                    App::$data['db_failed'] = $errors;
                } else {
                    App::$data['db_installed'] = true;
                }

                return;
            default:
                break;
        }
    }

    /**
     * @brief Get output for the setup page.
     *
     * Depending on the state we are currently in it returns different content.
     *
     * @return string parsed HTML output
     */

    public function get()
    {

        $o = EMPTY_STR;
        $wizard_status = EMPTY_STR;
        $db_return_text = EMPTY_STR;
        $install_title = t('$Projectname Server - Setup');

        if (x(App::$data, 'db_conn_failed')) {
            $this->install_wizard_pass = 2;
            $wizard_status = t('Could not connect to database.');
        }
        if (x(App::$data, 'url_fail')) {
            $this->install_wizard_pass = 3;
            $wizard_status = t('Could not connect to specified site URL. Possible SSL certificate or DNS issue.');
            if (App::$data['url_error']) {
                $wizard_status .= ' ' . App::$data['url_error'];
            }
        }

        if (x(App::$data, 'db_create_failed')) {
            $this->install_wizard_pass = 2;
            $wizard_status = t('Could not create table.');
        }
        if (x(App::$data, 'db_installed')) {
            $pass = t('Installation succeeded!');
            $icon = 'check';
            $txt = t('Your site database has been installed.') . EOL;
            $db_return_text .= $txt;
        }
        if (x(App::$data, 'db_failed')) {
            $pass = t('Database install failed!');
            $icon = 'exclamation-triangle';
            $txt = t('You may need to import the file "install/schema_xxx.sql" manually using a database client.') . EOL;
            $txt .= t('Please see the file "install/INSTALL.txt".') . EOL . '<hr>';
            $txt .= '<pre>' . App::$data['db_failed'] . '</pre>' . EOL;
            $db_return_text .= $txt;
        }
        if (DBA::$dba && DBA::$dba->connected) {
            $r = q("SELECT COUNT(*) as total FROM account");
            if ($r && count($r) && $r[0]['total']) {
                return replace_macros(Theme::get_template('install.tpl'), [
                    '$title' => $install_title,
                    '$pass' => '',
                    '$status' => t('Permission denied.'),
                    '$text' => '',
                ]);
            }
        }

        if (x(App::$data, 'txt') && strlen(App::$data['txt'])) {
            $db_return_text .= $this->manual_config($a);
        }

        if ($db_return_text !== EMPTY_STR) {
            $tpl = Theme::get_template('install.tpl');
            return replace_macros(Theme::get_template('install.tpl'), [
                '$title' => $install_title,
                '$icon' => $icon,
                '$pass' => $pass,
                '$text' => $db_return_text,
                '$what_next' => $this->what_next()
            ]);
        }

        switch ($this->install_wizard_pass) {
            case 1:
            {
                // System check

                $checks = [];

                $this->check_funcs($checks);

                $this->check_htconfig($checks);

                $this->check_store($checks);

                $this->check_smarty3($checks);

                $this->check_keys($checks);

                if (x($_POST, 'phpath')) {
                    $phpath = notags(trim($_POST['phpath']));
                }

                $this->check_php($phpath, $checks);

                $this->check_phpconfig($checks);

                $this->check_htaccess($checks);

                $checkspassed = array_reduce($checks, "self::check_passed", true);

                $o .= replace_macros(Theme::get_template('install_checks.tpl'), [
                    '$title' => $install_title,
                    '$pass' => t('System check'),
                    '$checks' => $checks,
                    '$passed' => $checkspassed,
                    '$see_install' => t('Please see the file "install/INSTALL.txt".'),
                    '$next' => t('Next'),
                    '$reload' => t('Check again'),
                    '$phpath' => $phpath,
                    '$baseurl' => z_root(),
                ]);
                return $o;
            }

            case 2:
            {
                // Database config

                $dbhost = ((x($_POST, 'dbhost')) ? trim($_POST['dbhost']) : '127.0.0.1');
                $dbuser = ((x($_POST, 'dbuser')) ? trim($_POST['dbuser']) : EMPTY_STR);
                $dbport = ((x($_POST, 'dbport')) ? intval(trim($_POST['dbport'])) : 0);
                $dbpass = ((x($_POST, 'dbpass')) ? trim($_POST['dbpass']) : EMPTY_STR);
                $dbdata = ((x($_POST, 'dbdata')) ? trim($_POST['dbdata']) : EMPTY_STR);
                $dbtype = ((x($_POST, 'dbtype')) ? intval(trim($_POST['dbtype'])) : 0);
                $phpath = ((x($_POST, 'phpath')) ? trim($_POST['phpath']) : EMPTY_STR);

                $o .= replace_macros(Theme::get_template('install_db.tpl'), [
                    '$title' => $install_title,
                    '$pass' => t('Database connection'),
                    '$info_01' => t('In order to install this software we need to know how to connect to your database.'),
                    '$info_02' => t('Please contact your hosting provider or site administrator if you have questions about these settings.'),
                    '$info_03' => t('The database you specify below should already exist. If it does not, please create it before continuing.'),

                    '$status' => $wizard_status,

                    '$dbhost' => array('dbhost', t('Database Server Name'), $dbhost, t('Default is 127.0.0.1')),
                    '$dbport' => array('dbport', t('Database Port'), $dbport, t('Communication port number - use 0 for default')),
                    '$dbuser' => array('dbuser', t('Database Login Name'), $dbuser, ''),
                    '$dbpass' => array('dbpass', t('Database Login Password'), $dbpass, ''),
                    '$dbdata' => array('dbdata', t('Database Name'), $dbdata, ''),
                    '$dbtype' => array('dbtype', t('Database Type'), $dbtype, '', array(0 => 'MySQL', 1 => 'PostgreSQL')),
                    '$baseurl' => z_root(),
                    '$phpath' => $phpath,
                    '$submit' => t('Submit'),
                ]);

                return $o;
                break;
            }
            case 3:
            {
                // Site settings
                $dbhost = ((x($_POST, 'dbhost')) ? trim($_POST['dbhost']) : '127.0.0.1');
                $dbuser = ((x($_POST, 'dbuser')) ? trim($_POST['dbuser']) : EMPTY_STR);
                $dbport = ((x($_POST, 'dbport')) ? intval(trim($_POST['dbport'])) : 0);
                $dbpass = ((x($_POST, 'dbpass')) ? trim($_POST['dbpass']) : EMPTY_STR);
                $dbdata = ((x($_POST, 'dbdata')) ? trim($_POST['dbdata']) : EMPTY_STR);
                $dbtype = ((x($_POST, 'dbtype')) ? intval(trim($_POST['dbtype'])) : 0);
                $phpath = ((x($_POST, 'phpath')) ? trim($_POST['phpath']) : EMPTY_STR);

                $timezone = 'America/Los_Angeles';

                $o .= replace_macros(Theme::get_template('install_settings.tpl'), [
                    '$title' => $install_title,
                    '$pass' => t('Site settings'),
                    '$status' => $wizard_status,
                    '$dbhost' => $dbhost,
                    '$dbport' => $dbport,
                    '$dbuser' => $dbuser,
                    '$dbpass' => $dbpass,
                    '$dbdata' => $dbdata,
                    '$dbtype' => $dbtype,
                    '$adminmail' => ['adminmail', t('Site administrator email address'), '', t('Required. Your account email address must match this in order to use the web admin panel.')],
                    '$siteurl' => ['siteurl', t('Website URL'), z_root(), t('Required. Please use SSL (https) URL if available.')],
                    '$sitename' => ['sitename', t('Website name'), '', t('The name of your website or community.')],
                    '$phpath' => ['phpath', t('Path to PHP command-line executable'), $phpath, 'This <em>may</em> require changing if your platform supports multiple php versions.' ],
                    '$timezone' => ['timezone', t('Please select a default timezone for your website'), $timezone, '', get_timezones()],
                    '$baseurl' => z_root(),
                    '$submit' => t('Submit'),
                ]);
                return $o;
                break;
            }
        }
    }

    /**
     * @brief Add a check result to the array for output.
     *
     * @param[in,out] array &$checks array passed to template
     * @param string $title a title for the check
     * @param bool $status
     * @param bool $required
     * @param string $help optional help string
     */
    public function check_add(&$checks, $title, $status, $required, $help = '')
    {
        $checks[] = [
            'title' => $title,
            'status' => $status,
            'required' => $required,
            'help' => $help
        ];
    }

    /**
     * @brief Checks the PHP environment.
     *
     * @param[in,out] string &$phpath
     * @param[out] array &$checks
     */
    public function check_php(&$phpath, &$checks)
    {
        $help = '';

        if (version_compare(PHP_VERSION, '8.0') < 0) {
            $help .= t('PHP version 8.0 or greater is required.');
            $this->check_add($checks, t('PHP version'), false, true, $help);
        }

        if (strlen($phpath)) {
            $passed = file_exists($phpath);
        } elseif (function_exists('shell_exec')) {
            $phpath = trim(shell_exec('which php'));
            $passed = strlen($phpath);
        }

        if (!$passed) {
            $help .= t('Could not find a command line version of PHP in the web server PATH.') . EOL;
            $help .= t('If you do not have a command line version of PHP installed on server, you will not be able to run background tasks - including message delivery.') . EOL;
            $help .= EOL;

            $help .= replace_macros(Theme::get_template('field_input.tpl'), [
                '$field' => ['phpath', t('PHP executable path'), $phpath, t('Enter full path to php executable. You can leave this blank to continue the installation.')],
            ]);
            $phpath = '';
        }

        $this->check_add($checks, t('Command line PHP') . ($passed ? " ($phpath)" : EMPTY_STR), $passed, false, $help);

        if ($passed) {
            $str = autoname(8);
            $cmd = "$phpath install/testargs.php $str";
            $help = '';

            if (function_exists('shell_exec')) {
                $result = trim(shell_exec($cmd));
            } else {
                $help .= t('Unable to check command line PHP, as shell_exec() is disabled. This is required.') . EOL;
            }
            $passed2 = (($result === $str) ? true : false);
            if (!$passed2) {
                $help .= t('The command line version of PHP on your system does not have "register_argc_argv" enabled.') . EOL;
                $help .= t('This is required for message delivery to work.');
            }

            $this->check_add($checks, t('PHP register_argc_argv'), $passed, true, $help);
        }
    }

    /**
     * @brief Some PHP configuration checks.
     *
     * @param[out] array &$checks
     * @todo Change how we display such informational text. Add more description
     *       how to change them.
     *
     */
    public function check_phpconfig(&$checks)
    {

        $help = '';
        $mem_warning = EMPTY_STR;

        $result = self::getPhpiniUploadLimits();
        if ($result['post_max_size'] < (2 * 1024 * 1024) || $result['max_upload_filesize'] < (2 * 1024 * 1024)) {
            $mem_warning = '<strong>' . t('This is not sufficient to upload larger images or files. You should be able to upload at least 2MB (2097152 bytes) at once.') . '</strong>';
        }

        $help = sprintf(
            t('Your max allowed total upload size is set to %s. Maximum size of one file to upload is set to %s. You are allowed to upload up to %d files at once.'),
            userReadableSize($result['post_max_size']),
            userReadableSize($result['max_upload_filesize']),
            $result['max_file_uploads']
        );

        $help .= (($mem_warning) ? $mem_warning : EMPTY_STR);
        $help .= '<br><br>' . t('You can adjust these settings in the server php.ini file.');

        $this->check_add($checks, t('PHP upload limits'), true, false, $help);
    }

    /**
     * @brief Check if the openssl implementation can generate keys.
     *
     * @param[out] array $checks
     */
    public function check_keys(&$checks)
    {
        $help = '';
        $res = false;

        if (function_exists('openssl_pkey_new')) {
            $res = openssl_pkey_new(array(
                    'digest_alg' => 'sha1',
                    'private_key_bits' => 4096,
                    'encrypt_key' => false));
        }

        // Get private key

        if (!$res) {
            $help .= t('Error: the "openssl_pkey_new" function on this system is not able to generate encryption keys') . EOL;
            $help .= t('If running under Windows, please see "http://www.php.net/manual/en/openssl.installation.php".');
        }

        $this->check_add($checks, t('Generate encryption keys'), $res, true, $help);
    }

    /**
     * @brief Check for some PHP functions and modules.
     *
     * @param[in,out] array &$checks
     */
    public function check_funcs(&$checks)
    {
        $ck_funcs = [];

        $disabled = explode(',', ini_get('disable_functions'));
        if ($disabled) {
            array_walk($disabled, 'array_trim');
        }

        // add check metadata, the real check is done bit later and return values set

        $this->check_add($ck_funcs, t('libCurl PHP module'), true, true);
        $this->check_add($ck_funcs, t('GD graphics PHP module'), true, true);
        $this->check_add($ck_funcs, t('OpenSSL PHP module'), true, true);
        $this->check_add($ck_funcs, t('PDO database PHP module'), true, true);
        $this->check_add($ck_funcs, t('mb_string PHP module'), true, true);
        $this->check_add($ck_funcs, t('xml PHP module'), true, true);
        $this->check_add($ck_funcs, t('zip PHP module'), true, true);

        if (function_exists('apache_get_modules')) {
            if (!in_array('mod_rewrite', apache_get_modules())) {
                $this->check_add($ck_funcs, t('Apache mod_rewrite module'), false, true, t('Error: Apache webserver mod-rewrite module is required but not installed.'));
            } else {
                $this->check_add($ck_funcs, t('Apache mod_rewrite module'), true, true);
            }
        }
        if ((!function_exists('exec')) || in_array('exec', $disabled)) {
            $this->check_add($ck_funcs, t('exec'), false, true, t('Error: exec is required but is either not installed or has been disabled in php.ini'));
        } else {
            $this->check_add($ck_funcs, t('exec'), true, true);
        }
        if ((!function_exists('shell_exec')) || in_array('shell_exec', $disabled)) {
            $this->check_add($ck_funcs, t('shell_exec'), false, true, t('Error: shell_exec is required but is either not installed or has been disabled in php.ini'));
        } else {
            $this->check_add($ck_funcs, t('shell_exec'), true, true);
        }

        if (!function_exists('curl_init')) {
            $ck_funcs[0]['status'] = false;
            $ck_funcs[0]['help'] = t('Error: libCURL PHP module required but not installed.');
        }
        if ((!function_exists('imagecreatefromjpeg')) && (!class_exists('\\Imagick'))) {
            $ck_funcs[1]['status'] = false;
            $ck_funcs[1]['help'] = t('Error: GD PHP module with JPEG support or ImageMagick graphics library required but not installed.');
        }
        if (!function_exists('openssl_public_encrypt')) {
            $ck_funcs[2]['status'] = false;
            $ck_funcs[2]['help'] = t('Error: openssl PHP module required but not installed.');
        }
        if (class_exists('\\PDO')) {
            $x = PDO::getAvailableDrivers();
            if ((!in_array('mysql', $x)) && (!in_array('pgsql', $x))) {
                $ck_funcs[3]['status'] = false;
                $ck_funcs[3]['help'] = t('Error: PDO database PHP module missing a driver for either mysql or pgsql.');
            }
        }
        if (!class_exists('\\PDO')) {
            $ck_funcs[3]['status'] = false;
            $ck_funcs[3]['help'] = t('Error: PDO database PHP module required but not installed.');
        }
        if (!function_exists('mb_strlen')) {
            $ck_funcs[4]['status'] = false;
            $ck_funcs[4]['help'] = t('Error: mb_string PHP module required but not installed.');
        }
        if (!extension_loaded('xml')) {
            $ck_funcs[5]['status'] = false;
            $ck_funcs[5]['help'] = t('Error: xml PHP module required for DAV but not installed.');
        }
        if (!extension_loaded('zip')) {
            $ck_funcs[6]['status'] = false;
            $ck_funcs[6]['help'] = t('Error: zip PHP module required but not installed.');
        }

        $checks = array_merge($checks, $ck_funcs);
    }

    /**
     * @brief Check for .htconfig requirements.
     *
     * @param[out] array &$checks
     */
    public function check_htconfig(&$checks)
    {
        $status = true;
        $help = EMPTY_STR;

        $fname = '.htconfig.php';

        if (
            (file_exists($fname) && is_writable($fname)) ||
            (!(file_exists($fname) && is_writable('.')))
        ) {
            $this->check_add($checks, t('.htconfig.php is writable'), $status, true, $help);
            return;
        }

        $status = false;
        $help .= t('The web installer needs to be able to create a file called ".htconfig.php" in the top folder of your web server and it is unable to do so.') . EOL;
        $help .= t('This is most often a permission setting, as the web server may not be able to write files in your folder - even if you can.') . EOL;
        $help .= t('Please see install/INSTALL.txt for additional information.');

        $this->check_add($checks, t('.htconfig.php is writable'), $status, true, $help);
    }

    /**
     * @brief Checks for our templating engine Smarty3 requirements.
     *
     * @param[out] array &$checks
     */
    public function check_smarty3(&$checks)
    {
        $status = true;
        $help = '';

        Stdio::mkdir(TEMPLATE_BUILD_PATH, STORAGE_DEFAULT_PERMISSIONS, true);

        if (!is_writable(TEMPLATE_BUILD_PATH)) {
            $status = false;
            $help .= t('This software uses the Smarty3 template engine to render its web views. Smarty3 compiles templates to PHP to speed up rendering.') . EOL;
            $help .= sprintf(t('In order to store these compiled templates, the web server needs to have write access to the directory %s under the top level web folder.'), TEMPLATE_BUILD_PATH) . EOL;
            $help .= t('Please ensure that the user that your web server runs as (e.g. www-data) has write access to this folder.') . EOL;
        }

        $this->check_add($checks, sprintf(t('%s is writable'), TEMPLATE_BUILD_PATH), $status, true, $help);
    }

    /**
     * @brief Check for store directory.
     *
     * @param[out] array &$checks
     */
    public function check_store(&$checks)
    {
        $status = true;
        $help = '';

        Stdio::mkdir('store', STORAGE_DEFAULT_PERMISSIONS, true);

        if (!is_writable('store')) {
            $status = false;
            $help = t('This software uses the store directory to save uploaded files. The web server needs to have write access to the store directory under the top level web folder') . EOL;
            $help .= t('Please ensure that the user that your web server runs as (e.g. www-data) has write access to this folder.') . EOL;
        }

        $this->check_add($checks, t('store is writable'), $status, true, $help);
    }

    /**
     * @brief Check URL rewrite und SSL certificate.
     *
     * @param[out] array &$checks
     */
    public function check_htaccess(&$checks)
    {
        $status = true;
        $help = '';
        $ssl_error = false;

        $url = z_root() . '/setup/testrewrite';

        if (function_exists('curl_init')) {
            $test = Url::get($url);
            if (!$test['success']) {
                if (strstr($url, 'https://')) {
                    $test = Url::get($url, ['novalidate' => true]);
                    if ($test['success']) {
                        $ssl_error = true;
                    }
                } else {
                    $test = Url::get(str_replace('http://', 'https://', $url), ['novalidate' => true]);
                    if ($test['success']) {
                        $ssl_error = true;
                    }
                }

                if ($ssl_error) {
                    $help .= t('SSL certificate cannot be validated. Fix certificate or disable https access to this site.') . EOL;
                    $help .= t('If you have https access to your website or allow connections to TCP port 443 (the https: port), you MUST use a browser-valid certificate. You MUST NOT use self-signed certificates!') . EOL;
                    $help .= t('This restriction is incorporated because public posts from you may for example contain references to images on your own hub.') . EOL;
                    $help .= t('If your certificate is not recognized, members of other sites (who may themselves have valid certificates) will get a warning message on their own site complaining about security issues.') . EOL;
                    $help .= t('This can cause usability issues elsewhere (not just on your own site) so we must insist on this requirement.') . EOL;
                    $help .= t('Providers are available that issue free certificates which are browser-valid.') . EOL;

                    $help .= t('If you are confident that the certificate is valid and signed by a trusted authority, check to see if you have failed to install an intermediate cert. These are not normally required by browsers, but are required for server-to-server communications.') . EOL;

                    $this->check_add($checks, t('SSL certificate validation'), false, true, $help);
                }
            }

            if ((!$test['success']) || ($test['body'] !== 'ok')) {
                $status = false;
                $help = t('Url rewrite in .htaccess is not working. Check your server configuration.' . 'Test: ' . var_export($test, true));
            }

            $this->check_add($checks, t('Url rewrite is working'), $status, true, $help);
        } else {
            // cannot check modrewrite if libcurl is not installed
        }
    }

    /**
     * @brief
     *
     * @param App &$a
     * @return string with paresed HTML
     */
    public function manual_config(&$a)
    {
        $data = htmlspecialchars(App::$data['txt'], ENT_COMPAT, 'UTF-8');
        $o = t('The database configuration file ".htconfig.php" could not be written. Please use the enclosed text to create a configuration file in your web server root.');
        $o .= "<textarea rows=\"24\" cols=\"80\" >$data</textarea>";

        return $o;
    }

    public function load_database_rem($v, $i)
    {
        $l = trim($i);
        if (strlen($l) > 1 && ($l[0] === '-' || ($l[0] === '/' && $l[1] === '*'))) {
            return $v;
        } else {
            return $v . "\n" . $i;
        }
    }


    public function load_database($db)
    {
        $str = file_get_contents(DBA::$dba->get_install_script());
        $arr = explode(';', $str);
        $errors = false;
        foreach ($arr as $a) {
            if (strlen(trim($a))) {
                $r = dbq(trim($a));
                if (!$r) {
                    $errors .= t('Errors encountered creating database tables.') . $a . EOL;
                }
            }
        }

        return $errors;
    }

    /**
     * @brief
     *
     * @return string with parsed HTML
     */
    public function what_next()
    {
        // install the standard theme
        set_config('system', 'allowed_themes', 'redbasic');

        // if imagick converter is installed, use it
        if (Stdio::is_executable('/usr/bin/magick')) {
            set_config('system', 'imagick_convert_path', '/usr/bin/magick');
        }
        elseif (Stdio::is_executable('/usr/bin/convert')) {
            set_config('system', 'imagick_convert_path', '/usr/bin/convert');
        }

        // Set a lenient list of ciphers if using openssl. Other ssl engines
        // (e.g. NSS used in RedHat) require different syntax, so hopefully
        // the default curl cipher list will work for most sites. If not,
        // this can set via config. Many distros are now disabling RC4,
        // but many existing sites still use it and are unable to change it.
        // We do not use SSL for encryption, only to protect session cookies.
        // Url::get() is also used to import shared links and other content
        // so in theory most any cipher could show up and we should do our best
        // to make the content available rather than tell folks that there's a
        // weird SSL error which they can't do anything about. This does not affect
        // the SSL server, but is only a client negotiation to find something workable.
        // Hence it will not make your system susceptible to POODL or other nasties.

        $x = curl_version();
        if (stristr($x['ssl_version'], 'openssl')) {
            set_config('system', 'curl_ssl_ciphers', 'ALL:!eNULL');
        }

        // Create a system channel

        Channel::create_system();

        $register_link = '<a href="' . z_root() . '/register">' . t('registration page') . '</a>';

        return
            t('<h1>What next?</h1>')
            . '<div class="alert alert-info">'
            . t('IMPORTANT: You will need to [manually] setup a scheduled task for the poller.')
            . EOL
            . t('Please see the file "install/INSTALL.txt" for more information and instructions.')
            . '</div><div>'
            . sprintf(t('Go to your new hub %s and register as new member. Please use the same email address that you entered for the administrator email as this will allow your new account to enter the site admin panel.'), $register_link)
            . '</div>';
    }

    /**
     * @brief
     *
     * @param unknown $v
     * @param array $c
     * @return array
     */
    private static function check_passed($v, $c)
    {
        if ($c['required']) {
            $v = $v && $c['status'];
        }
        return $v;
    }

    /**
     * @brief Get some upload related limits from php.ini.
     *
     * This function returns values from php.ini like \b post_max_size,
     * \b max_file_uploads, \b upload_max_filesize.
     *
     * @return array associative array
     *   * \e int \b post_max_size the maximum size of a complete POST in bytes
     *   * \e int \b upload_max_filesize the maximum size of one file in bytes
     *   * \e int \b max_file_uploads maximum number of files in one POST
     *   * \e int \b max_upload_filesize min(post_max_size, upload_max_filesize)
     */

    private static function getPhpiniUploadLimits()
    {
        $ret = [];

        // max size of the complete POST
        $ret['post_max_size'] = self::phpiniSizeToBytes(ini_get('post_max_size'));
        // max size of one file
        $ret['upload_max_filesize'] = self::phpiniSizeToBytes(ini_get('upload_max_filesize'));
        // catch a configuration error where post_max_size < upload_max_filesize
        $ret['max_upload_filesize'] = min(
            $ret['post_max_size'],
            $ret['upload_max_filesize']
        );
        // maximum number of files in one POST
        $ret['max_file_uploads'] = intval(ini_get('max_file_uploads'));

        return $ret;
    }

    /**
     * @brief Parses php_ini size settings to bytes.
     *
     * This function parses common size setting from php.ini files to bytes.
     * e.g. post_max_size = 8M ==> 8388608
     *
     * \note This method does not recognise other human readable formats like
     * 8MB, etc.
     *
     * @param string $val value from php.ini e.g. 2M, 8M
     * @return int size in bytes
     * @todo Make this function more universal useable. MB, T, etc.
     *
     */
    private static function phpiniSizeToBytes($val)
    {
        $val = trim($val);
        $unit = strtolower($val[strlen($val) - 1]);
        // strip off any non-numeric portion
        $val = intval($val);
        switch ($unit) {
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
            default:
                break;
        }

        return (int)$val;
    }
}
