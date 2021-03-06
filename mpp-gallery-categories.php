<?php

/**
 * Plugin Name: MediaPress Gallery Categories
 * Plugin URI: http://buddydev.com
 * Description: This plugin create a custom taxonomy for MediaPress and allow users to filter gallery based on category on gallery directory.
 * Version: 1.0.0
 * Author: BuddyDev Team
 * Author URI: https://buddydev.com
 */

/**
 * Contributor Name: Ravi Sharma ( raviousprime )
 */

// exit if file access directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MPP_Gallery_Categories_Helper
 */
class MPP_Gallery_Categories_Helper {

	/**
     * Class instance
     *
	 * @var MPP_Gallery_Categories_Helper
	 */
	private static $instance = null;

	/**
	 * The constructor.
	 */
	private function __construct() {
		$this->setup();
	}

	/**
     * Class instance
     *
	 * @return MPP_Gallery_Categories_Helper
	 */
	public static function get_instance() {

		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
	
	/**
	 * setup the requirements
	 */
	public function setup() {

		// register custom taxonomy for mediapress gallery
		add_action( 'mpp_setup', array( $this, 'register_gallery_taxonomy' ), 99 );
		//add interface to assign gallery to any category
		add_action( 'mpp_after_edit_gallery_form_fields', array( $this, 'add_interface' ) );
		add_action( 'mpp_before_create_gallery_form_submit_field', array( $this, 'add_interface' ) );
		// assign term to gallery
		add_action( 'mpp_gallery_created', array( $this, 'save_gallery_category' ) );
		add_action( 'mpp_gallery_updated', array( $this, 'save_gallery_category' ), 8 );
		// add category filter on gallery page
		add_action( 'mpp_gallery_directory_order_options', array( $this, 'add_category_filter' ) );
		// ajax action to filter gallery
		add_action( 'wp_ajax_mpp_filter', array( $this, 'load_filter_list' ), 5 );
		add_action( 'wp_ajax_nopriv_mpp_filter', array( $this, 'load_filter_list' ), 5 );

		add_action( 'mpp_media_added', array( $this, 'save_media_categories' ), 5, 2 );
		add_action( 'mpp_gallery_updated', array( $this, 'update_media_categories' ), 10 );
	}

	/**
	 * Register a custom taxonomy for MediaPress
	 */
	public function register_gallery_taxonomy() {

		$labels = array(
			'name'          => _x( 'Gallery Categories', 'taxonomy general name', 'mpp-gallery-categories' ),
			'singular_name' => _x( 'Gallery Category', 'taxonomy singular name', 'mpp-gallery-categories' ),
			'search_items'  => __( 'Search Gallery Category', 'mpp-gallery-categories' ),
			'all_items'     => __( 'All Gallery Category', 'mpp-gallery-categories' ),
			'edit_item'     => __( 'Edit Gallery Category', 'mpp-gallery-categories' ),
			'update_item'   => __( 'Update Gallery Category', 'mpp-gallery-categories' ),
			'add_new_item'  => __( 'Add New Gallery Category', 'mpp-gallery-categories' ),
			'new_item_name' => __( 'New Gallery Category Name', 'mpp-gallery-categories' ),
		);

		$args = array(
			'hierarchical'      => true,
			'labels'            => $labels,
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => $this->get_taxonomy_slug() ),
		);

		register_taxonomy( $this->get_taxonomy_name(),  mpp_get_gallery_post_type(), $args );

		//register_taxonomy_for_object_type( $this->get_taxonomy_name(), mpp_get_gallery_post_type() );
	}

	/**
     * Get gallery slug
     *
	 * @return string
	 */
	public function get_taxonomy_slug() {
		return apply_filters( 'mpp_gallery_category_slug', 'mpp-gallery-category' );
	}

	/**
     * Get custom taxonomy name
     *
	 * @return string
	 */
	public function get_taxonomy_name() {
		return 'mpp_gallery_category';
	}

