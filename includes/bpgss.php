<?php

class BPGSS {
	protected $search_terms;
	protected $found_data;
	protected $hidden_group_ids = array();

	protected function __construct() {
		add_filter( 'bp_has_groups', array( $this, 'add_found_data_to_loop' ) );
		add_filter( 'bp_ajax_querystring', array( $this, 'catch_querystring' ), 100, 2 );
		add_action( 'bp_before_directory_groups_list', array( $this, 'currently_searching_notice' ) );
		add_filter( 'bp_get_group_description_excerpt', array( $this, 'show_matches' ) );
	}

	public function catch_querystring( $qs, $object ) {
		if ( 'groups' !== $object ) {
			return $qs;
		}

		// Groups directory only
		if ( ! bp_is_directory() ) {
			return $qs;
		}

		wp_parse_str( $qs, $query );

		// Nothing to do
		if ( empty( $query['search_terms'] ) ) {
			return $qs;
		}

		$search_terms = stripslashes( $query['search_terms'] );
		if ( isset( $query['include'] ) ) {
			$query['include'] = array_intersect( wp_parse_id_list( $query['include'] ), $this->get_matching_groups( $search_terms ) );
		} else {
			$query['include'] = $this->get_matching_groups( $search_terms );
		}

		$this->search_terms = $search_terms;

		// Don't let BP see it, but store it for later
		unset( $query['search_terms'] );
		$query['_search_terms'] = $search_terms;

		// We are already filtering for hidden groups
		$query['show_hidden'] = '1';

		return build_query( $query );
	}

	public function get_matching_groups( $search_terms ) {
		$this->set_up_hidden_groups();

		// Replicate BP search
		$this->find_group_data( $search_terms );

		// Forums
		if ( bp_is_active( 'forums' ) ) {
			$this->find_forum_data( $search_terms );
		}

		// Docs
		if ( class_exists( 'BP_Docs' ) ) {
			$this->find_docs_data( $search_terms );
		}

		// @todo - Files? Activity?

		return array_keys( $this->found_data );
	}

	public function add_found_data_to_loop( $has_groups ) {
		global $groups_template;

		if ( ! empty( $this->found_data ) ) {
			$groups_template->is_search_results = true;

			foreach ( $groups_template->groups as &$g ) {
				$g->found_data = $this->found_data[ $g->id ];
			}
		}

		return $has_groups;
	}

	/**
	 * Get groups that are hidden to the current user.
	 */
	public function set_up_hidden_groups() {
		global $wpdb;

		if ( current_user_can( 'bp_moderate' ) ) {
			return;
		}

		$bp = buddypress();

		$non_public_groups = $wpdb->get_col( "SELECT id FROM {$bp->groups->table_name} WHERE status != 'public'" );

		if ( ! is_user_logged_in() ) {
			$this->hidden_group_ids = wp_parse_id_list( $non_public_groups );
			return;
		}

		$non_public_groups_sql = implode( ',', $non_public_groups );
		$my_non_public_groups  = $wpdb->get_col( $wpdb->prepare( "SELECT group_id FROM {$bp->groups->table_name_members} WHERE is_confirmed = 1 AND is_banned = 0 AND group_id IN ({$non_public_groups_sql}) AND user_id = %d", bp_loggedin_user_id() ) );

		$this->hidden_group_ids = wp_parse_id_list( array_diff( $non_public_groups, $my_non_public_groups ) );
	}

	/**
	 * Replicate BP's search_terms
	 */
	public function find_group_data( $search_terms ) {
		global $wpdb;

		$bp = buddypress();

		$hg_sql = implode( ',', $this->hidden_group_ids );
		$st_clean = esc_sql( like_escape( $search_terms ) );
		$hits = $wpdb->get_col( "SELECT id FROM {$bp->groups->table_name} WHERE ( name LIKE '%{$st_clean}%' OR description LIKE '%{$st_clean}%' ) AND id NOT IN ({$hg_sql})" );

		foreach ( $hits as $hit ) {
			$this->store_hit( intval( $hit ) );
		}
	}

