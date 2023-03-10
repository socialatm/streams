<?php
namespace Code\Lib;

class Xprof {

    function xprof_store_lowlevel($profile)
    {

        if (! $profile['xprof_hash']) {
            return false;
        }

        $store = [
            'xprof_hash'         => $profile['xprof_hash'],
            'xprof_dob'          => (($profile['birthday'] === '0000-00-00') ? $profile['birthday'] : datetime_convert('', '', $profile['birthday'], 'Y-m-d')),
            'xprof_age'          => (($profile['age'])         ? intval($profile['age']) : 0),
            'xprof_desc'         => (($profile['description']) ? htmlspecialchars($profile['description'], ENT_COMPAT, 'UTF-8', false) : ''),
            'xprof_gender'       => (($profile['gender'])      ? htmlspecialchars($profile['gender'], ENT_COMPAT, 'UTF-8', false) : ''),
            'xprof_marital'      => (($profile['marital'])     ? htmlspecialchars($profile['marital'], ENT_COMPAT, 'UTF-8', false) : ''),
            'xprof_sexual'       => (($profile['sexual'])      ? htmlspecialchars($profile['sexual'], ENT_COMPAT, 'UTF-8', false) : ''),
            'xprof_locale'       => (($profile['locale'])      ? htmlspecialchars($profile['locale'], ENT_COMPAT, 'UTF-8', false) : ''),
            'xprof_region'       => (($profile['region'])      ? htmlspecialchars($profile['region'], ENT_COMPAT, 'UTF-8', false) : ''),
            'xprof_postcode'     => (($profile['postcode'])    ? htmlspecialchars($profile['postcode'], ENT_COMPAT, 'UTF-8', false) : ''),
            'xprof_country'      => (($profile['country'])     ? htmlspecialchars($profile['country'], ENT_COMPAT, 'UTF-8', false) : ''),
            'xprof_about'        => (($profile['about'])       ? htmlspecialchars($profile['about'], ENT_COMPAT, 'UTF-8', false) : ''),
            'xprof_homepage'     => (($profile['homepage'])    ? htmlspecialchars($profile['homepage'], ENT_COMPAT, 'UTF-8', false) : ''),
            'xprof_hometown'     => (($profile['hometown'])    ? htmlspecialchars($profile['hometown'], ENT_COMPAT, 'UTF-8', false) : ''),
            'xprof_keywords'     => (($profile['keywords'])    ? htmlspecialchars($profile['keywords'], ENT_COMPAT, 'UTF-8', false) : ''),
        ];

        return create_table_from_array('xprof', $store);
    }


}