<?php
/*
 * Plugin Name: Private Tags
 * Version: 0.1
 * Plugin URI: http://michael.tyson.id.au/wordpress/plugins/private-tags
 * Description: Hide posts with certain tags/categories from the public and other authors
 * Author: Michael Tyson
 * Author URI: http://michael.tyson.id.au
 */



/**
 * Filter for WHERE clause on posts
 *
 *  If user is not administrator, adds a condition to only return posts that are not
 *  tagged with specified private tags.
 *
 * @param WHERE clause
 * @return Modified WHERE clause
 * @author Michael Tyson
 * @package Private Tags
 * @since 0.1
 **/
function private_tags_posts_where($query) {
    
    global $user_ID;
    global $wpdb;

    $exclusive = (get_option('private_tags_mode')=='exclusive');
    
    // Build tag list
    $tag_array = preg_split('/\s*,\s*/', strtolower(($exclusive ? get_option('private_tags') : get_option('public_tags'))));
    
    $tag_list = "";
    foreach ( $tag_array as $tag )
        $tag_list .= ($tag_list?', ':'')."'".mysql_real_escape_string($tag)."'";

    if ( $exclusive ) {
        // Following is derived from query.php:1897 (for handling of tag__not_in)
        $subQuery = "SELECT tr.object_id FROM $wpdb->term_relationships AS tr "
                        ."INNER JOIN $wpdb->term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id "
                        ."INNER JOIN $wpdb->terms AS t ON (tt.term_id = t.term_id) "
                        ."WHERE t.slug IN ($tag_list)";
                        
        if ( $wpdb->has_cap( 'subqueries' ) ) {
            // Subqueries supported
			$query .= " AND ($wpdb->posts.ID NOT IN ($subQuery)".($user_ID ? " OR $wpdb->posts.post_author = '".mysql_real_escape_string($user_ID)."'" : "").")";
		} else {
		    // No subqueries - do it in two steps
		    $ids = $wpdb->get_col($subQuery);
			if ( is_wp_error( $ids ) )
				$ids = array();
			if ( is_array($ids) && count($ids > 0) ) {
				$out_posts = "'" . implode("', '", $ids) . "'";
				$query .= " AND ($wpdb->posts.ID NOT IN ($out_posts)".($user_ID ? " OR $wpdb->posts.post_author = '".mysql_real_escape_string($user_ID)."' " : "").")";
			}
		}
    } else {
        $query .= " AND ("
                        ."$wpdb->terms.slug IN ($tag_list) "
                        .($user_ID ? " OR $wpdb->posts.post_author = '".mysql_real_escape_string($user_ID)."' " : "")
                    .") ";
    }
    
    return $query;
}



/**
 * Filter for JOIN part of query
 *
 *  Ensures that tags are joined to the query so we can select on them.
 *
 * @param JOIN clause
 * @return Modified JOIN clause
 * @author Michael Tyson
 * @package Private Tags
 * @since 0.1
 */
function private_tags_posts_join($query) {

    // Exclusive mode doesn't require join
    if ( get_option('private_tags_mode') == 'exclusive' ) return $query;

    global $wpdb;

    // The following parts are derived from wp-admin/query.php:1887 (for handling tag_slug__in)
    
    if ( !$query || strpos($query, "INNER JOIN $wpdb->term_relationships ON") === false ) {
		$query .= " INNER JOIN $wpdb->term_relationships ON ($wpdb->posts.ID = $wpdb->term_relationships.object_id) ";
    }
    
    if ( !$query || strpos($query, "INNER JOIN $wpdb->term_taxonomy ON") === false ) {
		$query .= " INNER JOIN $wpdb->term_taxonomy ON ($wpdb->term_relationships.term_taxonomy_id = $wpdb->term_taxonomy.term_taxonomy_id) ";
    }
    
    if ( !$query || strpos($query, "INNER JOIN $wpdb->terms ON") === false ) {
		$query .= " INNER JOIN $wpdb->terms ON ($wpdb->term_taxonomy.term_id = $wpdb->terms.term_id) ";
    }
    
    return $query;
}


/**
 * Filter for DISTINCT part of query
 *
 *  Ensures that rows returned are distinct
 *
 * @param DISTINCT clause
 * @return Modified DISTINCT clause
 * @author Michael Tyson
 * @package Private Tags
 * @since 0.1
 */
function private_tags_posts_distinct($query) {
    
    // Exclusive mode doesn't require join and thus doesn't require distinct
    if ( get_option('private_tags_mode') == 'exclusive' ) return $query;
    
    return " DISTINCT ";
}



