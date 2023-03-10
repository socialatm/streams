<?php

namespace Code\Module\Admin;

use App;
use Michelf\MarkdownExtra;
use Code\Render\Theme;


/**
 * @brief Admin area theme settings.
 */
class Themes
{

    /**
     * @brief
     *
     */
    public function post()
    {

        $theme = argv(2);

        info(t('Theme settings updated.'));
        if (is_ajax()) {
            return;
        }

        goaway(z_root() . '/admin/themes/' . $theme);
    }


    /**
     * @brief Themes admin page.
     *
     * @return string with parsed HTML
     */
    public function get()
    {
        $allowed_themes_str = get_config('system', 'allowed_themes');
        $allowed_themes_raw = explode(',', $allowed_themes_str);
        $allowed_themes = [];
        if (count($allowed_themes_raw)) {
            foreach ($allowed_themes_raw as $x) {
                if (strlen(trim($x))) {
                    $allowed_themes[] = trim($x);
                }
            }
        }

        $themes = [];
        $files = glob('view/theme/*');
        if ($files) {
            foreach ($files as $file) {
                if (! is_dir($file)) {
                    continue;
                }
                $f = basename($file);
                $is_experimental = intval(file_exists($file . '/.experimental'));
                $is_supported = 1 - (intval(file_exists($file . '/.unsupported'))); // Is not used yet
                $is_allowed = intval(in_array($f, $allowed_themes));
                $themes[] = array('name' => $f, 'experimental' => $is_experimental, 'supported' => $is_supported, 'allowed' => $is_allowed);
            }
        }

        if (!count($themes)) {
            notice(t('No themes found.'));
            return '';
        }

        /*
         * Single theme
         */

        if (App::$argc == 3) {
            $theme = App::$argv[2];
            if (!is_dir("view/theme/$theme")) {
                notice(t("Item not found."));
                return '';
            }

            if (x($_GET, "a") && $_GET['a'] == "t") {
                check_form_security_token_redirectOnErr('/admin/themes', 'admin_themes', 't');

                // Toggle theme status

                $this->toggle_theme($themes, $theme, $result);
                $s = $this->rebuild_theme_table($themes);
                if ($result) {
                    info(sprintf('Theme %s enabled.', $theme));
                } else {
                    info(sprintf('Theme %s disabled.', $theme));
                }

                set_config('system', 'allowed_themes', $s);
                goaway(z_root() . '/admin/themes');
            }

            // display theme details

            if ($this->theme_status($themes, $theme)) {
                $status = "on";
                $action = t("Disable");
            } else {
                $status = "off";
                $action = t("Enable");
            }

            $readme = null;
            if (is_file("view/theme/$theme/README.md")) {
                $readme = file_get_contents("view/theme/$theme/README.md");
                $readme = MarkdownExtra::defaultTransform($readme);
            } elseif (is_file("view/theme/$theme/README")) {
                $readme = '<pre>' . file_get_contents("view/theme/$theme/README") . '</pre>';
            }

            $screenshot = array(Theme::get_screenshot($theme), t('Screenshot'));
            if (!stristr($screenshot[0], $theme)) {
                $screenshot = null;
            }

            $t = Theme::get_template('admin_plugins_details.tpl');
            return replace_macros($t, array(
                '$title' => t('Administration'),
                '$page' => t('Themes'),
                '$toggle' => t('Toggle'),
                '$settings' => t('Settings'),
                '$baseurl' => z_root(),

                '$plugin' => $theme,
                '$status' => $status,
                '$action' => $action,
                '$info' => Theme::get_info($theme),
                '$function' => 'themes',
                '$str_author' => t('Author: '),
                '$str_maintainer' => t('Maintainer: '),
                '$screenshot' => $screenshot,
                '$readme' => $readme,

                '$form_security_token' => get_form_security_token('admin_themes'),
            ));
        }

        /*
         * List themes
         */

        $xthemes = [];
        if ($themes) {
            foreach ($themes as $th) {
                $xthemes[] = array($th['name'], (($th['allowed']) ? "on" : "off"), Theme::get_info($th['name']));
            }
        }

        $t = Theme::get_template('admin_plugins.tpl');
        return replace_macros($t, array(
            '$title' => t('Administration'),
            '$page' => t('Themes'),
            '$submit' => t('Submit'),
            '$baseurl' => z_root(),
            '$function' => 'themes',
            '$plugins' => $xthemes,
            '$experimental' => t('[Experimental]'),
            '$unsupported' => t('[Unsupported]'),
            '$form_security_token' => get_form_security_token('admin_themes'),
        ));
    }


    /**
     * @brief Toggle a theme.
     *
     * @param array &$themes
     * @param[in] string $th
     * @param[out] int &$result
     */
    public function toggle_theme(&$themes, $th, &$result)
    {
        for ($x = 0; $x < count($themes); $x++) {
            if ($themes[$x]['name'] === $th) {
                if ($themes[$x]['allowed']) {
                    $themes[$x]['allowed'] = 0;
                    $result = 0;
                } else {
                    $themes[$x]['allowed'] = 1;
                    $result = 1;
                }
            }
        }
    }

    /**
     * @param array $themes
     * @param string $th
     * @return int
     */
    public function theme_status($themes, $th)
    {
        for ($x = 0; $x < count($themes); $x++) {
            if ($themes[$x]['name'] === $th) {
                if ($themes[$x]['allowed']) {
                    return 1;
                } else {
                    return 0;
                }
            }
        }
        return 0;
    }

    /**
     * @param array $themes
     * @return string
     */
    public function rebuild_theme_table($themes)
    {
        $o = '';
        if (count($themes)) {
            foreach ($themes as $th) {
                if ($th['allowed']) {
                    if (strlen($o)) {
                        $o .= ',';
                    }
                    $o .= $th['name'];
                }
            }
        }
        return $o;
    }
}