	/**
	 * Add interface to apply category to the gallery
	 */
	public function add_interface() {

		$gallery_id = mpp_get_current_gallery_id();
		$args       = array( 'taxonomy' => $this->get_taxonomy_name(), 'post_id' => $gallery_id );

		if ( $gallery_id ) {
			$term_ids              = $this->get_gallery_categories( $gallery_id );
			$args['selected_cats'] = $term_ids;
		}

		echo '<div class="mpp-u mpp-gallery-categories"><label>'.__( 'Choose Category', 'mpp_gallery_categories' ).'</label>';
		$this->category_terms_checklist( $args );
		echo '</div>';
	}

	/**
     * Get gallery categories
     *
	 * @param int $gallery_id Gallery id.
	 *
	 * @return array
	 */
	public function get_gallery_categories( $gallery_id ) {
		$terms    = wp_get_post_terms( $gallery_id, $this->get_taxonomy_name() );
		$term_ids = wp_list_pluck( $terms, 'term_id' );

		return $term_ids;
	}

	/**
     * Print checkbox style categories to select for the gallery
     *
	 * @param array $params Array of parameters.
	 *
	 * @return string
	 */
	public function category_terms_checklist( $params = array() ) {
		$defaults = array(
			'descendants_and_self' => 0,
			'selected_cats'        => false,
			'walker'               => null,
			'taxonomy'             => 'mpp_gallery_category',
			'checked_ontop'        => true,
			'echo'                 => true,
		);

		$r = wp_parse_args( $params, $defaults );

		if ( empty( $r['walker'] ) || ! ( $r['walker'] instanceof Walker ) ) {
			require_once ABSPATH . '/wp-admin/includes/class-walker-category-checklist.php';

			$walker = new Walker_Category_Checklist;
		} else {
			$walker = $r['walker'];
		}

		$taxonomy             = $r['taxonomy'];
		$descendants_and_self = (int) $r['descendants_and_self'];
		$post_id              = $r['post_id'];

		$args = array( 'taxonomy' => $taxonomy );

		$tax              = get_taxonomy( $taxonomy );
		$args['disabled'] = 0; // !current_user_can( $tax->cap->assign_terms );.

		$args['list_only'] = ! empty( $r['list_only'] );

		if ( is_array( $r['selected_cats'] ) ) {
			$args['selected_cats'] = $r['selected_cats'];
		} elseif ( $post_id ) {
			$args['selected_cats'] = wp_get_object_terms( $post_id, $taxonomy, array_merge( $args, array( 'fields' => 'ids' ) ) );
		} else {
			$args['selected_cats'] = array();
		}

		if ( $descendants_and_self ) {
			$categories = (array) get_terms( $taxonomy, array(
				'child_of'     => $descendants_and_self,
				'hierarchical' => 0,
				'hide_empty'   => 0
			) );
			$self       = get_term( $descendants_and_self, $taxonomy );
			array_unshift( $categories, $self );
		} else {
			$categories = (array) get_terms( $taxonomy, array( 'get' => 'all' ) );
		}

		$output = '<ul class="mpp-u mpp-gallery-categories">';

		if ( $r['checked_ontop'] ) {
			// Post process $categories rather than adding an exclude to the get_terms() query to keep the query the same across all posts (for any query cache)
			$checked_categories = array();
			$keys               = array_keys( $categories );

			foreach ( $keys as $k ) {
				if ( in_array( $categories[ $k ]->term_id, $args['selected_cats'] ) ) {
					$checked_categories[] = $categories[ $k ];
					unset( $categories[ $k ] );
				}
			}

			// Put checked cats on top
			$output .= call_user_func_array( array( $walker, 'walk' ), array( $checked_categories, 0, $args ) );
		}
		// Then the rest of them
		$output .= call_user_func_array( array( $walker, 'walk' ), array( $categories, 0, $args ) );
		$output .= '</ul>';

		if ( $r['echo'] ) {
			echo $output;
			?>
			<style type="text/css">.children { margin-left: 20px; }ul.mpp-gallery-categories{list-style: none;}</style>
			<?php
		}

		return $output;
	}

