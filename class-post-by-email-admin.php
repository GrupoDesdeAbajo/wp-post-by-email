<?php
/**
 * Plugin admin class.
 *
 * @package PostByEmail
 * @author  Kat Hagan <kat@codebykat.com>
 */
class Post_By_Email_Admin {
	protected static $instance = null;

	/**
	 * Instance of this class.
	 *
	 * @since    0.9.6
	 *
	 * @var      object
	 */
	public static function get_instance() {
		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Hook up our functions to the admin menus.
	 *
	 * @since     0.9.6
	 */
	private function __construct() {
		// Add the options page and menu item.
		add_action( 'admin_init', array( $this, 'add_plugin_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_plugin_admin_menu' ) );

		// disable post by email settings on Settings->Writing page
		add_filter( 'enable_post_by_email_configuration', '__return_false' );

		// AJAX hook to clear the log
		add_action( 'wp_ajax_post_by_email_clear_log', array( $this, 'clear_log') );

		add_action( 'wp_ajax_post_by_email_generate_pin', array( $this, 'generate_pin') );

		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// allow for manual trigger of the wp-mail.php action hook
		add_action( 'admin_init', array( $this, 'maybe_check_mail' ) );
	}

	/**
	 * Register the settings.
	 *
	 * @since    0.9.0
	 */
	public function add_plugin_settings() {
		register_setting( 'post_by_email_options', 'post_by_email_options', array( $this, 'post_by_email_validate' ) );
	}

	/**
	* Initiate manual check for new mail.
	*
	* @since    1.0.3
	*/
	public function maybe_check_mail() {
		if ( isset( $_GET['check_mail'] ) ) {
			do_action( 'wp-mail.php' );
		}
	}

	/**
	 * Validate saved options.
	 *
	 * @since    0.9.5
	 *
	 * @param   array    $input    Form fields submitted from the settings page.
	 */
	public function post_by_email_validate( $input ) {
		// load all the options so we don't wipe out pre-existing stuff
		$options = get_option( 'post_by_email_options' );

		$default_options = Post_By_Email::$default_options;

		$options['mailserver_url'] = trim( $input['mailserver_url'] );

		$mailserver_protocol = trim( $input['mailserver_protocol'] );
		if ( in_array( $mailserver_protocol, array( 'POP3', 'IMAP' ) ) ) {
			$options['mailserver_protocol'] = $mailserver_protocol;
		}
 
		// port must be numeric and 16 digits max
		$mailserver_port = trim( $input['mailserver_port'] );
		if ( preg_match('/^[1-9][0-9]{0,15}$/', $mailserver_port ) ) {
			$options['mailserver_port'] = $mailserver_port;
		}

		$options['mailserver_login'] = trim( $input['mailserver_login'] );
		$options['mailserver_pass'] = trim( $input['mailserver_pass'] );

		// default email category must be the ID of a real category
		$default_email_category = $input['default_email_category'];
		if ( get_category( $default_email_category ) ) {
			$options['default_email_category'] = $default_email_category;
		}

		$options['ssl'] = isset( $input['ssl'] ) && '' != $input['ssl'];
		$options['delete_messages'] = isset( $input['delete_messages'] ) && '' != $input['delete_messages'];

		$options['pin_required'] = isset( $input['pin_required'] ) && '' != $input['pin_required'];
		$options['pin'] = trim( $input['pin'] );

		if ( isset( $input['discard_pending'] ) ) {
			$options['discard_pending'] = ( 'discard' == $input['discard_pending'] );
		}

		// this is ridiculous
		if ( isset ( $input['status'] ) && in_array( $input['status'], array( 'unconfigured', 'error', '') ) ) {
			// maintain saved state
			$options['status'] = $input['status'];
		}
		elseif ( ( $options['mailserver_url'] == $default_options['mailserver_url'] )
			|| ( '' == $options['mailserver_url'] )
			|| ( $options['mailserver_login'] == $default_options['mailserver_login'] )
			|| ( '' == $options['mailserver_login'] )
			|| ( '' == $options['mailserver_pass'] )
			|| ( '' == $options['mailserver_port'] )
			|| ( '' == $options['mailserver_protocol' ] )
			) {
			// detect if settings are blank or defaults
			$options['status'] = 'unconfigured';
		}
		else {
			// clear the transient and any error conditions if we have good options now
			delete_transient( 'mailserver_last_checked' );
			$options['status'] = '';
		}

		// make sure all default options are set
		foreach ( $default_options as $key => $value ) {
			if ( ! isset( $options[ $key ] ) ) {
				$options[ $key ] = $value;
			}
		}

		return $options;
	}

	/**
	 * Register the administration menu for this plugin into the WordPress Dashboard menu.
	 *
	 * @since    0.9.0
	 */
	public function add_plugin_admin_menu() {
		$this->plugin_screen_hook_suffix = add_management_page(
			__( 'Post By Email', 'post-by-email' ),
			__( 'Post By Email', 'post-by-email' ),
			'read',
			'post-by-email',
			array( $this, 'display_plugin_admin_page' )
		);
		WP_Screen::get($this->plugin_screen_hook_suffix)->add_help_tab( array(
			'id'      => 'options-postemail',
			'title'   => __( 'Post Via Email' ),
			'content' => '<p>' . __( 'Post via email settings allow you to send your WordPress install an email with the content of your post. You must set up a secret e-mail account with POP3 access to use this, and any mail received at this address will be posted, so it&#8217;s a good idea to keep this address very secret.', 'post-by-email' ) . '</p>',
		) );
	}

	/**
	 * Render the settings page for this plugin.
	 *
	 * @since    0.9.0
	 */
	public function display_plugin_admin_page() {
		include_once( plugin_dir_path( __FILE__ ) . 'views/admin.php' );
	}

	/**
	* Load up Javascript for the admin page.
	*
	* @since    1.0.1
	*
	* @param    string    $hook    Name of the current admin page.
	*/
	public function enqueue_scripts( $hook ) {
		if ( $hook != $this->plugin_screen_hook_suffix )
			return;

		wp_enqueue_script( 'post-by-email-admin-js', plugins_url( 'js/admin.js', __FILE__ ), 'jquery', '', true );
	}

	/**
	* Display any errors or notices as an admin banner.
	*
	* @since    1.0.1
	*/
	public function admin_notices() {
		$options = get_option( 'post_by_email_options' );
		$settings_url = add_query_arg( 'page', 'post-by-email', admin_url( 'tools.php' ) );
		if ( ! $options || ! isset( $options['status'] ) || 'unconfigured' == $options['status'] ) {
			echo "<div class='error'><p>";
			_e( "Notice: Post By Email is currently disabled.  To post to your blog via email, please <a href='$settings_url'>configure your settings now</a>.", 'post-by-email' );
			echo "</p></div>";
		} elseif ( 'error' == $options['status'] ) {
			echo "<div class='error'><p>";
			_e( "Post By Email encountered an error.  <a href='$settings_url&tab=log'>View the log</a> for details.", 'post-by-email' );
			echo "</p></div>";
		}
	}

	/**
	 * Clear the log file.
	 *
	 * @since    0.9.9
	*/
	public function clear_log() {
		check_ajax_referer( 'post-by-email-clear-log', 'security' );
		if ( current_user_can( 'manage_options' ) ) {
			update_option( 'post_by_email_log', array() );
		}

		die();
	}

	/**
	 * Generate a good PIN.
	 *
	 * @since    1.0.2
	*/
	public function generate_pin() {
		check_ajax_referer( 'post-by-email-generate-pin', 'security' );
		if ( current_user_can( 'manage_options' ) ) {
			echo wp_generate_password( 8, true, false );
		}

		die();
	}

}