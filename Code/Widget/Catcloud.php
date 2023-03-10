<?php

namespace Code\Widget;

use App;

class Catcloud implements WidgetInterface
{

    public function widget(array $arguments): string
    {

        if ((!App::$profile['profile_uid']) || (!App::$profile['channel_hash'])) {
            return '';
        }

        $limit = ((array_key_exists('limit', $arguments)) ? intval($arguments['limit']) : 50);

        if (array_key_exists('type', $arguments)) {
            switch ($arguments['type']) {
                case 'cards':
                    if (!perm_is_allowed(App::$profile['profile_uid'], get_observer_hash(), 'view_pages')) {
                        return '';
                    }

                    return card_catblock(App::$profile['profile_uid'], $limit, '', App::$profile['channel_hash']);

                case 'articles':
                    if (!perm_is_allowed(App::$profile['profile_uid'], get_observer_hash(), 'view_articles')) {
                        return '';
                    }

                    return article_catblock(App::$profile['profile_uid'], $limit, '', App::$profile['channel_hash']);


                default:
                    break;
            }
        }


        if (!perm_is_allowed(App::$profile['profile_uid'], get_observer_hash(), 'view_stream')) {
            return '';
        }

        return catblock(App::$profile['profile_uid'], $limit, '', App::$profile['channel_hash']);
    }
}
