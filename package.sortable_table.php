<?php

namespace ionmvc\packages;

class sortable_table extends \ionmvc\classes\package {

	const version = '1.0.0';

	public static function package_info() {
		return [
			'author'      => 'Kyle Keith',
			'version'     => self::version,
			'description' => 'Sortable table helper'
		];
	}

}

?>