/**
 * Filter for terms (tags/categories)
 *
 *  Filters out private tags/categories
 *
 * @param List of tags/categories
 * @return Filtered tags/categories
 * @author Michael Tyson
 * @package Private Tags
 * @since 0.1
 */
function private_tags_get_terms($terms) {
    
    global $user_ID;
    if ( $user_ID ) {
        // Logged in - show all terms
        return $terms;
    }
    
    // Not logged in - trim out private terms
    $exclusive = (get_option('private_tags_mode')=='exclusive');
    $term_array = preg_split('/\s*,\s*/', strtolower(($exclusive ? get_option('private_tags') : get_option('public_tags'))));
    
    $terms_out = array();
    foreach ( $terms as $term ) {
        if ( ( $exclusive && !in_array($term->slug, $term_array) ) ||
             (!$exclusive &&  in_array($term->slug, $term_array) ) ) {
             $terms_out[] = $term;
         }
    }
    
    return $terms_out;
}


// =======================
// =       Options       =
// =======================

/**
 * Settings page
 *
 * @author Michael Tyson
 * @package Private Tags
 * @since 0.1
 **/
function private_tags_options_page() {
    ?>
	<div class="wrap">
	<h2>Private Tags</h2>
	
	<form method="post" action="options.php">
	<?php wp_nonce_field('update-options'); ?>
	
	<table class="form-table">

		<tr valign="top">
    		<th scope="row"><?php _e('Mode:') ?></th>
    		<td>
    			<input type="radio" name="private_tags_mode" value="exclusive" id="pt_exclusive" <?php echo (get_option('private_tags_mode')=='exclusive'?'checked':'') ?>
    			    onclick="jQuery('#private_tags').attr('disabled', !this.checked); jQuery('#public_tags').attr('disabled', this.checked);">
    			    <label for="pt_exclusive"><?php _e('Exclusive (hide posts with specified tags)', 'private_tags')?></label><br />

    			<input type="radio" name="private_tags_mode" value="inclusive" id="pt_inclusive" <?php echo (get_option('private_tags_mode')=='inclusive'?'checked':'') ?>
        			onclick="jQuery('#private_tags').attr('disabled', this.checked); jQuery('#public_tags').attr('disabled', !this.checked);">
    			    <label for="pt_inclusive"><?php _e('Inclusive (only show posts with specified tags)', 'private_tags')?></label><br />
    		</td>
    	</tr>
	
		<tr valign="top">
    		<th scope="row"><?php _e('Excluded Tags:') ?></th>
    		<td>
    			<input type="text" id="private_tags" name="private_tags" value="<?php echo get_option('private_tags') ?>" <?php echo (get_option('private_tags_mode')=='inclusive' ? 'disabled' : '') ?> /><br />
    			<?php echo _e('Separate multiple tags with commas', 'private-tags'); ?>
    		</td>
    	</tr>

		<tr valign="top">
    		<th scope="row"><?php _e('Included Tags:') ?></th>
    		<td>
    			<input type="text" id="public_tags" name="public_tags" value="<?php echo get_option('public_tags'); ?>" <?php echo (get_option('private_tags_mode')=='exclusive' ? 'disabled' : '') ?> /><br />
    			<?php echo _e('Separate multiple tags with commas', 'private-tags'); ?>
    		</td>
    	</tr>

	
	</table>
	<input type="hidden" name="action" value="update" />
	<input type="hidden" name="page_options" value="private_tags_mode, private_tags, public_tags" />
	
	<p class="submit">
	<input type="submit" name="Submit" value="<?php _e('Save Changes', 'private-tags') ?>" />
	</p>
	
	</form>
	</div>
	<?php
}

/**
 * Set up administration
 *
 * @author Michael Tyson
 * @package Private Tags
 * @since 0.1
 */
function private_tags_setup_admin() {
	add_options_page( 'Private Tags', 'Private Tags', 5, __FILE__, 'private_tags_options_page' );
}


/**
 * Set up administration javascript
 *
 * @author Michael Tyson
 * @package Private Tags
 * @since 0.1
 */
function private_tags_setup_admin_scripts() {
	wp_enqueue_script('jquery');
}


add_filter( 'posts_where', 'private_tags_posts_where' );
add_filter( 'posts_join', 'private_tags_posts_join' );
add_filter( 'posts_distinct', 'private_tags_posts_distinct' );
add_filter( 'get_terms', 'private_tags_get_terms' );

add_action( 'admin_menu', 'private_tags_setup_admin' );
add_action( 'admin_print_scripts', 'private_tags_setup_admin_scripts' );

add_option( 'private_tags_mode', 'exclusive' );
add_option( 'private_tags', 'Private' );
add_option( 'public_tags', 'Public' );

?>