<?php
/*
Plugin Name: Page Links To
Plugin URI: http://txfx.net/wordpress-plugins/page-links-to/
Description: Allows you to point WordPress pages or posts to a URL of your choosing.  Good for setting up navigational links to non-WP sections of your site or to off-site resources.
Version: 2.9-beta
Author: Mark Jaquith
Author URI: http://coveredwebservices.com/
Text Domain: page-links-to
Domain Path: /languages
*/

/*  Copyright 2005-2013  Mark Jaquith

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

class CWS_PageLinksTo {
	static $instance;
	var $targets;
	var $links;
	var $targets_on_this_page = array();

	function __construct() {
		self::$instance = $this;
		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * Bootstraps the upgrade process and registers all the hooks.
	 */
	function init() {
		$this->maybe_upgrade();

		load_plugin_textdomain( 'page-links-to', false, basename( dirname( __FILE__ ) ) . '/languages' );

		add_filter( 'wp_list_pages',       array( $this, 'wp_list_pages'       )        );
		add_action( 'template_redirect',   array( $this, 'template_redirect'   )        );
		add_filter( 'page_link',           array( $this, 'link'                ), 20, 2 );
		add_filter( 'post_link',           array( $this, 'link'                ), 20, 2 );
		add_filter( 'post_type_link',      array( $this, 'link',               ), 20, 2 );
		add_action( 'do_meta_boxes',       array( $this, 'do_meta_boxes'       ), 20, 2 );
		add_action( 'save_post',           array( $this, 'save_post'           )        );
		add_filter( 'wp_nav_menu_objects', array( $this, 'wp_nav_menu_objects' ), 10, 2 );
		add_action( 'load-post.php',       array( $this, 'load_post'           )        );
		add_action( 'wp_footer',           array( $this, 'enqueue_scripts'     ), 19    );
		add_filter( 'plugin_row_meta',     array( $this, 'set_plugin_meta'     ), 10, 2 );
	}

 /**
  * Performs an upgrade for older versions
  *
  * * Version 3: Underscores the keys so they only show in the plugin's UI.
  */
	function maybe_upgrade() {
		if ( get_option( 'txfx_plt_schema_version' ) < 3 ) {
			global $wpdb;
			$total_affected = 0;
			foreach ( array( '', '_target', '_type' ) as $meta_key ) {
				$meta_key = 'links_to' . $meta_key;
				$affected = $wpdb->update( $wpdb->postmeta, array( 'meta_key' => '_' . $meta_key ), compact( 'meta_key' ) );
				if ( $affected )
					$total_affected += $affected;
			}
			// Only flush the cache if something changed
			if ( $total_affected > 0 )
				wp_cache_flush();
			$this->flush_if( update_option( 'txfx_plt_schema_version', 3 ) );
		}
	}

	/**
	 * Enqueues jQuery, if we think we are going to need it
	 */
	function enqueue_scripts() {
		if ( $this->targets_on_this_page )
			wp_enqueue_script( 'jquery' );
	}

	/**
	 * Returns post ids and meta values that have a given key
	 *
	 * @param string $key post meta key (limited to '_links_to' and '_links_to_target')
	 * @return array an array of objects with post_id and meta_value properties
	 */
	function meta_by_key( $key ) {
		global $wpdb;
		if ( !in_array( $key, array( '_links_to', '_links_to_target' ) ) )
			return false;
		$cache_key = 'plt_meta_cache_' . $key;
		if ( ! $meta = get_transient( $cache_key ) ) {
			$meta = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = %s", $key ) );
			set_transient( $cache_key, $meta );
		}
		return $meta;
	}

	/**
	 * Returns all links for the current site
	 *
	 * @return array an array of links, keyed by post ID
	 */
	function get_links() {
		global $wpdb, $blog_id;

		if ( !isset( $this->links[$blog_id] ) )
			$links_to = $this->meta_by_key( '_links_to' );
		else
			return $this->links[$blog_id];

		if ( !$links_to ) {
			$this->links[$blog_id] = false;
			return false;
		}

		foreach ( (array) $links_to as $link )
			$this->links[$blog_id][$link->post_id] = $link->meta_value;

		return $this->links[$blog_id];
	}

	/**
	 * Returns the link for the specified post ID
	 *
	 * @param  integer $post_id a post ID
	 * @return mixed either a URL or false
	 */
	function get_link( $post_id ) {
		$links = $this->get_links();
		if ( isset( $links[$post_id] ) )
			return $links[$post_id];
		else
			return false;
	}

	/**
	 * Returns the link for the specified post ID
	 *
	 * @param  integer $post_id a post ID
	 * @return mixed either a URL or false
	 */
	function get_target( $post_id ) {
		$targets = $this->get_targets();
		if ( isset( $targets[$post_id] ) )
			return $targets[$post_id];
		else
			return false;
	}

	/**
	 * Returns all targets for the current site
	 *
	 * @return array an array of targets, keyed by post ID
	 */
	function get_targets() {
		global $wpdb, $blog_id;

		if ( ! isset( $this->targets[$blog_id] ) )
			$links_to = $this->meta_by_key( '_links_to_target' );
		else
			return $this->targets[$blog_id];

		if ( ! $links_to ) {
			$this->targets[$blog_id] = false;
			return false;
		}

		foreach ( (array) $links_to as $link )
			$this->targets[$blog_id][$link->post_id] = $link->meta_value;

		return $this->targets[$blog_id];
	}

	/**
	 * Adds the meta box to the post or page edit screen
	 *
	 * @param string $page the name of the current page
	 * @param string $context the current context
	 */
	function do_meta_boxes( $page, $context ) {
		// Plugins that use custom post types can use this filter to hide the
		// PLT UI in their post type.
		$plt_post_types = apply_filters( 'page-links-to-post-types', array_keys( get_post_types( array('show_ui' => true ) ) ) );

		if ( in_array( $page, $plt_post_types ) && 'advanced' === $context )
			add_meta_box( 'page-links-to', _x( 'Page Links To', 'Meta box title', 'page-links-to'), array( $this, 'meta_box' ), $page, 'advanced', 'low' );
	}

	/**
	 * Outputs the Page Links To post screen meta box
	 */
	function meta_box() {
		$post = get_post();
		echo '<p>';
		wp_nonce_field( 'txfx_plt', '_txfx_pl2_nonce', false, true );
		echo '</p>';
		$url = get_post_meta( $post->ID, '_links_to', true);
		if ( !$url ) {
			$linked = false;
			$url = 'http://';
		} else {
			$linked = true;
		}
	?>
		<p><?php _e( 'Point this content to:', 'page-links-to' ); ?></p>
		<p><label><input type="radio" id="txfx-links-to-choose-wp" name="txfx_links_to_choice" value="wp" <?php checked( !$linked ); ?> /> <?php _e( 'Its normal WordPress URL', 'page-links-to' ); ?></label></p>
		<p><label><input type="radio" id="txfx-links-to-choose-custom" name="txfx_links_to_choice" value="custom" <?php checked( $linked ); ?> /> <?php _e( 'A custom URL', 'page-links-to' ); ?></label></p>
		<div style="webkit-box-sizing:border-box;-moz-box-sizing:border-box;box-sizing:border-box;margin-left: 30px;" id="txfx-links-to-custom-section" class="<?php echo !$linked ? 'hide-if-js' : ''; ?>">
			<p><input name="txfx_links_to" type="text" style="width:75%" id="txfx-links-to" value="<?php echo esc_attr( $url ); ?>" /></p>
			<p><label for="txfx-links-to-new-tab"><input type="checkbox" name="txfx_links_to_new_tab" id="txfx-links-to-new-tab" value="_blank" <?php checked( '_blank', get_post_meta( $post->ID, '_links_to_target', true ) ); ?>> <?php _e( 'Open this link in a new tab', 'page-links-to' ); ?></label></p>
		</div>
		<script src="<?php echo trailingslashit( plugin_dir_url( __FILE__ ) ) . 'js/page-links-to.js?v=3'; ?>"></script>
	<?php
	}

	/**
	 * Saves data on post save
	 *
	 * @param int $post_id a post ID
	 * @return int the post ID that was passed in
	 */
	function save_post( $post_id ) {
		if ( isset( $_REQUEST['_txfx_pl2_nonce'] ) && wp_verify_nonce( $_REQUEST['_txfx_pl2_nonce'], 'txfx_plt' ) ) {
			if ( ( ! isset( $_POST['txfx_links_to_choice'] ) || 'custom' == $_POST['txfx_links_to_choice'] ) && isset( $_POST['txfx_links_to'] ) && strlen( $_POST['txfx_links_to'] ) > 0 && $_POST['txfx_links_to'] !== 'http://' ) {
				$url = $this->clean_url( stripslashes( $_POST['txfx_links_to'] ) );
				$this->set_link( $post_id, $url );
				if ( isset( $_POST['txfx_links_to_new_tab'] ) )
					$this->set_link_new_tab( $post_id );
				else
					$this->set_link_same_tab( $post_id );
			} else {
				$this->delete_link( $post_id );
			}
		}
		return $post_id;
	}

	/**
	 * Cleans up a URL
	 *
	 * @param string $url URL
	 * @return string cleaned up URL
	 */
	function clean_url( $url ) {
		$url = trim( $url );
		// Starts with 'www.'. Probably a mistake. So add 'http://'.
		if ( 0 === strpos( $url, 'www.' ) )
			$url = 'http://' . $url;
		return $url;
	}

	/**
	 * Have a post point to a custom URL
	 *
	 * @param int $post_id post ID
	 * @param string $url the URL to point the post to
	 */
	function set_link( $post_id, $url ) {
		$this->flush_links_if( update_post_meta( $post_id, '_links_to', $url ) );
	}

	/**
	 * Tell an custom URL post to open in a new tab
	 *
	 * @param int $post_id post ID
	 */
	function set_link_new_tab( $post_id ) {
		$this->flush_targets_if( update_post_meta( $post_id, '_links_to_target', '_blank' ) );
	}

	/**
	 * Tell an custom URL post to open in the same tab
	 *
	 * @param int $post_id post ID
	 */
	function set_link_same_tab( $post_id ) {
		$this->flush_targets_if( delete_post_meta( $post_id, '_links_to_target' ) );
	}

	/**
	 * Discard a custom URL and point a post to its normal URL
	 *
	 * @param int $post_id post ID
	 */
	function delete_link( $post_id ) {
		$this->flush_links_if( delete_post_meta( $post_id, '_links_to' ) );
		$this->flush_targets_if( delete_post_meta( $post_id, '_links_to_target' ) );

		// Old, unused data that we can delete on the fly
		delete_post_meta( $post_id, '_links_to_type' );
	}

	/**
	 * Flushes the links transient cache if the condition is true
	 *
	 * @param bool $condition whether to proceed with the flush
	 * @return bool whether the flush happened
	 */
	function flush_links_if( $condition ) {
		return $this->flush_if( $condition, 'links' );
	}

	/**
	 * Flushes the targets transient cache if the condition is true
	 *
	 * @param bool $condition whether to proceed with the flush
	 * @return bool whether the flush happened
	 */
	function flush_targets_if( $condition ) {
		return $this->flush_if( $condition, 'targets' );
	}

	/**
	 * Flushes one of the transient caches if the first param is true
	 *
	 * @param bool $condition whether to flush the cache
	 * @param string $type which cache to flush
	 * @return bool whether the flush occurred
	 */
	function flush_if( $condition, $type ) {
		if ( $condition ) {
			if ( 'links' === $type )
				return delete_transient( 'plt_meta_cache__links_to' );
			elseif ( 'targets' === $type )
				return delete_transient( 'plt_meta_cache__links_to_target' );
		}
	}

	/**
	 * Logs that a target=_blank PLT item has been used, so we know to trigger footer JS
	 *
	 * @param int|WP_Post $post post ID or object
	 */
	function log_target( $post ) {
		$post = get_post( $post );
		$this->targets_on_this_page[$post->ID] = true;
		add_action( 'wp_footer', array( $this, 'targets_in_new_window_via_js_footer' ), 999 );
	}

	/**
	 * Filter for post or page links
	 *
	 * @param string $link the URL for the post or page
	 * @param int|WP_Post $post post ID or object
	 * @return string output URL
	 */
	function link( $link, $post ) {
		$post = get_post( $post );

		$meta_link = $this->get_link( $post->ID );

		if ( $meta_link ) {
			$link = esc_url( $meta_link );
			if ( $this->get_target( $post->ID ) )
				$this->log_target( $post->ID );
		}

		return $link;
	}

	/**
	 * Performs a redirect, if appropriate
	 */
	function template_redirect() {
		if ( !is_single() && !is_page() )
			return;

		global $wp_query;

		$link = get_post_meta( $wp_query->post->ID, '_links_to', true );

		if ( !$link )
			return;

		wp_redirect( $link, 301 );
		exit;
	}

	/**
	 * Filters the list of pages to alter the links and targets
	 *
	 * @param string $pages the wp_list_pages() HTML block from WordPress
	 * @return string the modified HTML block
	 */
	function wp_list_pages( $pages ) {
		$highlight = false;
		$links = $this->get_links();

		if ( ! $links )
			return $pages;

		$this_url = ( is_ssl() ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		$targets = array();

		foreach ( (array) $links as $id => $page ) {
			if ( $target = $this->get_target( $id ) )
				$targets[$page] = $target;

			if ( str_replace( 'http://www.', 'http://', $this_url ) == str_replace( 'http://www.', 'http://', $page ) || ( is_home() && str_replace( 'http://www.', 'http://', trailingslashit( get_bloginfo( 'url' ) ) ) == str_replace( 'http://www.', 'http://', trailingslashit( $page ) ) ) ) {
				$highlight = true;
				$current_page = esc_url( $page );
			}
		}

		if ( count( $targets ) ) {
			foreach ( $targets as  $p => $t ) {
				$p = esc_url( $p );
				$t = esc_attr( $t );
				$pages = str_replace( '<a href="' . $p . '"', '<a href="' . $p . '" target="' . $t . '"', $pages );
			}
		}

		if ( $highlight ) {
			$pages = preg_replace( '| class="([^"]+)current_page_item"|', ' class="$1"', $pages ); // Kill default highlighting
			$pages = preg_replace( '|<li class="([^"]+)"><a href="' . preg_quote( $current_page ) . '"|', '<li class="$1 current_page_item"><a href="' . $current_page . '"', $pages );
		}
		return $pages;
	}

	/**
	 * Filters nav menu objects and adds target=_blank to the ones that need it
	 *
	 * @param  array $items nav menu items
	 * @return array        modified nav menu items
	 */
	function wp_nav_menu_objects( $items ) {
		$new_items = array();
		foreach ( $items as $item ) {
			if ( $target = $this->get_target( $item->object_id ) )
				$item->target = '_blank';
			$new_items[] = $item;
		}
		return $new_items;
	}

	/**
	 * Hooks in as a post is being loaded for editing and conditionally adds a notice
	 */
	function load_post() {
		if ( isset( $_GET['post'] ) ) {
			if ( get_post_meta( absint( $_GET['post'] ), '_links_to', true ) ) {
				add_action( 'admin_notices', array( $this, 'notify_of_external_link' ) );
			}
		}
	}

	/**
	 * Outputs a notice that the current post item is pointed to a custom URL
	 */
	function notify_of_external_link() {
		?><div class="updated"><p><?php _e( '<strong>Note</strong>: This content is pointing to a custom URL. Use the &#8220;Page Links To&#8221; box to change this behavior.', 'page-links-to' ); ?></p></div><?php
	}

	/**
	 * Return a JS file as a string
	 *
	 * Takes a plugin-relative path to a CS-produced JS file
	 * and returns its second line (no CS comment line)
	 * @param  string $path plugin-relative path to CoffeeScript-produced JS file
	 * @return string       the JS string
	 */
	function inline_coffeescript( $path ) {
			$inline_script = file_get_contents( trailingslashit( plugin_dir_path( __FILE__ ) ) . $path );
			$inline_script = explode( "\n", $inline_script );
			return $inline_script[1];
	}

	/**
	 * Adds inline JS to the footer to handle "open in new tab" links
	 */
	function targets_in_new_window_via_js_footer() {
		$target_ids = $this->targets_on_this_page;
		$target_urls = array();
		foreach ( array_keys( $target_ids ) as $id ) {
			$link = $this->get_link( $id );
			if ( $link )
				$target_urls[$link] = true;
		}
		$targets = array_keys( $target_urls );
		if ( $targets ) {
			?><script>var pltNewTabURLs = <?php echo json_encode( $targets ) . ';' . $this->inline_coffeescript( 'js/new-tab.js' ); ?></script><?php
		}
	}

	/**
	 * Adds a GitHub link to the plugin meta
	 *
	 * @param array $links the current array of links
	 * @param string $file the current plugin being processed
	 * @return array the modified array of links
	 */
	function set_plugin_meta( $links, $file ) {
		if ( $file === plugin_basename( __FILE__ ) ) {
			return array_merge(
				$links,
				array( '<a href="https://github.com/markjaquith/page-links-to" target="_blank">GitHub</a>' )
			);
		}
		return $links;
	}
}

new CWS_PageLinksTo;
