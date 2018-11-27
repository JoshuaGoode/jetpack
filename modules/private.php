<?php
/**
 * Module Name: Private site
 * Module Description: Make your site only visible to you and users you approve.
 * Sort Order: 9
 * First Introduced: ?
 * Requires Connection: No
 * Auto Activate: No
 * Module Tags: Private
 * Feature: Traffic
 * Additional Search Queries: private, sandbox, launch, unlaunched
 *
 * @package Jetpack
 */

class Jetpack_Private {
	static function init() {
		add_action( 'parse_request', array( __CLASS__, 'privatize_blog' ), 100 );
		add_action( 'login_init', array( __CLASS__, 'privatize_blog_maybe_mask_blog_name' ) );
		add_filter( 'preprocess_comment', array( __CLASS__, 'privatize_blog_comments' ) );
		add_action( 'blog_privacy_selector', array( __CLASS__, 'privatize_blog_priv_selector' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'private_robots_txt' ) );
		add_filter( 'privacy_on_link_title', array( __CLASS__, 'privatize_privacy_on_link_title' ) );
		add_filter( 'privacy_on_link_text', array( __CLASS__, 'privatize_privacy_on_link_text' ) );
		add_action( 'wp_head', array( __CLASS__, 'private_no_pinning' ) );
		add_action( 'check_ajax_referer', array( __CLASS__, 'private_blog_ajax_nonce_check' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'disable_rest_api' ) );
		add_filter( 'option_jetpack_active_modules', array( __CLASS__, 'module_override' ) );
		add_action( 'update_option_blog_public', array( __CLASS__, 'private_update_option_blog_public' ) );
	}

