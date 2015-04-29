<?php

$config = [
	'sortable_table' => [
		'default_profile' => 'default',
		'profiles' => [
			'default' => [
				'sort_uri_id'   => 'sort',
				'search_uri_id' => 'search',
				'sort_sep'      => '--',
				'type_sep'      => '-',
				'search'        => false,
				'no_results_text' => 'No results found',
				'no_search_results_text' => 'No search results found',
				'assets' => [
					'css'   => 'sortable-table/css/styles.css',
					'js'    => 'sortable-table/javascript/script.js',
					'icons' => 'sortable-table/images/icons/'
				],
				'actions' => [
					'view' => [
						'title' => 'View',
						'icon'  => 'info.png'
					],
					'edit' => [
						'title' => 'Edit',
						'icon'  => 'pencil.png'
					],
					'delete' => [
						'title' => 'Delete',
						'icon'  => 'delete.png'
					]
				],
				'action_field'  => 'id',
				'action_column' => 'id'
			]
		]
	]
];

?>