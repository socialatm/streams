<?php

namespace Code\Widget;

use App;
use Code\Lib\ServiceClass;
use Code\Render\Theme;

    
class Follow implements WidgetInterface
{

    public function widget(array $arguments): string
    {

        if (!local_channel()) {
            return EMPTY_STR;
        }

        $uid = App::$channel['channel_id'];
        $r = q(
            "select count(*) as total from abook where abook_channel = %d and abook_self = 0 ",
            intval($uid)
        );

        if ($r) {
            $total_channels = $r[0]['total'];
        }

        $limit = ServiceClass::fetch($uid, 'total_channels');
        if ($limit !== false) {
            $abook_usage_message = sprintf(t("You have %1$.0f of %2$.0f allowed connections."), $total_channels, $limit);
        } else {
            $abook_usage_message = EMPTY_STR;
        }

        return replace_macros(Theme::get_template('follow.tpl'), [
            '$connect' => t('Add New Connection'),
            '$desc' => t('Enter channel address'),
            '$hint' => t('Examples: bob@example.com, https://example.com/barbara'),
            '$follow' => t('Connect'),
            '$abook_usage_message' => $abook_usage_message
        ]);
    }
}
