<?php
/**
 * Class AMP_Validation_Utils
 *
 * @package AMP
 */

/**
 * Class AMP_Validation_Utils
 *
 * @since 0.7
 */
class AMP_Validation_Utils {

	/**
	 * Key for the markup value in the REST API endpoint.
	 *
	 * @var string
	 */
	const MARKUP_KEY = 'markup';

	/**
	 * Query var that triggers validation.
	 *
	 * @todo Add amp prefix to this.
	 * @var string
	 */
	const VALIDATION_QUERY_VAR = 'validate';

	/**
	 * The slug of the post type to store AMP errors.
	 *
	 * @var string
	 */
	const POST_TYPE_SLUG = 'amp_validation_error';

	/**
	 * The key in the response for the sources that have invalid output.
	 *
	 * @var string
	 */
	const SOURCES_INVALID_OUTPUT = 'sources_with_invalid_output';

	/**
	 * Validation code for when element is removed.
	 *
	 * @var string
	 */
	const ELEMENT_REMOVED_CODE = 'element_removed';

	/**
	 * Validation code for when attribute is removed.
	 *
	 * @var string
	 */
	const ATTRIBUTE_REMOVED_CODE = 'attribute_removed';

	/**
	 * The meta key for the AMP URL where the error occurred.
	 *
	 * @var string
	 */
	const AMP_URL_META = 'amp_url';

	/**
	 * The key for removed elements.
	 *
	 * @var string
	 */
	const REMOVED_ELEMENTS = 'removed_elements';

	/**
	 * The key for removed attributes.
	 *
	 * @var string
	 */
	const REMOVED_ATTRIBUTES = 'removed_attributes';

	/**
	 * The key for removed sources.
	 *
	 * @var string
	 */
	const REMOVED_SOURCES = 'removed_sources';

	/**
	 * The action to recheck URLs for AMP validity.
	 *
	 * @var string
	 */
	const RECHECK_ACTION = 'amp_recheck';

	/**
	 * The query arg for whether there are remaining errors after rechecking URLs.
	 *
	 * @var string
	 */
	const REMAINING_ERRORS = 'amp_remaining_errors';

	/**
	 * The query arg for the number of URLs tested.
	 *
	 * @var string
	 */
	const URLS_TESTED = 'amp_urls_tested';

	/**
	 * The nonce action for rechecking a URL.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'amp_recheck_';

	/**
	 * The name of the cron event to validate URLs.
	 *
	 * @var string
	 */
	const CRON_EVENT = 'amp_validate_urls';

	/**
	 * The query var of the cron nonce.
	 *
	 * @var string
	 */
	const CUSTOM_CRON_NONCE = 'amp_validation_cron_nonce';

	/**
	 * The name of the transient to store the cron nonce.
	 *
	 * @var string
	 */
	const NONCE_TRANSIENT_NAME = 'amp_validation_cron';

	/**
	 * The errors encountered when validating.
	 *
	 * @var array[][] {
	 *     @type string  $code        Error code.
	 *     @type string  $node_name   Name of removed node.
	 *     @type string  $parent_name Name of parent node.
	 * }
	 */
	public static $validation_errors = array();

