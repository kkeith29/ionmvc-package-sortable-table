<?php

namespace ionmvc\packages\sortable_table\libraries;

use ionmvc\classes\array_func;
use ionmvc\classes\asset;
use ionmvc\classes\config;
use ionmvc\classes\func;
use ionmvc\classes\html\tag;
use ionmvc\classes\library;
use ionmvc\classes\redirect;
use ionmvc\classes\package;
use ionmvc\classes\uri;
use ionmvc\exceptions\app as app_exception;

class sortable_table {

	const sort_none = 0;
	const sort_asc  = 1;
	const sort_desc = 2;

	const insert_left  = 1;
	const insert_right = 2;

	private static $config = [];
	private static $count  = 0;

	private $form_id        = null;
	private $columns        = [];
	private $inserts        = [];
	private $query          = null;
	private $actions        = [];
	private $results        = null;
	private $paginate       = null;
	private $result_actions = [];
	private $result_total   = 0;
	private $filtered       = false;
	private $search_term    = null;
	private $html           = null;
	
	private $data = [];

	public function __construct( $config=[] ) {
		if ( self::$count === 0 ) {
			self::$config = config::get('sortable_table');
		}
		self::$count++;
		if ( !isset( $config['profile'] ) ) {
			$config['profile'] = self::$config['default_profile'];
		}
		if ( !isset( self::$config['profiles'][$config['profile']] ) ) {
			throw new app_exception( 'Unable to find the profile \'%s\'',$config['profile'] );
		}
		$this->data = array_merge( self::$config['profiles'][$config['profile']],$config );
		$required = ['sort_uri_id','search_uri_id'];
		if ( count( ( $keys = array_diff( $required,array_keys( $this->data ) ) ) ) > 0 ) {
			throw new app_exception( 'Missing config items: %s',implode( ', ',$keys ) );
		}
		$this->data['sort_uri_id'] = $this->data['sort_uri_id'] . ( self::$count == 1 ? '' : self::$count );
		$this->data['search_uri_id'] = $this->data['search_uri_id'] . ( self::$count == 1 ? '' : self::$count );
	}

	public function config( $data ) {
		$this->data = array_merge( $this->data,$data );
	}

	public function column( $column,$header,$config=[] ) {
		$config = array_merge( compact('column','header'),$config );
		if ( !isset( $config['sort'] ) ) {
			$config['sort'] = self::sort_none;
		}
		$i = ( count( $this->columns ) + 1 );
		$this->columns[$i] = $config;
		return $i;
	}

	public function columns( $data ) {
		if ( !is_array( $data ) ) {
			throw new app_exception('Parameter is required to be an array');
		}
		foreach( $data as $datum ) {
			if ( !isset( $datum['config'] ) ) {
				$datum['config'] = [];
			}
			$this->column( $datum['column'],$datum['header'],$datum['config'] );
		}
	}

	public function insert( $index,$position,$header,$data,$config=[] ) {
		$this->inserts[$index] = array_merge( compact('header','position','data'),$config );
		return $this;
	}

	public function action( $name,$uri,$config=[] ) {
		if ( !isset( $this->data['actions'][$name] ) ) {
			throw new app_exception( "Unable to find action '%s'",$name );
		}
		$config['uri'] = $uri;
		$config = array_func::merge_recursive_distinct( $this->data['actions'][$name],$config );
		$this->actions[] = $config;
	}

	public function actions( $data ) {
		if ( !is_array( $data ) ) {
			throw new app_exception('Parameter is required to be an array');
		}
		foreach( $data as $datum ) {
			if ( !isset( $datum['config'] ) ) {
				$datum['config'] = [];
			}
			$this->action( $datum['name'],$datum['uri'],$datum['config'] );
		}
	}

	public function query( $query ) {
		if ( !is_null( $this->results ) ) {
			throw new app_exception('You have already defined an array for this sort');
		}
		$this->query = $query;
		return $this;
	}

	public function results( $array ) {
		if ( !is_null( $this->query ) ) {
			throw new app_exception('You have already defined a query for this sort');
		}
		$this->results = $array;
		return $this;
	}

