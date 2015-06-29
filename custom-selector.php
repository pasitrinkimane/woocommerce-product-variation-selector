<?php
/**
 * Adding a product variation name
 *
 * @return void
 * @hook from woocommerce_before_single_product_summary
 */
function onireon_display_product_variation_name() {
	global $post;
	// might need a tag constructor
	$start_tag = '<span class="product_variation_name">';
	$end_tag = '</span>';
	$html_output = get_field( 'product_variation_name', $post->ID );

	echo $start_tag, $html_output, $end_tag;
}
add_action( 'woocommerce_before_single_product_summary', 'onireon_variation_closing_tag', 6 );

/**
 * Create selector from array of posts ( permalink, title )
 *
 * @return void
 * @hook woocommerce_before_single_product_summary
 */
function onireon_variation_selector() {
	$post_id = onireon_post_id();
	$post_array = onireon_get_variation_array( $post_id );
	if(empty( $post_array )) { return; } // HOUSTON, WE HAVE A PROBLEM!

	// html
	$start_tag = sprintf('<label class="custom_select">%s<select class="product_variation_selector">',
						__('disponibile anche in ', 'onireon_loc'));
	$default_selection = sprintf( '<option value="default" selected>%s</option>',
									__( 'altre essenze', 'onireon_loc' ) );
	$end_tag = '</select></label>';
	$html_output = '';

	foreach ($post_array as $post) {
		$html_output .= sprintf('<option value="%s">%s</option>',
				get_permalink( $post->ID ), $post->post_title );
	}

	echo $start_tag, $default_selection, $html_output, $end_tag;
}

function onireon_variation_opening_tag() {
	echo '<div class="product_variation_container">';
}

function onireon_variation_closing_tag() {
	echo '</div>';
}

add_action( 'woocommerce_before_single_product_summary', 'onireon_variation_opening_tag', 8 );
add_action( 'woocommerce_before_single_product_summary', 'onireon_variation_selector', 9 );
add_action( 'woocommerce_before_single_product_summary', 'onireon_variation_closing_tag', 10 );

/**
 * Filtering the shop loop in order to remove variation products
 *
 * @param array $q
 * @return void
 * @filter pre_get_posts
 */
function onireon_exclude_variaton_from_shop_loop($q) {
	
	$meta_query = $q->get('meta_query');
	$meta_query[] = array(
		'key' => 'is_product_variation',
		'value' => 'yes',
		'compare' => '!='
	);

	if( !is_admin() && is_shop() && $q->is_main_query() ) {
		$q->set( 'meta_query', $meta_query );
	}
}
add_filter( 'pre_get_posts', 'onireon_exclude_variaton_from_shop_loop' );

/**
 * Get all the variations (post object), based on passed post id
 *
 * @param int $post_id
 * @return array
 */
function onireon_get_variation_array( $post_id ) {
	// is a variation?
	$is_variation = get_field( 'is_product_variation', $post_id ) == 'yes';

	// If it is a variation, find me the object of the default product!
	$default_product = ($is_variation) ? get_field( 'is_product_variation_of', $post_id ) : get_post( $post_id );
	$id = $default_product->ID;
	
	// *** THIS IS EVIL !!!
	// delete_post_meta( $id, '_product_variation_array' );

	// start building the array
	$ret = get_post_meta( $id, '_product_variation_array', true );
	if( !$ret || empty( $ret ) ) { $ret = array(); }

	if( $is_variation ) {
		// adding the default product in the array
		$head = array( $default_product );
		// removing the variation object
		$ret = array_filter(array_merge( $head, $ret ),
			function($val) use($post_id) {
				return $val->ID != $post_id;
			}
		);
	}

	return $ret;
}

/**
 * Setting up the _product_variation_array post meta
 *
 * @param $action (string), $post (obj)
 * @return void
 */
function onireon_set_variation_array( $action, $post ) {
	if( !isset( $action ) ) { return; }
	if ( 'trash' == get_post_status( $post->ID ) ) { return; }

	$parent_post = get_field( 'is_product_variation_of', $post->ID );
	if(empty( $parent_post )) { return; } // HOUSTON, WE HAVE A PROBLEM! (parent_post deleted?)

	// get me the array with all product variations
	$variations_array = get_post_meta( $parent_post->ID, '_product_variation_array', true ); //=> TRUE!

	if( 'add' === $action ) {
		// if it is empty, create a new one
		if( empty( $variations_array ) ) { $variations_array = array(); }

		// for the sake of clarity we generate a check array with just post object's ids
		// we could've used it directly in the if statement (more functional but less evident)
		$check_array = array_map( function($elem) {
			return $elem->ID;
		}, $variations_array );

		// if the current variation isn't already in, add it
		if( !in_array( $post->ID, $check_array ) ) {
			array_push( $variations_array, $post );
		}

	} else if ( 'delete' === $action ) {

		// already deleted from array
		if ( 'trash' == get_post_status( $post->ID ) ) { return; }

		$variations_array = array_filter( $variations_array,
			function($post_obj) use($post) {
				// if the current variaiton is in the array, skip it
				if( $post_obj->ID != $post->ID ) {
					return $post_obj;
				}
			}
		);

	} else {
		return;
	}
	
	update_post_meta( $parent_post->ID, '_product_variation_array' , $variations_array );
}

/**
 * Remove attached image
 *
 */
function onireon_unattach_image($thumb) {
	$post_obj = get_post( $thumb['id'] );
	$post_parent = $post_obj->post_parent;

	if( !empty( $post_parent ) || $post_parent != 0 ) {

		$post_obj_arr = array(
			'ID' => $post_obj->ID,
			'post_parent' => 0
		);

		wp_update_post( $post_obj_arr );

		dumpit( get_post( $thumb['id'] ) );
	}
}

/**
 * Add action on creating/updating product variations
 *
 * @param $post_id (int), $post (obj)
 * @return void
 */
function onireon_save_product_variation($post_id, $post) {
	$slug = 'product';
	$acf_thumbnail = get_field( 'product_variation_img', $post_id );

	if( $post->post_type != $slug ) { return; }
	if( wp_is_post_revision( $post_id ) ) { return; }

	// if it is a post variation
	if( get_field( 'is_product_variation', $post_id ) == 'yes' ) {
		// work on the _product_variation_array
		onireon_set_variation_array( 'add', $post );
		onireon_unattach_image( $acf_thumbnail );

	} elseif ( get_field( 'is_product_variation', $post_id ) == 'no' ) {

		onireon_unattach_image( $acf_thumbnail );

	} else {
		return;
	}
}
add_action( 'save_post', 'onireon_save_product_variation', 99, 2 );

/**
 * Add action on trashing/deleting products variations
 *
 * @param $post_id (int)
 * @return void
 */
function onireon_delete_product_variation( $post_id ) {
	$post = get_post( $post_id );
	$slug = 'product';

	if( $post->post_type != $slug ) { return; }

	// if it is a post variation
	if( get_field( 'is_product_variation', $post_id ) == 'yes' ) {
		// work on the _product_variation_array
		onireon_set_variation_array( 'delete', $post );
	} else {
		return;
	}
}
add_action( 'wp_trash_post', 'onireon_delete_product_variation' );
add_action( 'before_delete_post', 'onireon_delete_product_variation' );