	/**
	 * Returns the private site template for private blogs
	 *
	 * @param object $wp Current WordPress environment instance (passed by reference).
	 */
	static function privatize_blog( $wp ) {
		global $pagenow, $current_user, $wpdb;

		if ( 'wp-login.php' === $pagenow ) {
			return;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		// Serve robots.txt for private blogs.
		if ( is_object( $wp ) && ! empty( $wp->query_vars['robots'] ) ) {
			return;
		}

		if ( $current_user && ( is_super_admin() || Jetpack_Private::is_private_blog_user( $wpdb->blogid, $current_user ) ) ) {
			return;
		}

		if ( file_exists( JETPACK__PLUGIN_DIR . '/modules/private/private.php' ) ) {
			include JETPACK__PLUGIN_DIR . '/modules/private/private.php';
		} else {
			esc_html_e( 'This site is private.', 'jetpack' );
		}

		exit;
	}

	/**
	 * Does not check whether the blog is private. Accepts blog and user in various types.
	 * Returns true for super admins; if you don't want that, use is_really_private_blog_user.
	 *
	 * @param int   $blog Current WordPress blod id.
	 * @param Mixed $user Current WordPress user or user_id.
	 */
	static function is_private_blog_user( $blog, $user ) {
		global $wpdb;

		if ( ! is_object( $user ) ) {
			$user = new WP_User( $user );
		}

		if ( ! $user->ID ) {
			return false;
		}

		if ( is_numeric( $blog ) ) {
			$blog_id = intval( $blog );
		} elseif ( is_object( $blog ) ) {
			$blog_id = $blog->blog_id;
		} elseif ( is_string( $blog ) ) {
			$blog    = get_blog_info( $blog, '/', 1 );
			$blog_id = $blog->blog_id;
		} else {
			$blog_id = $wpdb->blogid;
		}

		// check if the user has read permissions.
		$the_user = wp_clone( $user );
		$the_user->for_blog( $blog_id );
		return $the_user->has_cap( 'read' );
	}

	/**
	 * Hides the blog's name on the login form for private blogs.
	 */
	static function privatize_blog_maybe_mask_blog_name() {
		add_filter( 'bloginfo', array( __CLASS__, 'privatize_blog_mask_blog_name' ), 3, 2 );
	}

	/**
	 * Replaces the the blog's "name" value with "Protected Blog"
	 *
	 * @see privatize_blog_maybe_mask_blog_name()
	 * @param mixed $value The requested non-URL site information.
	 * @param mixed $what  Type of information requested.
	 */
	static function privatize_blog_mask_blog_name( $value, $what ) {
		if ( in_array( $what, array( 'name', 'title' ), true ) ) {
			$value = __( 'Protected Blog', 'jetpack' );
		}

		return $value;
	}

	/**
	 * Filters new comments so that users can't comment on private blogs
	 *
	 * @param array $comment Documented in wp-includes/comment.php.
	 */
	static function privatize_blog_comments( $comment ) {
		Jetpack_Private::privatize_blog( null );
		return $comment;
	}

	/**
	 * Extend the 'Site Visibility' privacy options to also include a private option
	 **/
	static function privatize_blog_priv_selector() {
		?>
		<style>
			.jetpack-private__setting-disabled {
				font-weight: bold;
				margin-top: 10px;
				padding: 10px;
			}
		</style>
		<div class="jetpack-private__setting-disabled highlight">
        	<?php _e( 'This setting is ignored because you made your site private in Jetpack', 'jetpack' ); ?>
         </div>
		<?php
	}

	/**
	 * Don't let search engines index private sites
	 * or sites not deemed publicly available, like deleted, archived, spam.
	 *
	 * @param string $output Robots.txt output.
	 */
	static function private_robots_txt( $output ) {
		$output  = "User-agent: *\n"; // Purposefully overriding current output; we only want these rules.
		$output .= "Disallow: /\n";
		return $output;
	}

	/**
	 * Filters the link title attribute for the message displayed in the 'At a Glance' dashboard widget.
	 *
	 * @param string $text Default attribute text.
	 */
	static function privatize_privacy_on_link_title( $text ) {
		return __( 'Your site is visible only to registered members', 'jetpack' );
	}

	/**
	 * Filters the link label for the message displayed in the 'At a Glance' dashboard widget.
	 *
	 * @param string $text Default text.
	 */
	static function privatize_privacy_on_link_text( $text ) {
		return __( 'Private', 'jetpack' );
	}

	/**
	 * Output the meta tag that tells Pinterest not to allow users to pin
	 * content from this page.
	 * https://support.pinterest.com/entries/21063792-what-if-i-don-t-want-images-from-my-site-to-be-pinned
	 */
	static function private_no_pinning() {
		echo '<meta name="pinterest" content="nopin" />';
	}

	/**
	 * Prevents ajax requests on private blogs for users who don't have permissions
	 *
	 * @param string    $action The Ajax nonce action.
	 * @param false|int $result The result of the nonce check.
	 */
	static function private_blog_ajax_nonce_check( $action, $result ) {
		global $current_user, $wpdb;

		if ( is_super_admin() ) {
			return;
		}

		if ( 1 !== $result && 2 !== $result ) {
			return;
		}

		if ( 'find-posts' !== $action && 'internal-linking' !== $action ) {
			return;
		}

		// Make sure we are in the right code path, if not bail now.
		if ( ! is_admin() || ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) ) {
			return;
		}

		if ( ! Jetpack_Private::is_private_blog_user( $wpdb->blogid, $current_user ) ) {
			wp_die( -1 );
		}
	}

	/**
	 * Disables WordPress Rest API for external requests
	 */
	static function disable_rest_api() {
		global $pagenow, $current_user, $wpdb;

		if ( $current_user && ( is_super_admin() || Jetpack_Private::is_private_blog_user( $wpdb->blogid, $current_user ) ) ) {
			return;
		}

		die( __( 'This site is private.', 'jetpack' ) );
	}

	static function module_override( $modules ) {
		$disabled_modules = array(
			'publicize',
			'sharedaddy',
			'subscriptions',
		);

		foreach ( $disabled_modules as $module_slug ) {
			$found = array_search( $module_slug, $modules );
			if ( false !== $found ) {
				unset( $modules[ $found ] );
			}
		}

		return $modules;
	}

	/**
	 * Show an error when the blog_public option is updated
	 */
	static function private_update_option_blog_public() {
		if ( function_exists( 'add_settings_error') ) {
			add_settings_error( 'general', 'setting_not_updated', __( "This setting is ignored because you made your site private in Jetpack", 'jetpack' ), 'error' );
		}
	}
}

Jetpack_Private::init();