	public function paginate( $config=[] ) {
		if ( !package::loaded('pagination') ) {
			throw new app_exception('Pagination package is required to use this functionality');
		}
		$this->paginate = library::pagination( $config );
		return $this;
	}

	public function result_action( $name,\Closure $function ) {
		$this->result_actions[] = compact('name','function');
		return $this;
	}

	private function format( $data ) {
		$retval = [];
		foreach( $data as $key => $val ) {
			$retval[] = $key . $this->data['type_sep'] . $val;
		}
		return implode( $this->data['sort_sep'],$retval );
	}

	private function compare( $a,$b,$i=0 ) {
		if ( count( $this->sort ) == 0 || !isset( $this->columns[$i] ) ) {
			return 0;
		}
		$column = $this->columns[$i]['column'];
		$a_cmp = $a[$column];
		$b_cmp = $b[$column];
		$type = ( $this->sort[$i]['type'] == self::sort_desc ? -1 : self::sort_asc );
		if ( !isset( $this->columns[$i]['compare_function'] ) ) {
			$a_dt = strtotime( $a_cmp );
			$b_dt = strtotime( $b_cmp );
			if ( ( $a_dt == -1 ) || ( $b_dt == -1 ) || ( $a_dt == false ) || ( $b_dt == false ) ) {
				$retval = ( $type * strnatcasecmp( $a_cmp,$b_cmp ) );
			}
			else {
				$retval = ( $type * ( $a_dt > $b_dt ? 1 : ( $a_dt < $b_dt ? -1 : 0 ) ) );
			}
		}
		else {
			$retval = call_user_func_array( $this->columns[$i]['compare_function'],[ $a_cmp,$b_cmp ] );
			$retval = ( $type * $retval );
		}
		if ( $retval == 0 && $i < ( count( $this->sort ) - 1 ) ) {
			return $this->compare( $a,$b,$i+1 );
		}
		return $retval;
	}

	private function do_sort() {
		$sort = [];
		if ( ( $seg = uri::segment( $this->data['sort_uri_id'] ) ) !== false ) {
			foreach( array_filter( explode( $this->data['sort_sep'],$seg ) ) as $data ) {
				list( $column,$type ) = explode( $this->data['type_sep'],$data,2 );
				if ( array_key_exists( $column,$this->columns ) && in_array( $type,[ self::sort_asc,self::sort_desc ] ) ) {
					$sort[$column] = $type;
				}
			}
		}
		foreach( $this->columns as $i => $config ) {
			if ( isset( $config['sortable'] ) && $config['sortable'] == false ) {
				continue;
			}
			$data = $sort;
			$type = $config['sort'];
			if ( isset( $data[$i] ) ) {
				$type = $data[$i];
			}
			$data[$i] = ( $type == self::sort_asc ? self::sort_desc : ( $type == self::sort_desc ? self::sort_none : self::sort_asc ) );
			if ( $data[$i] == self::sort_none ) {
				unset( $data[$i] );
			}
			$this->sort[$i] = [ 'url'=>array( $this->data['sort_uri_id']=>$this->format($data) ),'type'=>$type ];
		}
		if ( is_null( $this->results ) ) {
			$query = clone $this->query;
			$this->data['total_results'] = $query->clear_fields(false)->count('*','count')->use_clauses('join','where','group_by')->execute()->result()->count;
			foreach( $sort as $i => $type ) {
				if ( $type !== self::sort_none ) {
					$this->query->order_by( $this->columns[$i]['column'],( $type == self::sort_asc ? 'asc' : 'desc' ) );
				}
			}
			return;
		}
		$this->data['total_results'] = count( $this->results );
		usort( $this->results,[ $this,'compare' ] );
	}