	/**
	 * Add the actions.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'amp_rest_validation' ) );
		add_action( 'edit_form_top', array( __CLASS__, 'validate_content' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'all_admin_notices', array( __CLASS__, 'plugin_notice' ) );
		add_filter( 'manage_' . self::POST_TYPE_SLUG . '_posts_columns', array( __CLASS__, 'add_post_columns' ) );
		add_action( 'manage_posts_custom_column', array( __CLASS__, 'output_custom_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'add_recheck' ), 10, 2 );
		add_filter( 'bulk_actions-edit-' . self::POST_TYPE_SLUG, array( __CLASS__, 'add_bulk_action' ), 10, 2 );
		add_filter( 'handle_bulk_actions-edit-' . self::POST_TYPE_SLUG, array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'remaining_error_notice' ) );
		add_action( 'post_action_' . self::RECHECK_ACTION, array( __CLASS__, 'handle_inline_recheck' ) );
		add_action( 'init', array( __CLASS__, 'schedule_cron' ) );
		add_action( self::CRON_EVENT, array( __CLASS__, 'cron_validate_urls' ) );
		add_action( 'admin_menu', array( __CLASS__, 'remove_publish_meta_box' ) );

		// @todo There is more than just node removal that needs to be reported. There is also script enqueues, external stylesheets, cdata length, etc.
		// Actions and filters involved in validation.
		add_action( 'activate_plugin', function() {
			if ( ! has_action( 'shutdown', array( __CLASS__, 'validate_home' ) ) ) {
				add_action( 'shutdown', array( __CLASS__, 'validate_home' ) ); // Shutdown so all plugins will have been activated.
			}
		} );
	}

	/**
	 * Add hooks for doing validation during preprocessing/sanitizing.
	 */
	public static function add_validation_hooks() {
		add_action( 'wp', array( __CLASS__, 'callback_wrappers' ) );
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'decorate_shortcode_source' ), -1, 2 );
		add_filter( 'amp_content_sanitizers', array( __CLASS__, 'add_validation_callback' ) );
	}

	/**
	 * Tracks when a sanitizer removes a node (element or attribute).
	 *
	 * @param array $removed {
	 *     The data of the removed node.
	 *
	 *     @type DOMElement|DOMNode $node   The removed node.
	 *     @type DOMElement|DOMNode $parent The parent of the removed node.
	 * }
	 * @return void
	 */
	public static function track_removed( $removed ) {
		$node  = $removed['node'];
		$error = array(
			'node_name' => $node->nodeName,
			'sources'   => self::locate_sources( $node ),
		);
		if ( $node->parentNode ) {
			$error['parent_name'] = $node->parentNode->nodeName;
		}
		if ( $removed['node'] instanceof DOMElement ) {
			$error['code'] = self::ELEMENT_REMOVED_CODE;
		} elseif ( $removed['node'] instanceof DOMAttr ) {
			$error['code'] = self::ATTRIBUTE_REMOVED_CODE;
		}

		self::add_validation_error( $error );
	}

	/**
	 * Processes markup, to determine AMP validity.
	 *
	 * Passes $markup through the AMP sanitizers.
	 * Also passes a 'remove_invalid_callback' to keep track of stripped attributes and nodes.
	 *
	 * @param string $markup The markup to process.
	 * @return string Sanitized markup.
	 */
	public static function process_markup( $markup ) {
		AMP_Theme_Support::register_content_embed_handlers();

		/** This filter is documented in wp-includes/post-template.php */
		$markup = apply_filters( 'the_content', $markup );
		$args   = array(
			'content_max_width'       => ! empty( $content_width ) ? $content_width : AMP_Post_Template::CONTENT_MAX_WIDTH,
			'remove_invalid_callback' => 'AMP_Validation_Utils::track_removed',
		);

		$results = AMP_Content_Sanitizer::sanitize( $markup, amp_get_content_sanitizers(), $args );
		return $results[0];
	}

	/**
	 * Registers the REST API endpoint for validation.
	 *
	 * @return void
	 */
	public static function amp_rest_validation() {
		register_rest_route( 'amp-wp/v1', '/validate', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_validate_request' ),
			'args'                => array(
				self::MARKUP_KEY => array(
					'validate_callback' => array( __CLASS__, 'validate_arg' ),
				),
			),
			'permission_callback' => array( __CLASS__, 'has_cap' ),
		) );
	}

	/**
	 * Whether the user has the required capability.
	 *
	 * Checks for permissions before validating.
	 * Also serves as the permission callback for REST requests.
	 *
	 * @return boolean $has_cap Whether the current user has the capability.
	 */
	public static function has_cap() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Validate the markup passed to the REST API.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return array|WP_Error.
	 */
	public static function handle_validate_request( WP_REST_Request $request ) {
		$json = $request->get_json_params();
		if ( empty( $json[ self::MARKUP_KEY ] ) ) {
			return new WP_Error( 'no_markup', 'No markup passed to validator', array(
				'status' => 404,
			) );
		}

		// @todo Add request param to indicate whether the supplied content is raw (and needs the_content filters applied).
		$processed = self::process_markup( $json[ self::MARKUP_KEY ] );
		$response  = self::summarize_validation_errors( self::$validation_errors );
		self::reset_validation_results();
		$response['processed_markup'] = $processed;
		return $response;
	}

	/**
	 * Add validation error.
	 *
	 * @param array $data {
	 *     Data.
	 *
	 *     @type string $code Error code.
	 * }
	 */
	public static function add_validation_error( array $data ) {
		if ( ! isset( $data['code'] ) ) {
			$data['code'] = 'unknown';
		}
		self::$validation_errors[] = $data;
	}

	/**
	 * Gets the AMP validation response.
	 *
	 * Returns the current validation errors the sanitizers found in rendering the page.
	 *
	 * @param array $validation_errors Validation errors.
	 * @return array The AMP validity of the markup.
	 */
	public static function summarize_validation_errors( $validation_errors ) {
		$results            = array();
		$removed_elements   = array();
		$removed_attributes = array();
		$invalid_sources    = array();
		foreach ( $validation_errors as $validation_error ) {
			$code = isset( $validation_error['code'] ) ? $validation_error['code'] : null;

			if ( self::ELEMENT_REMOVED_CODE === $code ) {
				if ( ! isset( $removed_elements[ $validation_error['node_name'] ] ) ) {
					$removed_elements[ $validation_error['node_name'] ] = 0;
				}
				$removed_elements[ $validation_error['node_name'] ] += 1;
			} elseif ( self::ATTRIBUTE_REMOVED_CODE === $code ) {
				if ( ! isset( $removed_attributes[ $validation_error['node_name'] ] ) ) {
					$removed_attributes[ $validation_error['node_name'] ] = 0;
				}
				$removed_attributes[ $validation_error['node_name'] ] += 1;
			}

			// @todo It would be best if the invalid source was tied to the invalid elements and attributes.
			if ( ! empty( $validation_error['sources'] ) ) {
				$source = array_pop( $validation_error['sources'] );

				$invalid_sources[ $source['type'] ][] = $source['name'];
			}
		}

		$results = array_merge(
			array(
				self::SOURCES_INVALID_OUTPUT => $invalid_sources,
			),
			compact(
				'removed_elements',
				'removed_attributes'
			),
		$results );

		return $results;
	}

	/**
	 * Reset the stored removed nodes and attributes.
	 *
	 * After testing if the markup is valid,
	 * these static values will remain.
	 * So reset them in case another test is needed.
	 *
	 * @return void
	 */
	public static function reset_validation_results() {
		self::$validation_errors = array();
	}

	/**
	 * Validate the argument in the REST API request.
	 *
	 * It would be ideal to simply pass 'is_string' in register_rest_route().
	 * But it always returned false.
	 *
	 * @param mixed $arg      The argument to validate.
	 * @return boolean $is_valid Whether the argument is valid.
	 */
	public static function validate_arg( $arg ) {
		return is_string( $arg );
	}

	/**
	 * Checks the AMP validity of the post content.
	 *
	 * If it's not valid AMP,
	 * it displays an error message above the 'Classic' editor.
	 *
	 * @param WP_Post $post The updated post.
	 * @return void
	 */
	public static function validate_content( $post ) {
		if ( ! post_supports_amp( $post ) ) {
			return;
		}

		self::process_markup( $post->post_content );
		$results = self::summarize_validation_errors( self::$validation_errors );
		if ( ! empty( self::$validation_errors ) ) {
			self::display_error( $results );
		}
		self::reset_validation_results();
	}

	/**
	 * Get source start comment.
	 *
	 * @param string $type Extension type.
	 * @param string $name Extension name.
	 * @param array  $args Args.
	 * @return string HTML Comment.
	 */
	public static function get_source_comment_start( $type, $name, $args = array() ) {
		return sprintf( '<!--amp-source-stack:%s:%s %s-->', $type, $name, str_replace( '--', '', wp_json_encode( $args ) ) );
	}

	/**
	 * Get source end comment.
	 *
	 * @param string $type Extension type.
	 * @param string $name Extension name.
	 * @return string HTML Comment.
	 */
	public static function get_source_comment_end( $type, $name ) {
		return sprintf( '<!--/amp-source-stack:%s:%s-->', $type, $name );
	}

	/**
	 * Parse source comment.
	 *
	 * @param DOMComment $comment Comment.
	 * @return array|null Source info or null if not a source comment.
	 */
	public static function parse_source_comment( DOMComment $comment ) {
		if ( ! preg_match( '#^\s*(?P<closing>/)?amp-source-stack:(?P<type>theme|plugin|mu-plugin):(?P<name>\S+)(?: (?P<args>{.+}))?\s*$#s', $comment->nodeValue, $matches ) ) {
			return null;
		}
		$source = wp_array_slice_assoc( $matches, array( 'type', 'name' ) );

		$source['closing'] = ! empty( $matches['closing'] );
		if ( isset( $matches['args'] ) ) {
			$source['args'] = json_decode( $matches['args'], true );
		}
		return $source;
	}

	/**
	 * Walk back tree to find the open sources.
	 *
	 * @param DOMNode $node Node to look for.
	 * @return array[][] {
	 *       The data of the removed sources (theme, plugin, or mu-plugin).
	 *
	 *       @type string $name The name of the source.
	 *       @type string $type The type of the source.
	 * }
	 */
	public static function locate_sources( DOMNode $node ) {
		$xpath    = new DOMXPath( $node->ownerDocument );
		$comments = $xpath->query( 'preceding::comment()[ contains( ., "amp-source-stack:" ) ]', $node );
		$sources  = array();
		foreach ( $comments as $comment ) {
			$source = self::parse_source_comment( $comment );
			if ( $source ) {
				if ( $source['closing'] ) {
					array_pop( $sources );
				} else {
					unset( $source['closing'] );
					$sources[] = $source;
				}
			}
		}
		return $sources;
	}

	/**
	 * Wraps callbacks in comments to indicate to the sanitizer which extension added them.
	 *
	 * Iterates through all of the registered callbacks for actions and filters.
	 * If a callback is from a plugin and outputs markup, this wraps the markup in comments.
	 * Later, the sanitizer can identify which theme or plugin the illegal markup is from.
	 *
	 * @global array $wp_filter
	 * @return void
	 */
	public static function callback_wrappers() {
		global $wp_filter;
		$pending_wrap_callbacks = array();
		foreach ( $wp_filter as $filter_tag => $wp_hook ) {
			foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$source_data = self::get_source( $callback['function'] );
					if ( isset( $source_data ) ) {
						$pending_wrap_callbacks[ $filter_tag ][] = array_merge(
							$callback,
							$source_data,
							array(
								'hook' => $filter_tag,
							),
							compact( 'priority' )
						);
					}
				}
			}
		}

		// Iterate over hooks to replace after iterating over all to begin with to prevent infinite loop in PHP<=5.4.
		foreach ( $pending_wrap_callbacks as $hook => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				remove_action( $hook, $callback['function'], $callback['priority'] );
				$wrapped_callback = self::wrapped_callback( $callback );
				add_action( $hook, $wrapped_callback, $callback['priority'], $callback['accepted_args'] );
			}
		}
	}

	/**
	 * Filters the output created by a shortcode callback.
	 *
	 * @since 0.7
	 *
	 * @param string $output Shortcode output.
	 * @param string $tag    Shortcode name.
	 * @return string Output.
	 * @global array $shortcode_tags
	 */
	public static function decorate_shortcode_source( $output, $tag ) {
		global $shortcode_tags;
		if ( ! isset( $shortcode_tags[ $tag ] ) ) {
			return $output;
		}
		$source = self::get_source( $shortcode_tags[ $tag ] );
		if ( empty( $source ) ) {
			return $output;
		}
		$output = implode( '', array(
			self::get_source_comment_start( $source['type'], $source['name'], array( 'shortcode' => $tag ) ),
			$output,
			self::get_source_comment_end( $source['type'], $source['name'] ),
		) );
		return $output;
	}

	/**
	 * Gets the plugin or theme of the callback, if one exists.
	 *
	 * @param string|array $callback The callback for which to get the plugin.
	 * @return array|null {
	 *     The source data.
	 *
	 *     @type string $type Source type.
	 *     @type string $name Source name.
	 * }
	 */
	public static function get_source( $callback ) {
		try {
			if ( is_string( $callback ) && is_callable( $callback ) ) {
				// The $callback is a function or static method.
				$exploded_callback = explode( '::', $callback );
				if ( count( $exploded_callback ) > 1 ) {
					$reflection = new ReflectionClass( $exploded_callback[0] );
				} else {
					$reflection = new ReflectionFunction( $callback );
				}
			} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) && method_exists( $callback[0], $callback[1] ) ) {
				// The $callback is a method.
				$reflection = new ReflectionClass( $callback[0] );
			} elseif ( is_object( $callback ) && ( 'Closure' === get_class( $callback ) ) ) {
				$reflection = new ReflectionFunction( $callback );
			}
		} catch ( Exception $e ) {
			return null;
		}

		$file = isset( $reflection ) ? $reflection->getFileName() : null;
		if ( ! isset( $file ) ) {
			return null;
		}
		$file = wp_normalize_path( $file );

		$slug_pattern = '([^/]+)';
		if ( preg_match( ':' . preg_quote( trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ), ':' ) . $slug_pattern . ':s', $file, $matches ) ) {
			$type = 'plugin';
			$name = $matches[1];
		} elseif ( preg_match( ':' . preg_quote( trailingslashit( wp_normalize_path( get_theme_root() ) ), ':' ) . $slug_pattern . ':s', $file, $matches ) ) {
			$type = 'theme';
			$name = $matches[1];
		} elseif ( preg_match( ':' . preg_quote( trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ), ':' ) . $slug_pattern . ':s', $file, $matches ) ) {
			$type = 'mu-plugin';
			$name = $matches[1];
		}

		if ( isset( $type, $name ) ) {
			return compact( 'type', 'name' );
		}
		return null;
	}

	/**
	 * Wraps a callback in comments if it outputs markup.
	 *
	 * If the sanitizer removes markup,
	 * this indicates which plugin it was from.
	 * The call_user_func_array() logic is mainly copied from WP_Hook:apply_filters().
	 *
	 * @param array $callback {
	 *     The callback data.
	 *
	 *     @type callable $function
	 *     @type int      $accepted_args
	 *     @type string   $type
	 *     @type string   $source
	 *     @type string   $hook
	 * }
	 * @return closure $wrapped_callback The callback, wrapped in comments.
	 */
	public static function wrapped_callback( $callback ) {
		return function() use ( $callback ) {
			$function      = $callback['function'];
			$accepted_args = $callback['accepted_args'];
			$args          = func_get_args();

			ob_start();
			$result = call_user_func_array( $function, array_slice( $args, 0, intval( $accepted_args ) ) );
			$output = ob_get_clean();

			// Wrap output that contains HTML tags (as opposed to actions that trigger in HTML attributes).
			if ( ! empty( $output ) && preg_match( '/<.+?>/s', $output ) ) {
				echo AMP_Validation_Utils::get_source_comment_start( $callback['type'], $callback['name'], array( 'hook' => $callback['hook'] ) ); // WPCS: XSS ok.
				echo $output; // WPCS: XSS ok.
				echo AMP_Validation_Utils::get_source_comment_end( $callback['type'], $callback['name'] ); // WPCS: XSS ok.
			}
			return $result;
		};
	}

	/**
	 * Displays an error message on /wp-admin/post.php.
	 *
	 * Located at the top of the 'Classic' editor.
	 * States that the content is not valid AMP.
	 *
	 * @param array $response The validation response, an associative array.
	 * @return void
	 */
	public static function display_error( $response ) {
		echo '<div class="notice notice-warning">';
		printf( '<p>%s</p>', esc_html__( 'Warning: There is content which fails AMP validation; it will be stripped when served as AMP.', 'amp' ) );
		$removed_sets = array();
		if ( ! empty( $response[ self::REMOVED_ELEMENTS ] ) && is_array( $response[ self::REMOVED_ELEMENTS ] ) ) {
			$removed_sets[] = array(
				'label' => __( 'Invalid elements:', 'amp' ),
				'names' => array_map( 'sanitize_key', $response[ self::REMOVED_ELEMENTS ] ),
			);
		}
		if ( ! empty( $response[ self::REMOVED_ATTRIBUTES ] ) && is_array( $response[ self::REMOVED_ATTRIBUTES ] ) ) {
			$removed_sets[] = array(
				'label' => __( 'Invalid attributes:', 'amp' ),
				'names' => array_map( 'sanitize_key', $response[ self::REMOVED_ATTRIBUTES ] ),
			);
		}
		foreach ( $removed_sets as $removed_set ) {
			printf( '<p>%s ', esc_html( $removed_set['label'] ) );
			self::output_removed_set( $removed_set['names'] );
			echo '</p>';
		}
		echo '</div>';
	}

	/**
	 * Output a removed set, each wrapped in <code></code>.
	 *
	 * @param array[][] $set {
	 *     The removed elements to output.
	 *
	 *     @type string $name  The name of the source.
	 *     @type string $count The number that were invalid.
	 * }
	 * @return void
	 */
	public static function output_removed_set( $set ) {
		$items = array();
		foreach ( $set as $name => $count ) {
			if ( 1 === intval( $count ) ) {
				$items[] = sprintf( '<code>%s</code>', esc_html( $name ) );
			} else {
				$items[] = sprintf( '<code>%s</code> (%d)', esc_html( $name ), $count );
			}
		}
		echo implode( ', ', $items ); // WPCS: XSS OK.
	}

	/**
	 * Whether to validate the front end response.
	 *
	 * Either the user has the capability and the query var is present,
	 * or this is a cron job, and the nonce saved in the transient must match that passed in the request.
	 *
	 * @return boolean
	 */
	public static function should_validate_front_end() {
		$should_validate = (
			( self::has_cap() && ( isset( $_GET[ self::VALIDATION_QUERY_VAR ] ) ) )
			||
			(
				isset( $_GET[ self::CUSTOM_CRON_NONCE ] )
				&&
				( get_transient( self::NONCE_TRANSIENT_NAME ) === sanitize_key( wp_unslash( $_GET[ self::CUSTOM_CRON_NONCE ] ) ) )
			)
		); // WPCS: CSRF ok.
		return $should_validate;
	}

	/**
	 * Adds the validation callback if front-end validation is needed.
	 *
	 * @param array $sanitizers The AMP sanitizers.
	 * @return array $sanitizers The filtered AMP sanitizers.
	 */
	public static function add_validation_callback( $sanitizers ) {
		foreach ( $sanitizers as $sanitizer => $args ) {
			$sanitizers[ $sanitizer ] = array_merge(
				$args,
				array(
					'remove_invalid_callback' => __CLASS__ . '::track_removed',
				)
			);
		}
		return $sanitizers;
	}

	/**
	 * Registers the post type to store the validation errors.
	 *
	 * @return void.
	 */
	public static function register_post_type() {
		$post_type = register_post_type(
			self::POST_TYPE_SLUG,
			array(
				'labels'       => array(
					'name'               => _x( 'Validation Status', 'post type general name', 'amp' ),
					'singular_name'      => __( 'validation error', 'amp' ),
					'not_found'          => __( 'No validation errors found', 'amp' ),
					'not_found_in_trash' => __( 'No validation errors found in trash', 'amp' ),
					'search_items'       => __( 'Search statuses', 'amp' ),
					'edit_item'          => __( 'Validation Status', 'amp' ),
				),
				'supports'     => false,
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => AMP_Options_Manager::OPTION_NAME,
			)
		);

		// Hide the add new post link.
		$post_type->cap->create_posts = 'do_not_allow';
	}

	/**
	 * Stores the validation errors.
	 *
	 * After the preprocessors run, this gets the validation response if the query var is present.
	 * It then stores the response in a custom post type.
	 * If there's already an error post for the URL, but there's no error anymore, it deletes it.
	 *
	 * @param array  $validation_errors Validation errors.
	 * @param string $url               URL on which the validation errors occurred.
	 * @return int|null $post_id The post ID of the custom post type used, or null.
	 * @global WP $wp
	 */
	public static function store_validation_errors( $validation_errors, $url ) {

		// Remove query vars that are only used to initiate validation requests.
		$url = remove_query_arg(
			array(
				self::VALIDATION_QUERY_VAR,
				self::CUSTOM_CRON_NONCE,
				'amp_disable_invalid_removal',
			),
			$url
		);

		$post_for_this_url = self::get_validation_status_post( $url );

		// Since there are no validation errors and there is an existing $existing_post_id, just delete the post.
		if ( empty( $validation_errors ) ) {
			if ( $post_for_this_url ) {
				wp_delete_post( $post_for_this_url->ID, true );
			}
			return null;
		}

		$encoded_errors = wp_json_encode( $validation_errors );
		$post_name      = md5( $encoded_errors );

		if ( $post_for_this_url ) {
			if ( $post_for_this_url->post_name === $post_name ) {
				// If the post name is unchanged then the errors are the same and there is nothing to do.
				return $post_for_this_url->ID;
			} else {
				// Otherwise, if the post name is changed, then the errors are now different for the URL (and any other associated URLs) so delete and create anew.
				wp_delete_post( $post_for_this_url->ID, true );
				$post_for_this_url = null;
			}
		}

		// If there already exists a post for the given validation errors, just amend the $url to the existing post.
		$post_for_other_url = get_page_by_path( $post_name, OBJECT, self::POST_TYPE_SLUG );
		if ( $post_for_other_url ) {
			if ( ! in_array( $url, get_post_meta( $post_for_other_url->ID, self::AMP_URL_META, false ), true ) ) {
				add_post_meta( $post_for_other_url->ID, self::AMP_URL_META, wp_slash( $url ), false );
			}
			return $post_for_other_url->ID;
		}

		// Otherwise, create a new validation status post.
		return wp_insert_post( wp_slash( array(
			'post_type'    => self::POST_TYPE_SLUG,
			'post_title'   => $url,
			'post_name'    => $post_name,
			'post_content' => $encoded_errors,
			'post_status'  => 'publish',
			'meta_input'   => array(
				self::AMP_URL_META => $url,
			),
		) ) );
	}

	/**
	 * Gets the existing custom post that stores errors for the $url, if it exists.
	 *
	 * @param string $url The URL of the post.
	 * @return WP_Post|null The post of the existing custom post, or null.
	 */
	public static function get_validation_status_post( $url ) {
		$query = new WP_Query( array(
			'post_type'      => self::POST_TYPE_SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'   => self::AMP_URL_META,
					'value' => $url,
				),
			),
		) );
		return array_shift( $query->posts );
	}

	/**
	 * Validates the home page.
	 *
	 * @return array|WP_Error The response array, or WP_Error.
	 */
	public static function validate_home() {
		return self::validate_url( home_url() );
	}

	/**
	 * Validates a given URL, and stores its errors in a custom post.
	 *
	 * @param string $url The URL to validate.
	 * @return array|WP_Error The response array, or WP_Error.
	 */
	public static function validate_url( $url ) {
		$validation_url = add_query_arg(
			self::VALIDATION_QUERY_VAR,
			1,
			$url
		);
		return wp_remote_get( $validation_url, array(
			'cookies'   => wp_unslash( $_COOKIE ),
			'sslverify' => false,
		) );
	}

	/**
	 * On activating a plugin, display a notice if a plugin causes an AMP validation error.
	 *
	 * @return void
	 */
	public static function plugin_notice() {
		global $pagenow;
		if ( ( 'plugins.php' === $pagenow ) && ( ! empty( $_GET['activate'] ) || ! empty( $_GET['activate-multi'] ) ) ) { // WPCS: CSRF ok.
			$error_post = self::get_validation_status_post( home_url() );
			if ( ! $error_post ) {
				return;
			}
			$validation_errors = json_decode( $error_post->post_content, true );
			if ( ! is_array( $validation_errors ) ) {
				return;
			}
			$errors          = self::summarize_validation_errors( $validation_errors );
			$invalid_plugins = isset( $errors[ self::SOURCES_INVALID_OUTPUT ]['plugin'] ) ? array_unique( $errors[ self::SOURCES_INVALID_OUTPUT ]['plugin'] ) : null;
			if ( isset( $invalid_plugins ) ) {
				$reported_plugins = array();
				foreach ( $invalid_plugins as $plugin ) {
					$reported_plugins[] = sprintf( '<code>%s</code>', esc_html( $plugin ) );
				}

				$more_details_link = sprintf(
					'<a href="%s">%s</a>',
					esc_url( add_query_arg(
						'post_type',
						self::POST_TYPE_SLUG,
						admin_url( 'edit.php' )
					) ),
					__( 'more details', 'amp' )
				);
				printf(
					'<div class="notice notice-warning is-dismissible"><p>%s %s %s</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">%s</span></button></div>',
					esc_html( _n( 'Warning: the following plugin is incompatible with AMP:', 'Warning: the following plugins are incompatible with AMP: ', count( $invalid_plugins ), 'amp' ) ),
					implode( ', ', $reported_plugins ),
					$more_details_link,
					esc_html__( 'Dismiss this notice.', 'amp' )
				); // WPCS: XSS ok.
			}
		}
	}

	/**
	 * Adds post columns to the UI for the validation errors.
	 *
	 * @param array $columns The post columns.
	 * @return array $columns The new post columns.
	 */
	public static function add_post_columns( $columns ) {
		$columns = array_merge(
			$columns,
			array(
				'url_count'                  => esc_html__( 'Count', 'amp' ),
				self::REMOVED_ELEMENTS       => esc_html__( 'Removed Elements', 'amp' ),
				self::REMOVED_ATTRIBUTES     => esc_html__( 'Removed Attributes', 'amp' ),
				self::SOURCES_INVALID_OUTPUT => esc_html__( 'Incompatible Sources', 'amp' ),
			)
		);

		// Move date to end.
		if ( isset( $columns['date'] ) ) {
			$date = $columns['date'];
			unset( $columns['date'] );
			$columns['date'] = $date;
		}

		return $columns;
	}

	/**
	 * Outputs custom columns in the /wp-admin UI for the AMP validation errors.
	 *
	 * @param string $column_name The name of the column.
	 * @param int    $post_id     The ID of the post for the column.
	 * @return void
	 */
	public static function output_custom_column( $column_name, $post_id ) {
		$post = get_post( $post_id );
		if ( self::POST_TYPE_SLUG !== $post->post_type ) {
			return;
		}
		$validation_errors = json_decode( $post->post_content, true );
		if ( ! is_array( $validation_errors ) ) {
			return;
		}
		$errors = self::summarize_validation_errors( $validation_errors );
		$urls   = get_post_meta( $post_id, self::AMP_URL_META, false );

		switch ( $column_name ) {
			case 'url_count':
				echo count( $urls );
				break;
			case self::REMOVED_ELEMENTS:
				if ( ! empty( $errors[ self::REMOVED_ELEMENTS ] ) ) {
					self::output_removed_set( $errors[ self::REMOVED_ELEMENTS ] );
				} else {
					esc_html_e( '--', 'amp' );
				}
				break;
			case self::REMOVED_ATTRIBUTES:
				if ( ! empty( $errors[ self::REMOVED_ATTRIBUTES ] ) ) {
					self::output_removed_set( $errors[ self::REMOVED_ATTRIBUTES ] );
				} else {
					esc_html_e( '--', 'amp' );
				}
				break;
			case self::SOURCES_INVALID_OUTPUT:
				if ( isset( $errors[ self::SOURCES_INVALID_OUTPUT ] ) ) {
					$sources = array();
					foreach ( $errors[ self::SOURCES_INVALID_OUTPUT ] as $type => $names ) {
						foreach ( array_unique( $names ) as $name ) {
							$sources[] = sprintf( '%s: <code>%s</code>', esc_html( $type ), esc_html( $name ) );
						}
					}
					echo implode( ', ', $sources ); // WPCS: XSS ok.
				}
				break;
		}
	}

	/**
	 * Adds a 'Recheck' link to the edit.php row actions.
	 *
	 * The logic to add the new action is mainly copied from WP_Posts_List_Table::handle_row_actions().
	 *
	 * @param array   $actions The actions in the edit.php page.
	 * @param WP_Post $post    The post for the actions.
	 * @return array $actions The filtered actions.
	 */
	public static function add_recheck( $actions, $post ) {
		if ( self::POST_TYPE_SLUG !== $post->post_type ) {
			return $actions;
		}

		// @todo Add link to view frontend without sanitization applied to debug.
		$actions['edit'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( get_edit_post_link( $post ) ),
			esc_html__( 'Details', 'amp' )
		);
		unset( $actions['inline hide-if-no-js'] );
		$url = get_post_meta( $post->ID, self::AMP_URL_META, true );

		if ( ! empty( $url ) ) {
			$post_type_object                = get_post_type_object( $post->post_type );
			$actions[ self::RECHECK_ACTION ] = sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				wp_nonce_url(
					add_query_arg(
						'action',
						self::RECHECK_ACTION,
						admin_url( sprintf( $post_type_object->_edit_link, $post->ID ) )
					),
					self::NONCE_ACTION . $post->ID
				),
				esc_html__( 'Recheck the URL for AMP validity', 'amp' ),
				__( 'Recheck', 'amp' )
			);
		}

		return $actions;
	}

	/**
	 * Adds a 'Recheck' bulk action to the edit.php page.
	 *
	 * @param array $actions The bulk actions in the edit.php page.
	 * @return array $actions The filtered bulk actions.
	 */
	public static function add_bulk_action( $actions ) {
		unset( $actions['edit'] );
		$actions[ self::RECHECK_ACTION ] = esc_html__( 'Recheck', 'amp' );
		return $actions;
	}

	/**
	 * Handles the 'Recheck' bulk action on the edit.php page.
	 *
	 * @param string $redirect The URL of the redirect.
	 * @param string $action   The action.
	 * @param array  $items    The items on which to take the action.
	 * @return string $redirect The filtered URL of the redirect.
	 */
	public static function handle_bulk_action( $redirect, $action, $items ) {
		if ( self::RECHECK_ACTION !== $action ) {
			return $redirect;
		}
		$urls = array();
		foreach ( $items as $item ) {
			$url = get_post_meta( $item, self::AMP_URL_META, true );
			if ( ! empty( $url ) ) {
				$urls[] = $url;
				self::validate_url( $url );
			}
		}

		// Get the URLs that still have errors after rechecking.
		$args = array(
			self::URLS_TESTED      => count( $items ),
			self::REMAINING_ERRORS => '0',
		);
		foreach ( $urls as $url ) {
			if ( self::get_validation_status_post( $url ) ) {
				$args[ self::REMAINING_ERRORS ] = '1';
				break;
			}
		}

		return add_query_arg( $args, $redirect );
	}

	/**
	 * Outputs an admin notice after rechecking URL(s) on the custom post page.
	 *
	 * @return void
	 */
	public static function remaining_error_notice() {
		if ( ! isset( $_GET[ self::REMAINING_ERRORS ] ) || self::POST_TYPE_SLUG !== get_current_screen()->post_type ) { // WPCS: CSRF ok.
			return;
		}

		$count_urls_tested = isset( $_GET[ self::URLS_TESTED ] ) ? intval( $_GET[ self::URLS_TESTED ] ) : 1; // WPCS: CSRF ok.
		$errors_remain     = ! empty( $_GET[ self::REMAINING_ERRORS ] ); // WPCS: CSRF ok.
		if ( $errors_remain ) {
			$class   = 'notice-warning';
			$message = _n( 'The rechecked URL still has validation errors.', 'The rechecked URLs still have validation errors.', $count_urls_tested, 'amp' );
		} else {
			$message = _n( 'The rechecked URL has no validation error.', 'The rechecked URLs have no validation error.', $count_urls_tested, 'amp' );
			$class   = 'updated';
		}

		printf(
			'<div class="notice is-dismissible %s"><p>%s</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">%s</span></button></div>',
			esc_attr( $class ),
			esc_html( $message ),
			esc_html__( 'Dismiss this notice.', 'amp' )
		);
	}

	/**
	 * Handles clicking 'recheck' on the inline post actions.
	 *
	 * @param integer $post_id The post ID of the recheck.
	 * @return void
	 */
	public static function handle_inline_recheck( $post_id ) {
		check_admin_referer( self::NONCE_ACTION . $post_id );
		$url = get_post_meta( $post_id, self::AMP_URL_META, true );
		self::validate_url( $url );

		$args = array(
			self::URLS_TESTED      => '1',
			self::REMAINING_ERRORS => self::get_validation_status_post( $url ) ? '1' : '0',
		);
		wp_safe_redirect( add_query_arg( $args, wp_get_referer() ) );
		exit();
	}

	/**
	 * Schedules the cron job to validate URLs.
	 *
	 * @return void
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_EVENT ) ) {
			wp_schedule_event( time(), 'twicedaily', self::CRON_EVENT );
		}
	}

	/**
	 * Validates URLs when the cron action occurs.
	 *
	 * Makes validation requests to the home URL and the most recent post.
	 * Creates a custom nonce, saves it in a transient, and passes it in the request.
	 * Then, should_validate_front_end() verifies whether the passed nonce matches the transient.
	 *
	 * @return void
	 */
	public static function cron_validate_urls() {
		if ( ! isset( $_GET['doing_wp_cron'] ) ) { // WPCS: CSRF ok.
			return;
		}

		$nonce             = md5( sanitize_key( wp_unslash( $_GET['doing_wp_cron'] ) ) ); // WPCS: CSRF ok.
		$minute_in_seconds = 60;
		set_transient( self::NONCE_TRANSIENT_NAME, $nonce, $minute_in_seconds );

		$urls_to_validate           = array( get_home_url() );
		$recent_posts               = wp_get_recent_posts(
			array(
				'numberposts' => 1,
				'post_status' => 'publish',
			),
			OBJECT
		);
		$most_recent_post_permalink = is_array( $recent_posts ) ? get_permalink( reset( $recent_posts ) ) : null;
		if ( ! empty( $most_recent_post_permalink ) ) {
			$urls_to_validate[] = $most_recent_post_permalink;
		}

		foreach ( $urls_to_validate as $url ) {
			wp_remote_get( add_query_arg(
				self::CUSTOM_CRON_NONCE,
				$nonce,
				$url
			) );
		};
	}

	/**
	 * Removes the 'Publish' meta box from the CPT post.php page.
	 *
	 * @return void
	 */
	public static function remove_publish_meta_box() {
		remove_meta_box( 'submitdiv', self::POST_TYPE_SLUG, 'side' );
	}

}