	/**
     * Save gallery category
     *
	 * @param int $gallery_id Gallery id.
	 */
	public function save_gallery_category( $gallery_id ) {
		$terms    = $_POST['tax_input'];
		$taxonomy = $this->get_taxonomy_name();

		if ( empty( $terms ) || empty( $terms[ $taxonomy ] ) ) {
			return;
		}

		$terms = $terms[ $taxonomy ];
		$terms = array_map( 'absint', $terms );

		wp_set_object_terms( $gallery_id, $terms, $taxonomy, false );
	}

	/**
	 * Add category filter to gallery listing directory.
	 */
	public function add_category_filter(){
		$args = array(
			'hide_empty' => 0,
			'fields'     => 'id=>name',
			'taxonomy'   => $this->get_taxonomy_name()
		);

		$terms = get_terms( $args );

		?>
		<?php if ( ! empty( $terms ) ) : ?>
			<optgroup label="<?php _e( 'By Category', 'mpp-gallery-categories' ) ?>">
				<?php foreach ( ( array ) $terms as $id => $name ) : ?>
					<option value="mpp-gallery-category-<?php echo $id?>">
						<?php echo $name; ?>
					</option>
				<?php endforeach;?>
			</optgroup>
		<?php endif; ?>
		<?php
	}

	/**
	 * Load filter list
	 */
	public function load_filter_list () {
		$type = isset( $_POST['filter'] ) ? $_POST['filter'] : '';

		if ( strpos( $type, 'mpp-gallery-category-' ) === false ) {
			return ;
		}

		$cat_id = absint( str_replace( 'mpp-gallery-category-', '', $type  ) );

		$page         = absint( $_POST['page'] );
		$scope        = $_POST['scope'];
		$search_terms = $_POST['search_terms'];

		//make the query and setup
		mediapress()->is_directory = true;

		//get all public galleries, should we do type filtering
		mediapress()->the_gallery_query = new MPP_Gallery_Query( array(
			'status'       => 'public',
			'type'         => $type,
			'page'         => $page,
			'search_terms' => $search_terms,
			'tax_query'    => array(
				array(
					'taxonomy' => $this->get_taxonomy_name(),
					'field'    => 'term_id',
					'terms'    => $cat_id,
				),
			),
		) );

		mpp_get_template( 'gallery/loop-gallery.php' );

		exit( 0 );
	}

	/**
     * Save media categories
     *
	 * @param int $media_id Media id.
	 * @param int $gallery_id Gallery id.
	 */
	public function save_media_categories( $media_id, $gallery_id ) {
		$terms = wp_get_post_terms( $gallery_id, $this->get_taxonomy_name() );
		$terms = wp_list_pluck( $terms, 'term_id' );

		wp_set_object_terms( $media_id, $terms, $this->get_taxonomy_name(), false );
	}

	/**
     * Update media categories
     *
	 * @param int $gallery_id Gallery id.
	 */
	public function update_media_categories( $gallery_id ) {

		if ( ! $gallery_id ) {
			return;
		}

		$attachment_ids = get_children( array( 'post_parent' => $gallery_id, 'post_type' => 'attachment' ) );
		$media_ids      = wp_list_pluck( $attachment_ids, 'ID' );

		if ( ! $media_ids ) {
			return;
		}

		$taxonomy_name = $this->get_taxonomy_name();
		$terms         = wp_get_post_terms( $gallery_id, $taxonomy_name );
		$terms         = wp_list_pluck( $terms, 'term_id' );

		foreach ( $media_ids as $media_id ) {
			wp_set_object_terms( $media_id, $terms, $taxonomy_name, false );
		}
	}

}

mpp_gallery_categories();

function mpp_gallery_categories() {
	return MPP_Gallery_Categories_Helper::get_instance();
}