	private function do_search() {
		if ( ( $this->search_term = uri::segment( $this->data['search_uri_id'] ) ) === false ) {
			$this->search_term = null;
			return;
		}
		$this->search_term = uri::base64_decode( $this->search_term );
		$columns = [];
		foreach( $this->columns as $i => $config ) {
			if ( isset( $config['searchable'] ) && $config['searchable'] == false ) {
				continue;
			}
			if ( isset( $config['search-columns'] ) ) {
				$columns = array_merge( $columns,$config['search-columns'] );
				continue;
			}
			$columns[$i] = ( isset( $config['search-column'] ) ? $config['search-column'] : $config['column'] );
		}
		if ( count( $columns ) == 0 ) {
			return;
		}
		$this->filtered = true;
		if ( is_null( $this->results ) ) {
			foreach( $columns as $column ) {
				if ( !$this->query->is_field_alias( $column ) ) {
					continue;
				}
				throw new app_exception('Unfortunately, fields that are aliases cannot be searched at this time');
			}
			$search_term = $this->search_term;
			$this->query->and_where_group(function( $query ) use( $columns,$search_term ) {
				foreach( $columns as $column ) {
					$query->or_where_like( $column,$search_term,1 );
				}
			});
			return;
		}
		foreach( $this->results as $i => $result ) {
			$found = false;
			foreach( $columns as $column ) {
				if ( strpos( $result[$column],$this->search_term ) !== false ) {
					$found = true;
					break;
				}
			}
			if ( $found == false ) {
				unset( $this->results[$i] );
			}
		}
	}