	/**
	 * bp-forums matches (legacy)
	 */
	public function find_forum_data( $search_terms ) {
		global $wpdb, $bbdb;

		do_action( 'bbpress_init' );

		$bp = buddypress();

		// Get hidden forum IDs
		$hg_sql = implode( ',', $this->hidden_group_ids );
		$hidden_forum_ids = wp_parse_id_list( $wpdb->get_col( "SELECT meta_value FROM {$bp->groups->table_name_groupmeta} WHERE meta_key = 'forum_id' AND group_id IN ({$hg_sql})" ) );

		// Get matching topics
		$hf_sql = implode( ',', $hidden_forum_ids );
		$st_clean = esc_sql( like_escape( $search_terms ) );
		$results = $wpdb->get_results( "SELECT p.topic_id, p.forum_id, p.post_text, t.topic_title, t.topic_slug FROM {$bbdb->posts} p JOIN {$bbdb->topics} t ON p.topic_id = t.topic_id WHERE p.forum_id NOT IN ({$hf_sql}) AND ( p.post_text LIKE '%{$st_clean}%' OR t.topic_title LIKE '%{$st_clean}%' ) ORDER BY p.post_id DESC" );

		// Key by group
		$non_hidden_forum_ids = $wpdb->get_results( "SELECT group_id, meta_value FROM {$bp->groups->table_name_groupmeta} WHERE meta_key = 'forum_id' AND group_id NOT IN ({$hg_sql})" );

		$gf_index = array();
		foreach ( $non_hidden_forum_ids as $data ) {
			$gf_index[ $data->meta_value ] = intval( $data->group_id );
		}

		// get group slugs corresponding to the forums to build permalinks
		$found_forum_ids_sql = implode( ',', wp_list_pluck( $results, 'forum_id' ) );
		$found_group_slugs = $wpdb->get_results( "SELECT m.group_id, m.meta_value, g.slug FROM {$bp->groups->table_name_groupmeta} m JOIN {$bp->groups->table_name} g ON g.id = m.group_id WHERE meta_key = 'forum_id' AND meta_value IN ({$found_forum_ids_sql})" );

		$groups_root = bp_get_root_domain() . '/' . bp_get_groups_root_slug() . '/';
		foreach ( $results as $r ) {
			$gid = isset( $gf_index[ $r->forum_id ] ) ? $gf_index[ $r->forum_id ] : 0;

			$slug = '';
			foreach ( $found_group_slugs as $fgslug ) {
				if ( $gid == $fgslug->group_id ) {
					$slug  = $fgslug->slug;
					break;
				}
			}

			$data = array(
				'title' => $r->topic_title,
				'url' => $groups_root . $slug . '/forum/topic/' . $r->topic_slug . '/',
				'excerpt' => $this->create_excerpt( $r->post_text, $search_terms ),
			);

			$this->store_hit( $gid, 'forums', $data );
		}

		unset( $results );
	}

	public function find_docs_data( $search_terms ) {
		global $wpdb;

		$bp = buddypress();

		// First find all matching docs
		$docs = get_posts( array(
			'post_type' => 'bp_doc',
			's' => $search_terms,
		) );

		// Get object terms for those docs to filter out
		// those the user doesn't have access to
		// NOTE this will only work for legacy BP Docs
		$terms = wp_get_object_terms( wp_list_pluck( $docs, 'ID' ), $bp->bp_docs->associated_item_tax_name, array( 'fields' => 'all_with_object_id' ) );

		foreach ( $terms as $t ) {
			// Off limits
			if ( in_array( $t->name, $this->hidden_group_ids ) ) {
				// Find and unset
				foreach ( $docs as $doc_k => $doc ) {
					if ( $doc->ID === $t->object_id ) {
						unset( $docs[ $doc_k ] );
						continue 2;
					}
				}
			}
		}

		foreach ( $docs as $doc ) {
			// Gah
			foreach ( $terms as $t ) {
				if ( $doc->ID === $t->object_id ) {
					$gid = intval( $t->name );
					break;
				}
			}

			$data = array(
				'title' => $doc->post_title,
				'url' => bp_docs_get_doc_link( $doc->ID ),
				'excerpt' => $this->create_excerpt( $doc->post_content, $search_terms ),
			);

			$this->store_hit( $gid, 'docs', $data );
		}
	}

	public function store_hit( $gid, $type = '', $data = '' ) {
		if ( empty( $gid ) ) {
			return;
		}

		if ( ! isset( $this->found_data[ $gid ] ) ) {
			$this->found_data[ $gid ] = array();
		}

		if ( empty( $type ) ) {
			return;
		}

		if ( ! isset( $this->found_data[ $gid ][ $type ] ) ) {
			$this->found_data[ $gid ][ $type ] = array();
		}

		$this->found_data[ $gid ][ $type ][] = $data;
	}

	public function create_excerpt( $text, $search_terms ) {
		$fp = stripos( $text, $search_terms );

		// Take an excerpt and then trim to get to word breaks
		$start = $fp - 50;
		if ( $start < 0 ) {
			$start = 0;
		}

		$excerpt = substr( $text, $start, 100 );

		if ( $start !== 0 ) {
			$excerpt = preg_replace( '/^\S+ /ms', '', $excerpt );
		}

		if ( strlen( $excerpt ) > 100 ) {
			$excerpt = preg_replace( '/ \S+$/ms', '', $excerpt );
		}

		$excerpt = trim( $excerpt );
		$excerpt = str_replace( "\n", ' ', $excerpt );

		// highlight
		$excerpt = preg_replace( "/($search_terms)/i", '<span class="match">$1</span>', $excerpt );

		return $excerpt;
	}

	public function currently_searching_notice() {
		global $groups_template;
		if ( ! empty( $groups_template->is_search_results ) ) {
			if ( !empty( $this->search_terms ) ) {
				echo '<p class="currently-searching">Found the following results for: <span class="search-terms">' . $this->search_terms . '</span></p>';
			}
		}
	}

	public function show_matches( $excerpt ) {
		global $groups_template;

		if ( ! empty( $groups_template->group->found_data ) ) {
			$excerpt = '<div class="group-matches">';
			// Don't show more than 3 total matches
			foreach ( $groups_template->group->found_data as $type ) {
				foreach ( $type as $r ) {
					if ( $counter >= 3 ) {
						break 2;
					}

					$excerpt .= '<a href="' . $r['url'] . '">' . $r['title'] . '</a> &middot; <span class="excerpt">' . $r['excerpt'] . '</span><br />';

					$counter++;
				}
			}
			$excerpt .= '</div>';
		}

		return $excerpt;
	}

	public static function init() {
		static $instance;

		if ( empty( $instance ) ) {
			$instance = new BPGSS;
		}

		return $instance;
	}
}

add_action( 'bp_init', array( 'BPGSS', 'init' ) );
