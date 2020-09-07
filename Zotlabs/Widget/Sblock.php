<?php

namespace Zotlabs\Widget;

use App;

class Sblock {

	function widget($args) {

		if (! local_channel()) {
			return EMPTY_STR;
		}
		
		return replace_macros(get_markup_template('superblock_widget.tpl'), [
			'$connect'             => t('Block Channel'),
			'$desc'                => t('Enter channel address'),
			'$hint'                => t('Examples: bob@example.com, https://example.com/barbara'),
			'$follow'              => t('Block'),
			'$abook_usage_message' => '',
		]);
	}
}