	private function build() {
		$this->do_sort();
		if ( $this->data['search'] ) {
			$this->do_search();
		}
		if ( !is_null( $this->paginate ) ) {
			if ( is_null( $this->results ) ) {
				$this->paginate->query( $this->query )->build();
			}
			else {
				$this->results = $this->paginate->results( $this->results )->get_results();
			}
		}
		if ( is_null( $this->results ) ) {
			$this->results = $this->query->execute()->rows();
		}
		$no_results = false;
		if ( count( $this->results ) == 0 ) {
			$no_results = true;
		}
		$action_form = false;
		if ( count( $this->result_actions ) > 0 && $no_results == false ) {
			$action_form = true;
		}
		$key_val = [
			$this->data['sort_uri_id']   => '',
			$this->data['search_uri_id'] => ''
		];
		if ( !is_null( $this->paginate ) ) {
			$key_val[$this->paginate->page_uri_id]  = '';
			$key_val[$this->paginate->limit_uri_id] = '';
		}
		$this->data['id'] = substr( sha1( uri::create( null,[ 'key-val'=>$key_val ] ) ),0,8 ) . '-sort' . self::$count;
		if ( $action_form || $this->data['search'] ) {
			$this->data['form'] = library::form([
				'id' => $this->data['id']
			]);
			//$this->data['form']->security('timeout',false);
		}
		if ( $action_form ) {
			if ( isset( $this->data['assets']['js'] ) && $this->data['assets']['js'] !== false ) {
				if ( !package::loaded('jquery') ) {
					throw new app_exception('jQuery package is required');
				}
				\ionmvc\packages\jquery\classes\jquery::load();
				asset::add( $this->data['assets']['js'] );
			}
			$action_field = $this->data['action_field'];
			$values = [];
			foreach( $this->results as $result ) {
				$values[$result[$this->data['action_column']]] = $result[$this->data['action_column']];
			}
			$actions = [];
			foreach( $this->result_actions as $i => $action ) {
				$actions[$i] = $action['name'];
			}
			$result_actions = $this->result_actions;
			$this->data['form']->fieldset('action',function( $form ) use( $action_field,$values,$actions ) {
				$form->field( $action_field,'checkbox_group' )->label('Selection')->values( $values )->rules('required|in_values');
				$form->field('action','select')->label('Action')->options( $actions )->tag(['class'=>'c-sttfa-select'])->rules('required|in_options');
			})->rules(function( $rules ) use( $result_actions,$action_field ) {
				$rules->set_message('action_failed','Unable to do action');
				$rules->do_action(function( $form ) use( $result_actions,$action_field ) {
					$action = $form->input('action');
					if ( !call_user_func( $result_actions[$action]['function'],$form->input( $action_field ) ) ) {
						return [
							'message_id' => 'action_failed'
						];
					}
					return true;
				});
			});
			$this->data['form']->field('action_submit','input')->type('submit')->tag(['class'=>'c-sttfa-submit','value'=>'Go']);
			if ( $this->data['form']->pressed('action_submit') && $this->data['form']->is_valid('action') ) {
				$this->data['form']->reset();
				redirect::current_page();
			}
		}
		if ( $this->data['search'] ) {
			if ( !is_null( $this->search_term ) ) {
				$this->data['form']->set_field( 'search_term',$this->search_term );
			}
			$this->data['form']->fieldset('search',function( $form ) {
				$form->field('search_term','input')->label('Search Term')->type('text')->tag(['class'=>'c-sttis-term'])->rules('max_length[200]');
			});
			$this->data['form']->field('search_submit','input')->type('submit')->tag(['class'=>'c-sttis-submit','value'=>'Search']);
			if ( !is_null( $this->search_term ) ) {
				$this->data['form']->field('search_clear','input')->type('submit')->tag(['class'=>'c-sttis-clear','value'=>'Clear']);
			}
			if ( $this->data['form']->pressed('search_clear') ) {
				$uri = [];
				$uri[$this->data['search_uri_id']] = '';
				if ( !is_null( $this->paginate ) ) {
					$uri[$this->paginate->page_uri_id] = 1;
				}
				$this->data['form']->reset();
				redirect::to_url(uri::create( $uri,['all'=>true] ));
			}
			if ( $this->data['form']->pressed('search_submit') && $this->data['form']->is_valid('search') ) {
				$uri = [
					$this->data['search_uri_id'] => uri::base64_encode( $this->data['form']->input('search_term','') )
				];
				if ( !is_null( $this->paginate ) ) {
					$uri[$this->paginate->page_uri_id] = 1;
				}
				$this->data['form']->reset();
				redirect::to_url(uri::create( $uri,['all'=>true] ));
			}
		}
		if ( isset( $this->data['assets']['css'] ) && $this->data['assets']['css'] !== false ) {
			asset::add( $this->data['assets']['css'] );
		}
		$html = "<div id=\"{$this->data['id']}\" class=\"m-sortable-table\">\n";
		if ( $action_form || $this->data['search'] ) {
			$html .= $this->data['form']->open();
			$errors = $this->data['form']->get_errors();
			if ( count( $errors ) > 0 ) {
				$html .= "\t<div class=\"c-st-errors\">\n\t\t<div class=\"c-ste-label\">Errors have occurred:</div>\n\t\t<ul class=\"c-ste-list\">\n";
				foreach( $errors as $error ) {
					$html .= "\t\t\t<li class=\"c-stel-item\">{$error}</li>\n";
				}
				$html .= "\t\t</ul>\n\t</div>\n";
			}
		}
		$html .= "\t<table class=\"c-st-table\" cellspacing=\"0\" cellpadding=\"0\">\n";
		$colspan = ( count( $this->columns ) + count( $this->actions ) + ( $action_form ? 1 : 0 ) );
		//header
		$html .= "\t\t<tr>\n\t\t\t<td class=\"c-stt-info\" colspan=\"{$colspan}\">\n";
		if ( is_null( $this->paginate ) ) {
			$start = ( $no_results == true ? 0 : 1 );
			$end   = ( $no_results == true ? 0 : count( $this->results ) );
			$total = $end;
		}
		else {
			$start = $this->paginate->result_start;
			$end   = $this->paginate->result_end;
			$total = $this->paginate->total_results;
		}
		$html .= "\t\t\t\t<span class=\"c-stti-showing\">Showing {$start} to {$end} of {$total} entries" . ( $this->filtered == true ? " (filtered from {$this->data['total_results']} total entries)" : '' ) . "</span>\n";
		if ( $this->data['search'] == true ) {
			$html .= "\t\t\t\t<div class=\"c-stti-search\">" . $this->data['form']->field('search_term')->render() . $this->data['form']->field('search_submit')->render() . ( !is_null( $this->search_term ) ? $this->data['form']->field('search_clear')->render() : '' ) . "</div>\n";
		}
		$html .= "\t\t\t</td>\n\t\t</tr>\n";
		//column headers
		$html .= "\t\t<tr class=\"c-stt-headers\">\n";
		if ( $action_form == true ) {
			$html .= "\t\t\t<th class=\"c-stth-check-all\"><input type=\"checkbox\" class=\"c-stthca-checkbox\" /></th>\n";
		}
		foreach( $this->columns as $i => $config ) {
			if ( isset( $this->inserts[$i] ) && $this->inserts[$i]['position'] == self::insert_left ) {
				$html .= "\t\t\t<th" . tag::build_attrs( ( isset( $this->inserts[$i]['th'] ) ? $this->inserts[$i]['th'] : [] ) ) . ">{$this->inserts[$i]['header']}</th>\n";
			}
			switch( $this->sort[$i]['type'] ) {
				case self::sort_none:
					$config['header'] .= '<span class="c-stthhc-arrow">&#8597;</span>';
					break;
				case self::sort_asc:
					$config['header'] .= '<span class="c-stthhc-arrow">&#8593;</span>';
					break;
				case self::sort_desc:
					$config['header'] .= '<span class="c-stthhc-arrow">&#8595;</span>';
					break;
			}
			$html .= "\t\t\t<th class=\"c-stth-header\">" . ( isset( $this->sort[$i] ) ? "<a class=\"c-stthh-column\" href=\"" . uri::create( $this->sort[$i]['url'],array('all'=>true,'csm'=>( uri::segment('csm') === false ? false : true )) ) . "\">" : '' ) . $config['header'] . ( isset( $this->sort[$i] ) ? '</a>' : '' ) . "</th>\n";
			if ( isset( $this->inserts[$i] ) && $this->inserts[$i]['position'] == self::insert_right ) {
				$html .= "\t\t\t<th" . tag::build_attrs( ( isset( $this->inserts[$i]['th'] ) ? $this->inserts[$i]['th'] : [] ) ) . ">{$this->inserts[$i]['header']}</th>\n";
			}
		}
		if ( count( $this->actions ) > 0 ) {
			$html .= "\t\t\t<th class=\"c-stth-header t-actions\" colspan=\"" . count( $this->actions ) . "\">Action</th>\n";
		}
		$html .= "\t\t</tr>\n";
		//result rows
		if ( $no_results ) {
			$html .= "\t\t<tr class=\"c-stt-row\">\n\t\t\t<td class=\"c-sttr-no-results\" colspan=\"{$colspan}\">" . ( $this->filtered ? $this->data['no_search_results_text'] : $this->data['no_results_text'] ) . "</td>\n\t\t</tr>\n";
		}
		else {
			foreach( $this->results as $num => $row ) {
				$class = ( $num % 2 == 0 ? 't-even' : 't-odd' );
				$html .= "\t\t<tr class=\"c-stt-row {$class}\">\n";
				if ( $action_form ) {
					$checkbox = $this->data['form']->field( $this->data['action_field'] )->get_field( $num + 1 );
					$html .= "\t\t\t<td class=\"c-sttr-checkbox\">" . $checkbox->render() . "</td>\n";
				}
				foreach( $this->columns as $i => $config ) {
					$name = ( isset( $config['name'] ) ? $config['name'] : $config['column'] );
					$data = ( !isset( $row[$name] ) ? '' : $row[$name] );
					if ( isset( $config['type'] ) ) {
						switch( $config['type'] ) {
							case 'allowed-empty':
								if ( is_null( $data ) || $data == '' ) {
									$data = 'N/A';
								}
							break;
							case 'email':
								$data = "<a href=\"mailto:{$data}\">" . ( isset( $config['label'] ) ? $config['label'] : 'Email' ) . '</a>';
							break;
							case 'date':
								$format = ( isset( $config['format'] ) ? $config['format'] : 'm-d-Y g:i a' );
								if ( $data == '0' || $data === '' ) {
									$data = 'N/A';
									break;
								}
								$data = date( $format,$data );
							break;
							case 'template':
								if ( !isset( $config['template'] ) ) {
									throw new app_exception( "No template provided for column '%s'",$name );
								}
								$data = func::fill( $config['template'],array( compact('data') ) );
							break;
							case 'max-length':
								if ( !isset( $config['length'] ) ) {
									throw new app_exception( "Length not provided for column '%s'",$name );
								}
								$data = substr( $data,0,( $config['length'] - 3 ) ) . '…';
							break;
							case 'boolean':
								if ( !in_array( $data,array('0','1') ) ) {
									throw new app_exception('Data not boolean');
								}
								$data = ( $data == '0' ? ( isset( $config['false'] ) ? $config['false'] : 'N' ) : ( isset( $config['true'] ) ? $config['true'] : 'Y' ) );
							break;
							case 'array':
								$data = ( isset( $config['array'][$data] ) ? $config['array'][$data] : 'N/A' );
							break;
							case 'function':
								$data = call_user_func_array( $config['function'],array( $data,&$config,&$row ) );
							break;
							default:
								throw new app_exception( "Invalid data type '%s'",$config['type'] );
							break;
						}
					}
					if ( isset( $this->inserts[$i] ) && $this->inserts[$i]['position'] == self::insert_left ) {
						$tag = tag::create('td')->inner_content( $this->inserts[$i]['data'] );
						if ( isset( $this->inserts[$i]['td'] ) ) {
							call_user_func( $this->inserts[$i]['td'],$tag );
						}
						$html .= "\t\t\t" . $tag->render() . "\n";
					}
					$tag = tag::create('td')->class_add('c-sttr-column')->inner_content( $data );
					if ( isset( $config['td'] ) ) {
						call_user_func( $config['td'],$tag );
					}
					$html .= "\t\t\t" . $tag->render() . "\n";
					if ( isset( $this->inserts[$i] ) && $this->inserts[$i]['position'] == self::insert_right ) {
						$tag = tag::create('td')->inner_content( $this->inserts[$i]['data'] );
						if ( isset( $this->inserts[$i]['td'] ) ) {
							call_user_func( $this->inserts[$i]['td'],$tag );
						}
						$html .= "\t\t\t" . $tag->render() . "\n";
					}
				}
				if ( count( $this->actions ) > 0 ) {
					$actions = '';
					foreach( $this->actions as $i => $data ) {
						if ( is_string( $data['uri'] ) ) {
							foreach( $row as $key => $value ) {
								$data['uri'] = str_replace( "{{$key}}",$value,$data['uri'] );
							}
							$uri_config = array(
								'csm' => true
							);
							if ( isset( $data['uri_config'] ) ) {
								$uri_config = array_func::merge_recursive_distinct( $uri_config,$data['uri_config'] );
							}
							$url = uri::create( $data['uri'],$uri_config );
						}
						elseif ( $data['uri'] instanceof \Closure ) {
							$url = call_user_func( $data['uri'],$row );
						}
						if ( isset( $data['icon'] ) ) {
							$text = '<img class="c-sttral-icon" src="' . asset::image( $this->data['assets']['icons'] . $data['icon'],array('resize'=>array('width'=>20,'height'=>20,'prop'=>true)) ) . '" alt="' . $data['title'] . '" />';
						}
						else {
							$text = $data['title'];
						}
						$html .= "\t\t\t<td class=\"c-sttr-action\"><a class=\"c-sttra-link\" title=\"{$data['title']}\" href=\"{$url}\">{$text}</a></td>\n";
					}
				}
				$html .= "\t\t</tr>\n";
			}
		}
		if ( !$no_results && ( $action_form || !is_null( $this->paginate ) ) ) {
			$html .= "\t\t<tr>\n\t\t\t<td class=\"c-stt-footer\" colspan=\"{$colspan}\">\n" . ( $action_form ? "\t\t\t\t<div class=\"c-sttf-actions\">" . $this->data['form']->field('action')->render() . $this->data['form']->field('action_submit')->render() . '</div>' . "\n" : '' ) . ( !is_null( $this->paginate ) ? "\t\t\t\t" . $this->paginate->output() . "\n" : '' ) . "</td>\n\t\t</tr>\n";
		}
		$html .= "\t</table>\n";
		if ( $action_form || $this->data['search'] ) {
			$html .= $this->data['form']->close();
			if ( $action_form ) {
				$html .= '<script type="text/javascript">sortable_table.init(\'' . $this->data['id'] . "');</script>\n";
			}
		}
		$html .= '</div>';
		$this->html = $html;
		return $this;
	}

	public function output() {
		if ( is_null( $this->html ) ) {
			$this->build();
		}
		return $this->html;
	}

}

?>