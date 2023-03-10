<?php

namespace Code\Widget;

use App;
use Code\Lib\Features;
use Code\Render\Theme;


class Archive implements WidgetInterface
{

    public function widget(array $arguments): string
    {
        if (!App::$profile_uid) {
            return '';
        }

        $uid = App::$profile_uid;

        if (!Features::enabled($uid, 'archives')) {
            return '';
        }

        if (!perm_is_allowed($uid, get_observer_hash(), 'view_stream')) {
            return '';
        }

        $wall = ((array_key_exists('wall', $arguments)) ? intval($arguments['wall']) : 0);
        $wall = ((array_key_exists('articles', $arguments)) ? 2 : $wall);

        $style = ((array_key_exists('style', $arguments)) ? $arguments['style'] : 'select');
        $showend = (bool)get_pconfig($uid, 'system', 'archive_show_end_date');
        $mindate = get_pconfig($uid, 'system', 'archive_mindate');
        $visible_years = get_pconfig($uid, 'system', 'archive_visible_years', 5);

        $url = z_root() . '/' . App::$cmd;

        $ret = list_post_dates($uid, $wall, $mindate);

        if (!count($ret)) {
            return '';
        }

        $cutoff_year = intval(datetime_convert('', date_default_timezone_get(), 'now', 'Y')) - $visible_years;
        $cutoff = array_key_exists($cutoff_year, $ret);

        return replace_macros(Theme::get_template('posted_date_widget.tpl'), [
            '$title' => t('Archives'),
            '$size' => $visible_years,
            '$cutoff_year' => $cutoff_year,
            '$cutoff' => $cutoff,
            '$url' => $url,
            '$style' => $style,
            '$showend' => $showend,
            '$dates' => $ret
        ]);

    }
}
