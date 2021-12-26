<?php
/**
 * Class for looking up a site's health based on a user's WordPress environment.
 *
 * @package WordPress
 * @subpackage Site_Health
 * @since 5.2.0
 */

class WP_Site_Health {
	private static $instance = null;

	private $mysql_min_version_check;
	private $mysql_rec_version_check;

	public $is_mariadb                           = false;
	private $mysql_server_version                = '';
	private $health_check_mysql_required_version = '5.5';
	private $health_check_mysql_rec_version      = '';

	public $php_memory_limit;

	public $schedules;
	public $crons;
	public $last_missed_cron     = null;
	public $last_late_cron       = null;
	private $timeout_missed_cron = null;
	private $timeout_late_cron   = null;

	/**
	 * WP_Site_Health constructor.
	 *
	 * @since 5.2.0
	 */
	public function __construct() {
		$this->maybe_create_scheduled_event();

		// Save memory limit before it's affected by wp_raise_memory_limit( 'admin' ).
		$this->php_memory_limit = ini_get( 'memory_limit' );

		$this->timeout_late_cron   = 0;
		$this->timeout_missed_cron = - 5 * MINUTE_IN_SECONDS;

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$this->timeout_late_cron   = - 15 * MINUTE_IN_SECONDS;
			$this->timeout_missed_cron = - 1 * HOUR_IN_SECONDS;
		}

		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_site_health_scheduled_check', array( $this, 'wp_cron_scheduled_check' ) );
	}

	/**
	 * Return an instance of the WP_Site_Health class, or create one if none exist yet.
	 *
	 * @since 5.4.0
	 *
	 * @return WP_Site_Health|null
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new WP_Site_Health();
		}

		return self::$instance;
	}

	/**
	 * Enqueues the site health scripts.
	 *
	 * @since 5.2.0
	 */
	public function enqueue_scripts() {
		$screen = get_current_screen();
		if ( 'site-health' !== $screen->id && 'dashboard' !== $screen->id ) {
			return;
		}

		$health_check_js_variables = array(
			'screen'      => $screen->id,
			'nonce'       => array(
				'site_status'        => wp_create_nonce( 'health-check-site-status' ),
				'site_status_result' => wp_create_nonce( 'health-check-site-status-result' ),
			),
			'site_status' => array(
				'direct' => array(),
				'async'  => array(),
				'issues' => array(
					'good'        => 0,
					'recommended' => 0,
					'critical'    => 0,
				),
			),
		);

		$issue_counts = get_transient( 'health-check-site-status-result' );

		if ( false !== $issue_counts ) {
			$issue_counts = json_decode( $issue_counts );

			$health_check_js_variables['site_status']['issues'] = $issue_counts;
		}

		if ( 'site-health' === $screen->id && ! isset( $_GET['tab'] ) ) {
			$tests = WP_Site_Health::get_tests();

			// Don't run https test on development environments.
			if ( $this->is_development_environment() ) {
				unset( $tests['direct']['https_status'] );
			}

			foreach ( $tests['direct'] as $test ) {
				if ( is_string( $test['test'] ) ) {
					$test_function = sprintf(
						'get_test_%s',
						$test['test']
					);

					if ( method_exists( $this, $test_function ) && is_callable( array( $this, $test_function ) ) ) {
						$health_check_js_variables['site_status']['direct'][] = $this->perform_test( array( $this, $test_function ) );
						continue;
					}
				}

				if ( is_callable( $test['test'] ) ) {
					$health_check_js_variables['site_status']['direct'][] = $this->perform_test( $test['test'] );
				}
			}

			foreach ( $tests['async'] as $test ) {
				if ( is_string( $test['test'] ) ) {
					$health_check_js_variables['site_status']['async'][] = array(
						'test'      => $test['test'],
						'has_rest'  => ( isset( $test['has_rest'] ) ? $test['has_rest'] : false ),
						'completed' => false,
						'headers'   => isset( $test['headers'] ) ? $test['headers'] : array(),
					);
				}
			}
		}

		wp_localize_script( 'site-health', 'SiteHealth', $health_check_js_variables );
	}

	/**
	 * Run a Site Health test directly.
	 *
	 * @since 5.4.0
	 *
	 * @param callable $callback
	 * @return mixed|void
	 */
	private function perform_test( $callback ) {
		/**
		 * Filters the output of a finished Site Health test.
		 *
		 * @since 5.3.0
		 *
		 * @param array $test_result {
		 *     An associative array of test result data.
		 *
		 *     @type string $label       A label describing the test, and is used as a header in the output.
		 *     @type string $status      The status of the test, which can be a value of `good`, `recommended` or `critical`.
		 *     @type array  $badge {
		 *         Tests are put into categories which have an associated badge shown, these can be modified and assigned here.
		 *
		 *         @type string $label The test label, for example `Performance`.
		 *         @type string $color Default `blue`. A string representing a color to use for the label.
		 *     }
		 *     @type string $description A more descriptive explanation of what the test looks for, and why it is important for the end user.
		 *     @type string $actions     An action to direct the user to where they can resolve the issue, if one exists.
		 *     @type string $test        The name of the test being ran, used as a reference point.
		 * }
		 */
		return apply_filters( 'site_status_test_result', call_user_func( $callback ) );
	}

	/**
	 * Run the SQL version checks.
	 *
	 * These values are used in later tests, but the part of preparing them is more easily managed
	 * early in the class for ease of access and discovery.
	 *
	 * @since 5.2.0
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	private function prepare_sql_data() {
		global $wpdb;

		if ( $wpdb->use_mysqli ) {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_get_server_info
			$mysql_server_type = mysqli_get_server_info( $wpdb->dbh );
		} else {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysql_get_server_info,PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved
			$mysql_server_type = mysql_get_server_info( $wpdb->dbh );
		}

		$this->mysql_server_version = $wpdb->get_var( 'SELECT VERSION()' );

		$this->health_check_mysql_rec_version = '5.6';

		if ( stristr( $mysql_server_type, 'mariadb' ) ) {
			$this->is_mariadb                     = true;
			$this->health_check_mysql_rec_version = '10.0';
		}

		$this->mysql_min_version_check = version_compare( '5.5', $this->mysql_server_version, '<=' );
		$this->mysql_rec_version_check = version_compare( $this->health_check_mysql_rec_version, $this->mysql_server_version, '<=' );
	}

	/**
	 * Test if `wp_version_check` is blocked.
	 *
	 * It's possible to block updates with the `wp_version_check` filter, but this can't be checked
	 * during an Ajax call, as the filter is never introduced then.
	 *
	 * This filter overrides a standard page request if it's made by an admin through the Ajax call
	 * with the right query argument to check for this.
	 *
	 * @since 5.2.0
	 */
	public function check_wp_version_check_exists() {
		if ( ! is_admin() || ! is_user_logged_in() || ! current_user_can( 'update_core' ) || ! isset( $_GET['health-check-test-wp_version_check'] ) ) {
			return;
		}

		echo ( has_filter( 'wp_version_check', 'wp_version_check' ) ? 'yes' : 'no' );

		die();
	}

	/**
	 * Tests for WordPress version and outputs it.
	 *
	 * Gives various results depending on what kind of updates are available, if any, to encourage
	 * the user to install security updates as a priority.
	 *
	 * @since 5.2.0
	 *
	 * @return array The test result.
	 */
	public function get_test_wordpress_version() {
		$result = array(
			'label'       => '',
			'status'      => '',
			'badge'       => array(
				'label' => __( 'Performance' ),
				'color' => 'blue',
			),
			'description' => '',
			'actions'     => '',
			'test'        => 'wordpress_version',
		);

		$core_current_version = get_bloginfo( 'version' );
		$core_updates         = get_core_updates();

		if ( ! is_array( $core_updates ) ) {
			$result['status'] = 'recommended';

			$result['label'] = sprintf(
				/* translators: %s: Your current version of WordPress. */
				__( 'WordPress version %s' ),
				$core_current_version
			);

			$result['description'] = sprintf(
				'<p>%s</p>',
				__( 'We were unable to check if any new versions of WordPress are available.' )
			);

			$result['actions'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'update-core.php?force-check=1' ) ),
				__( 'Check for updates manually' )
			);
		} else {
			foreach ( $core_updates as $core => $update ) {
				if ( 'upgrade' === $update->response ) {
					$current_version = explode( '.', $core_current_version );
					$new_version     = explode( '.', $update->version );

					$current_major = $current_version[0] . '.' . $current_version[1];
					$new_major     = $new_version[0] . '.' . $new_version[1];

					$result['label'] = sprintf(
						/* translators: %s: The latest version of WordPress available. */
						__( 'WordPress update available (%s)' ),
						$update->version
					);

					$result['actions'] = sprintf(
						'<a href="%s">%s</a>',
						esc_url( admin_url( 'update-core.php' ) ),
						__( 'Install the latest version of WordPress' )
					);

					if ( $current_major !== $new_major ) {
						// This is a major version mismatch.
						$result['status']      = 'recommended';
						$result['description'] = sprintf(
							'<p>%s</p>',
							__( 'A new version of WordPress is available.' )
						);
					} else {
						// This is a minor version, sometimes considered more critical.
						$result['status']         = 'critical';
						$result['badge']['label'] = __( 'Security' );
						$result['description']    = sprintf(
							'<p>%s</p>',
							__( 'A new minor update is available for your site. Because minor updates often address security, it&#8217;s important to install them.' )
						);
					}
				} else {
					$result['status'] = 'good';
					$result['label']  = sprintf(
						/* translators: %s: The current version of WordPress installed on this site. */
						__( 'Your version of WordPress (%s) is up to date' ),
						$core_current_version
					);

					$result['description'] = sprintf(
						'<p>%s</p>',
						__( 'You are currently running the latest version of WordPress available, keep it up!' )
					);
				}
			}
		}

		return $result;
	}

	/**
	 * Test if plugins are outdated, or unnecessary.
	 *
	 * The tests checks if your plugins are up to date, and encourages you to remove any
	 * that are not in use.
	 *
	 * @since 5.2.0
	 *
	 * @return array The test result.
	 */
	public function get_test_plugin_version() {
		$result = array(
			'label'       => __( 'Your plugins are all up to date' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				__( 'Plugins extend your site&#8217;s functionality with things like contact forms, ecommerce and much more. That means they have deep access to your site, so it&#8217;s vital to keep them up to date.' )
			),
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'plugins.php' ) ),
				__( 'Manage your plugins' )
			),
			'test'        => 'plugin_version',
		);

		$plugins        = get_plugins();
		$plugin_updates = get_plugin_updates();

		$plugins_have_updates = false;
		$plugins_active       = 0;
		$plugins_total        = 0;
		$plugins_need_update  = 0;

		// Loop over the available plugins and check their versions and active state.
		foreach ( $plugins as $plugin_path => $plugin ) {
			$plugins_total++;

			if ( is_plugin_active( $plugin_path ) ) {
				$plugins_active++;
			}

			$plugin_version = $plugin['Version'];

			if ( array_key_exists( $plugin_path, $plugin_updates ) ) {
				$plugins_need_update++;
				$plugins_have_updates = true;
			}
		}

		// Add a notice if there are outdated plugins.
		if ( $plugins_need_update > 0 ) {
			$result['status'] = 'critical';

			$result['label'] = __( 'You have plugins waiting to be updated' );

			$result['description'] .= sprintf(
				'<p>%s</p>',
				sprintf(
					/* translators: %d: The number of outdated plugins. */
					_n(
						'Your site has %d plugin waiting to be updated.',
						'Your site has %d plugins waiting to be updated.',
						$plugins_need_update
					),
					$plugins_need_update
				)
			);

			$result['actions'] .= sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( network_admin_url( 'plugins.php?plugin_status=upgrade' ) ),
				__( 'Update your plugins' )
			);
		} else {
			if ( 1 === $plugins_active ) {
				$result['description'] .= sprintf(
					'<p>%s</p>',
					__( 'Your site has 1 active plugin, and it is up to date.' )
				);
			} else {
				$result['description'] .= sprintf(
					'<p>%s</p>',
					sprintf(
						/* translators: %d: The number of active plugins. */
						_n(
							'Your site has %d active plugin, and it is up to date.',
							'Your site has %d active plugins, and they are all up to date.',
							$plugins_active
						),
						$plugins_active
					)
				);
			}
		}

		// Check if there are inactive plugins.
		if ( $plugins_total > $plugins_active && ! is_multisite() ) {
			$unused_plugins = $plugins_total - $plugins_active;

			$result['status'] = 'recommended';

			$result['label'] = __( 'You should remove inactive plugins' );

			$result['description'] .= sprintf(
				'<p>%s %s</p>',
				sprintf(
					/* translators: %d: The number of inactive plugins. */
					_n(
						'Your site has %d inactive plugin.',
						'Your site has %d inactive plugins.',
						$unused_plugins
					),
					$unused_plugins
				),
				__( 'Inactive plugins are tempting targets for attackers. If you&#8217;re not going to use a plugin, we recommend you remove it.' )
			);

			$result['actions'] .= sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'plugins.php?plugin_status=inactive' ) ),
				__( 'Manage inactive plugins' )
			);
		}

		return $result;
	}

	/**
	 * Test if themes are outdated, or unnecessary.
	 *
	 * Сhecks if your site has a default theme (to fall back on if there is a need),
	 * if your themes are up to date and, finally, encourages you to remove any themes
	 * that are not needed.
	 *
	 * @since 5.2.0
	 *
	 * @return array The test results.
	 */
	public function get_test_theme_version() {
		$result = array(
			'label'       => __( 'Your themes are all up to date' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				__( 'Themes add your site&#8217;s look and feel. It&#8217;s important to keep them up to date, to stay consistent with your brand and keep your site secure.' )
			),
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'themes.php' ) ),
				__( 'Manage your themes' )
			),
			'test'        => 'theme_version',
		);

		$theme_updates = get_theme_updates();

		$themes_total        = 0;
		$themes_need_updates = 0;
		$themes_inactive     = 0;

		// This value is changed during processing to determine how many themes are considered a reasonable amount.
		$allowed_theme_count = 1;

		$has_default_theme   = false;
		$has_unused_themes   = false;
		$show_unused_themes  = true;
		$using_default_theme = false;

		// Populate a list of all themes available in the install.
		$all_themes   = wp_get_themes();
		$active_theme = wp_get_theme();

		// If WP_DEFAULT_THEME doesn't exist, fall back to the latest core default theme.
		$default_theme = wp_get_theme( WP_DEFAULT_THEME );
		if ( ! $default_theme->exists() ) {
			$default_theme = WP_Theme::get_core_default_theme();
		}

		if ( $default_theme ) {
			$has_default_theme = true;

			if (
				$active_theme->get_stylesheet() === $default_theme->get_stylesheet()
			||
				is_child_theme() && $active_theme->get_template() === $default_theme->get_template()
			) {
				$using_default_theme = true;
			}
		}

		foreach ( $all_themes as $theme_slug => $theme ) {
			$themes_total++;

			if ( array_key_exists( $theme_slug, $theme_updates ) ) {
				$themes_need_updates++;
			}
		}

		// If this is a child theme, increase the allowed theme count by one, to account for the parent.
		if ( is_child_theme() ) {
			$allowed_theme_count++;
		}

		// If there's a default theme installed and not in use, we count that as allowed as well.
		if ( $has_default_theme && ! $using_default_theme ) {
			$allowed_theme_count++;
		}

		if ( $themes_total > $allowed_theme_count ) {
			$has_unused_themes = true;
			$themes_inactive   = ( $themes_total - $allowed_theme_count );
		}

		// Check if any themes need to be updated.
		if ( $themes_need_updates > 0 ) {
			$result['status'] = 'critical';

			$result['label'] = __( 'You have themes waiting to be updated' );

			$result['description'] .= sprintf(
				'<p>%s</p>',
				sprintf(
					/* translators: %d: The number of outdated themes. */
					_n(
						'Your site has %d theme waiting to be updated.',
						'Your site has %d themes waiting to be updated.',
						$themes_need_updates
					),
					$themes_need_updates
				)
			);
		} else {
			// Give positive feedback about the site being good about keeping things up to date.
			if ( 1 === $themes_total ) {
				$result['description'] .= sprintf(
					'<p>%s</p>',
					__( 'Your site has 1 installed theme, and it is up to date.' )
				);
			} else {
				$result['description'] .= sprintf(
					'<p>%s</p>',
					sprintf(
						/* translators: %d: The number of themes. */
						_n(
							'Your site has %d installed theme, and it is up to date.',
							'Your site has %d installed themes, and they are all up to date.',
							$themes_total
						),
						$themes_total
					)
				);
			}
		}

		if ( $has_unused_themes && $show_unused_themes && ! is_multisite() ) {

			// This is a child theme, so we want to be a bit more explicit in our messages.
			if ( $active_theme->parent() ) {
				// Recommend removing inactive themes, except a default theme, your current one, and the parent theme.
				$result['status'] = 'recommended';

				$result['label'] = __( 'You should remove inactive themes' );

				if ( $using_default_theme ) {
					$result['description'] .= sprintf(
						'<p>%s %s</p>',
						sprintf(
							/* translators: %d: The number of inactive themes. */
							_n(
								'Your site has %d inactive theme.',
								'Your site has %d inactive themes.',
								$themes_inactive
							),
							$themes_inactive
						),
						sprintf(
							/* translators: 1: The currently active theme. 2: The active theme's parent theme. */
							__( 'To enhance your site&#8217;s security, we recommend you remove any themes you&#8217;re not using. You should keep your current theme, %1$s, and %2$s, its parent theme.' ),
							$active_theme->name,
							$active_theme->parent()->name
						)
					);
				} else {
					$result['description'] .= sprintf(
						'<p>%s %s</p>',
						sprintf(
							/* translators: %d: The number of inactive themes. */
							_n(
								'Your site has %d inactive theme.',
								'Your site has %d inactive themes.',
								$themes_inactive
							),
							$themes_inactive
						),
						sprintf(
							/* translators: 1: The default theme for WordPress. 2: The currently active theme. 3: The active theme's parent theme. */
							__( 'To enhance your site&#8217;s security, we recommend you remove any themes you&#8217;re not using. You should keep %1$s, the default WordPress theme, %2$s, your current theme, and %3$s, its parent theme.' ),
							$default_theme ? $default_theme->name : WP_DEFAULT_THEME,
							$active_theme->name,
							$active_theme->parent()->name
						)
					);
				}
			} else {
				// Recommend removing all inactive themes.
				$result['status'] = 'recommended';

				$result['label'] = __( 'You should remove inactive themes' );

				if ( $using_default_theme ) {
					$result['description'] .= sprintf(
						'<p>%s %s</p>',
						sprintf(
							/* translators: 1: The amount of inactive themes. 2: The currently active theme. */
							_n(
								'Your site has %1$d inactive theme, other than %2$s, your active theme.',
								'Your site has %1$d inactive themes, other than %2$s, your active theme.',
								$themes_inactive
							),
							$themes_inactive,
							$active_theme->name
						),
						__( 'We recommend removing any unused themes to enhance your site&#8217;s security.' )
					);
				} else {
					$result['description'] .= sprintf(
						'<p>%s %s</p>',
						sprintf(
							/* translators: 1: The amount of inactive themes. 2: The default theme for WordPress. 3: The currently active theme. */
							_n(
								'Your site has %1$d inactive theme, other than %2$s, the default WordPress theme, and %3$s, your active theme.',
								'Your site has %1$d inactive themes, other than %2$s, the default WordPress theme, and %3$s, your active theme.',
								$themes_inactive
							),
							$themes_inactive,
							$default_theme ? $default_theme->name : WP_DEFAULT_THEME,
							$active_theme->name
						),
						__( 'We recommend removing any unused themes to enhance your site&#8217;s security.' )
					);
				}
			}
		}

		// If no default Twenty* theme exists.
		if ( ! $has_default_theme ) {
			$result['status'] = 'recommended';

			$result['label'] = __( 'Have a default theme available' );

			$result['description'] .= sprintf(
				'<p>%s</p>',
				__( 'Your site does not have any default theme. Default themes are used by WordPress automatically if anything is wrong with your chosen theme.' )
			);
		}

		return $result;
	}

	/**
	 * Test if the supplied PHP version is supported.
	 *
	 * @since 5.2.0
	 *
	 * @return array The test results.
	 */
	public function get_test_php_version() {
		$response = wp_check_php_version();

		$result = array(
			'label'       => sprintf(
				/* translators: %s: The current PHP version. */
				__( 'Your site is running the current version of PHP (%s)' ),
				PHP_VERSION
			),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Performance' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				sprintf(
					/* translators: %s: The minimum recommended PHP version. */
					__( 'PHP is the programming language used to build and maintain WordPress. Newer versions of PHP are created with increased performance in mind, so you may see a positive effect on your site&#8217;s performance. The minimum recommended version of PHP is %s.' ),
					$response ? $response['recommended_version'] : ''
				)
			),
			'actions'     => sprintf(
				'<p><a href="%s" target="_blank" rel="noopener">%s <span class="screen-reader-text">%s</span><span aria-hidden="true" class="dashicons dashicons-external"></span></a></p>',
				esc_url( wp_get_update_php_url() ),
				__( 'Learn more about updating PHP' ),
				/* translators: Accessibility text. */
				__( '(opens in a new tab)' )
			),
			'test'        => 'php_version',
		);

		// PHP is up to date.
		if ( ! $response || version_compare( PHP_VERSION, $response['recommended_version'], '>=' ) ) {
			return $result;
		}

		// The PHP version is older than the recommended version, but still receiving active support.
		if ( $response['is_supported'] ) {
			$result['label'] = sprintf(
				/* translators: %s: The server PHP version. */
				__( 'Your site is running an older version of PHP (%s)' ),
				PHP_VERSION
			);
			$result['status'] = 'recommended';

			return $result;
		}

		// The PHP version is only receiving security fixes.
		if ( $response['is_secure'] ) {
			$result['label'] = sprintf(
				/* translators: %s: The server PHP version. */
				__( 'Your site is running an older version of PHP (%s), which should be updated' ),
				PHP_VERSION
			);
			$result['status'] = 'recommended';

			return $result;
		}

		// Anything no longer secure must be updated.
		$result['label'] = sprintf(
			/* translators: %s: The server PHP version. */
			__( 'Your site is running an outdated version of PHP (%s), which requires an update' ),
			PHP_VERSION
		);
		$result['status']         = 'critical';
		$result['badge']['label'] = __( 'Security' );

		return $result;
	}

	/**
	 * Check if the passed extension or function are available.
	 *
	 * Make the check for available PHP modules into a simple boolean operator for a cleaner test runner.
	 *
	 * @since 5.2.0
	 * @since 5.3.0 The `$constant` and `$class` parameters were added.
	 *
	 * @param string $extension Optional. The extension name to test. Default null.
	 * @param string $function  Optional. The function name to test. Default null.
	 * @param string $constant  Optional. The constant name to test for. Default null.
	 * @param string $class     Optional. The class name to test for. Default null.
	 * @return bool Whether or not the extension and function are available.
	 */
	private function test_php_extension_availability( $extension = null, $function = null, $constant = null, $class = null ) {
		// If no extension or function is passed, claim to fail testing, as we have nothing to test against.
		if ( ! $extension && ! $function && ! $constant && ! $class ) {
			return false;
		}

		if ( $extension && ! extension_loaded( $extension ) ) {
			return false;
		}
		if ( $function && ! function_exists( $function ) ) {
			return false;
		}
		if ( $constant && ! defined( $constant ) ) {
			return false;
		}
		if ( $class && ! class_exists( $class ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Test if required PHP modules are installed on the host.
	 *
	 * This test builds on the recommendations made by the WordPress Hosting Team
	 * as seen at https://make.wordpress.org/hosting/handbook/handbook/server-environment/#php-extensions
	 *
	 * @since 5.2.0
	 *
	 * @return array
	 */
	public function get_test_php_extensions() {
		$result = array(
			'label'       => __( 'Required and recommended modules are installed' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Performance' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p><p>%s</p>',
				__( 'PHP modules perform most of the tasks on the server that make your site run. Any changes to these must be made by your server administrator.' ),
				sprintf(
					/* translators: 1: Link to the hosting group page about recommended PHP modules. 2: Additional link attributes. 3: Accessibility text. */
					__( 'The WordPress Hosting Team maintains a list of those modules, both recommended and required, in <a href="%1$s" %2$s>the team handbook%3$s</a>.' ),
					/* translators: Localized team handbook, if one exists. */
					esc_url( __( 'https://make.wordpress.org/hosting/handbook/handbook/server-environment/#php-extensions' ) ),
					'target="_blank" rel="noopener"',
					sprintf(
						' <span class="screen-reader-text">%s</span><span aria-hidden="true" class="dashicons dashicons-external"></span>',
						/* translators: Accessibility text. */
						__( '(opens in a new tab)' )
					)
				)
			),
			'actions'     => '',
			'test'        => 'php_extensions',
		);

		$modules = array(
			'curl'      => array(
				'function' => 'curl_version',
				'required' => false,
			),
			'dom'       => array(
				'class'    => 'DOMNode',
				'required' => false,
			),
			'exif'      => array(
				'function' => 'exif_read_data',
				'required' => false,
			),
			'fileinfo'  => array(
				'function' => 'finfo_file',
				'required' => false,
			),
			'hash'      => array(
				'function' => 'hash',
				'required' => false,
			),
			'json'      => array(
				'function' => 'json_last_error',
				'required' => true,
			),
			'mbstring'  => array(
				'function' => 'mb_check_encoding',
				'required' => false,
			),
			'mysqli'    => array(
				'function' => 'mysqli_connect',
				'required' => false,
			),
			'libsodium' => array(
				'constant'            => 'SODIUM_LIBRARY_VERSION',
				'required'            => false,
				'php_bundled_version' => '7.2.0',
			),
			'openssl'   => array(
				'function' => 'openssl_encrypt',
				'required' => false,
			),
			'pcre'      => array(
				'function' => 'preg_match',
				'required' => false,
			),
			'imagick'   => array(
				'extension' => 'imagick',
				'required'  => false,
			),
			'mod_xml'   => array(
				'extension' => 'libxml',
				'required'  => false,
			),
			'zip'       => array(
				'class'    => 'ZipArchive',
				'required' => false,
			),
			'filter'    => array(
				'function' => 'filter_list',
				'required' => false,
			),
			'gd'        => array(
				'extension'    => 'gd',
				'required'     => false,
				'fallback_for' => 'imagick',
			),
			'iconv'     => array(
				'function' => 'iconv',
				'required' => false,
			),
			'mcrypt'    => array(
				'extension'    => 'mcrypt',
				'required'     => false,
				'fallback_for' => 'libsodium',
			),
			'simplexml' => array(
				'extension'    => 'simplexml',
				'required'     => false,
				'fallback_for' => 'mod_xml',
			),
			'xmlreader' => array(
				'extension'    => 'xmlreader',
				'required'     => false,
				'fallback_for' => 'mod_xml',
			),
			'zlib'      => array(
				'extension'    => 'zlib',
				'required'     => false,
				'fallback_for' => 'zip',
			),
		);

		/**
		 * An array representing all the modules we wish to test for.
		 *
		 * @since 5.2.0
		 * @since 5.3.0 The `$constant` and `$class` parameters were added.
		 *
		 * @param array $modules {
		 *     An associative array of modules to test for.
		 *
		 *     @type array ...$0 {
		 *         An associative array of module properties used during testing.
		 *         One of either `$function` or `$extension` must be provided, or they will fail by default.
		 *
		 *         @type string $function     Optional. A function name to test for the existence of.
		 *         @type string $extension    Optional. An extension to check if is loaded in PHP.
		 *         @type string $constant     Optional. A constant name to check for to verify an extension exists.
		 *         @type string $class        Optional. A class name to check for to verify an extension exists.
		 *         @type bool   $required     Is this a required feature or not.
		 *         @type string $fallback_for Optional. The module this module replaces as a fallback.
		 *     }
		 * }
		 */
		$modules = apply_filters( 'site_status_test_php_modules', $modules );

		$failures = array();

		foreach ( $modules as $library => $module ) {
			$extension  = ( isset( $module['extension'] ) ? $module['extension'] : null );
			$function   = ( isset( $module['function'] ) ? $module['function'] : null );
			$constant   = ( isset( $module['constant'] ) ? $module['constant'] : null );
			$class_name = ( isset( $module['class'] ) ? $module['class'] : null );

			// If this module is a fallback for another function, check if that other function passed.
			if ( isset( $module['fallback_for'] ) ) {
				/*
				 * If that other function has a failure, mark this module as required for usual operations.
				 * If that other function hasn't failed, skip this test as it's only a fallback.
				 */
				if ( isset( $failures[ $module['fallback_for'] ] ) ) {
					$module['required'] = true;
				} else {
					continue;
				}
			}

			if ( ! $this->test_php_extension_availability( $extension, $function, $constant, $class_name ) && ( ! isset( $module['php_bundled_version'] ) || version_compare( PHP_VERSION, $module['php_bundled_version'], '<' ) ) ) {
				if ( $module['required'] ) {
					$result['status'] = 'critical';

					$class         = 'error';
					$screen_reader = __( 'Error' );
					$message       = sprintf(
						/* translators: %s: The module name. */
						__( 'The required module, %s, is not installed, or has been disabled.' ),
						$library
					);
				} else {
					$class         = 'warning';
					$screen_reader = __( 'Warning' );
					$message       = sprintf(
						/* translators: %s: The module name. */
						__( 'The optional module, %s, is not installed, or has been disabled.' ),
						$library
					);
				}

				if ( ! $module['required'] && 'good' === $result['status'] ) {
					$result['status'] = 'recommended';
				}

				$failures[ $library ] = "<span class='dashicons $class'><span class='screen-reader-text'>$screen_reader</span></span> $message";
			}
		}

		if ( ! empty( $failures ) ) {
			$output = '<ul>';

			foreach ( $failures as $failure ) {
				$output .= sprintf(
					'<li>%s</li>',
					$failure
				);
			}

			$output .= '</ul>';
		}

		if ( 'good' !== $result['status'] ) {
			if ( 'recommended' === $result['status'] ) {
				$result['label'] = __( 'One or more recommended modules are missing' );
			}
			if ( 'critical' === $result['status'] ) {
				$result['label'] = __( 'One or more required modules are missing' );
			}

			$result['description'] .= $output;
		}

		return $result;
	}

	/**
	 * Test if the PHP default timezone is set to UTC.
	 *
	 * @since 5.3.1
	 *
	 * @return array The test results.
	 */
	public function get_test_php_default_timezone() {
		$result = array(
			'label'       => __( 'PHP default timezone is valid' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Performance' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				__( 'PHP default timezone was configured by WordPress on loading. This is necessary for correct calculations of dates and times.' )
			),
			'actions'     => '',
			'test'        => 'php_default_timezone',
		);

		if ( 'UTC' !== date_default_timezone_get() ) {
			$result['status'] = 'critical';

			$result['label'] = __( 'PHP default timezone is invalid' );

			$result['description'] = sprintf(
				'<p>%s</p>',
				sprintf(
					/* translators: %s: date_default_timezone_set() */
					__( 'PHP default timezone was changed after WordPress loading by a %s function call. This interferes with correct calculations of dates and times.' ),
					'<code>date_default_timezone_set()</code>'
				)
			);
		}

		return $result;
	}

	/**
	 * Test if there's an active PHP session that can affect loopback requests.
	 *
	 * @since 5.5.0
	 *
	 * @return array The test results.
	 */
	public function get_test_php_sessions() {
		$result = array(
			'label'       => __( 'No PHP sessions detected' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Performance' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				sprintf(
					/* translators: 1: session_start(), 2: session_write_close() */
					__( 'PHP sessions created by a %1$s function call may interfere with REST API and loopback requests. An active session should be closed by %2$s before making any HTTP requests.' ),
					'<code>session_start()</code>',
					'<code>session_write_close()</code>'
				)
			),
			'test'        => 'php_sessions',
		);

		if ( function_exists( 'session_status' ) && PHP_SESSION_ACTIVE === session_status() ) {
			$result['status'] = 'critical';

			$result['label'] = __( 'An active PHP session was detected' );

			$result['description'] = sprintf(
				'<p>%s</p>',
				sprintf(
					/* translators: 1: session_start(), 2: session_write_close() */
					__( 'A PHP session was created by a %1$s function call. This interferes with REST API and loopback requests. The session should be closed by %2$s before making any HTTP requests.' ),
					'<code>session_start()</code>',
					'<code>session_write_close()</code>'
				)
			);
		}

		return $result;
	}

	/**
	 * Test if the SQL server is up to date.
	 *
	 * @since 5.2.0
	 *
	 * @return array The test results.
	 */
	public function get_test_sql_server() {
		if ( ! $this->mysql_server_version ) {
			$this->prepare_sql_data();
		}

		$result = array(
			'label'       => __( 'SQL server is up to date' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Performance' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				__( 'The SQL server is a required piece of software for the database WordPress uses to store all your site&#8217;s content and settings.' )
			),
			'actions'     => sprintf(
				'<p><a href="%s" target="_blank" rel="noopener">%s <span class="screen-reader-text">%s</span><span aria-hidden="true" class="dashicons dashicons-external"></span></a></p>',
				/* translators: Localized version of WordPress requirements if one exists. */
				esc_url( __( 'https://wordpress.org/about/requirements/' ) ),
				__( 'Learn more about what WordPress requires to run.' ),
				/* translators: Accessibility text. */
				__( '(opens in a new tab)' )
			),
			'test'        => 'sql_server',
		);

		$db_dropin = file_exists( WP_CONTENT_DIR . '/db.php' );

		if ( ! $this->mysql_rec_version_check ) {
			$result['status'] = 'recommended';

			$result['label'] = __( 'Outdated SQL server' );

			$result['description'] .= sprintf(
				'<p>%s</p>',
				sprintf(
					/* translators: 1: The database engine in use (MySQL or MariaDB). 2: Database server recommended version number. */
					__( 'For optimal performance and security reasons, we recommend running %1$s version %2$s or higher. Contact your web hosting company to correct this.' ),
					( $this->is_mariadb ? 'MariaDB' : 'MySQL' ),
					$this->health_check_mysql_rec_version
				)
			);
		}

		if ( ! $this->mysql_min_version_check ) {
			$result['status'] = 'critical';

			$result['label']          = __( 'Severely outdated SQL server' );
			$result['badge']['label'] = __( 'Security' );

			$result['description'] .= sprintf(
				'<p>%s</p>',
				sprintf(
					/* translators: 1: The database engine in use (MySQL or MariaDB). 2: Database server minimum version number. */
					__( 'WordPress requires %1$s version %2$s or higher. Contact your web hosting company to correct this.' ),
					( $this->is_mariadb ? 'MariaDB' : 'MySQL' ),
					$this->health_check_mysql_required_version
				)
			);
		}

		if ( $db_dropin ) {
			$result['description'] .= sprintf(
				'<p>%s</p>',
				wp_kses(
					sprintf(
						/* translators: 1: The name of the drop-in. 2: The name of the database engine. */
						__( 'You are using a %1$s drop-in which might mean that a %2$s database is not being used.' ),
						'<code>wp-content/db.php</code>',
						( $this->is_mariadb ? 'MariaDB' : 'M