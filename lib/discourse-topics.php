<?php

namespace WPDiscourse\Shortcodes;

Use WPDiscourse\Utilities\Utilities as DiscourseUtilities;

class DiscourseTopics {
//	use Utilities;

	/**
	 * The key for the plugin's options array.
	 *
	 * @access protected
	 * @var string
	 */
	protected $option_key = 'wpds_options';

	/**
	 * The merged options from WP Discourse and WP Discourse Shortcodes.
	 *
	 * All options are held in a single array, use a custom plugin prefix to avoid naming collisions with wp-discourse.
	 *
	 * @access protected
	 * @var array
	 */
	protected $options;

	/**
	 * The Discourse forum url.
	 *
	 * @access protected
	 * @var string
	 */
	protected $discourse_url;

	/**
	 * The Discourse API key.
	 *
	 * @access protected
	 * @var string
	 */
	protected $api_key;

	/**
	 * The Discourse api_username.
	 *
	 * @access protected
	 * @var string
	 */
	protected $api_username;

	/**
	 * An instance of the TopicFormatter class.
	 *
	 * @access protected
	 * @var DiscourseTopicFormatter
	 */
	protected $topic_formatter;

	/**
	 * LatestTopics constructor.
	 *
	 * @param DiscourseTopicFormatter $topic_formatter An instance of DiscourseTopicFormatter.
	 */
	public function __construct( $topic_formatter ) {
		$this->topic_formatter = $topic_formatter;

		add_action( 'init', array( $this, 'setup_options' ) );
		add_action( 'rest_api_init', array( $this, 'initialize_topic_route' ) );
	}

	/**
	 * Adds the plugin options, gets the merged wp-discourse/wp-discourse-latest-topics options, sets the discourse_url.
	 */
	public function setup_options() {
		add_option( 'wpds_update_latest', 1 );
		$this->options       = DiscourseUtilities::get_options();
		$this->discourse_url = ! empty( $this->options['url'] ) ? $this->options['url'] : null;
		$this->api_key       = ! empty( $this->options['api-key'] ) ? $this->options['api-key'] : null;
		$this->api_username  = ! empty( $this->options['publish-username'] ) ? $this->options['publish-username'] : null;
	}

	/**
	 * Initializes a WordPress Rest API route and endpoint.
	 */
	public function initialize_topic_route() {
		if ( ! empty( $this->options['wpds_topic_webhook_refresh'] ) ) {
			register_rest_route( 'wp-discourse/v1', 'latest-topics', array(
				array(
					'methods'  => \WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'update_latest_topics' ),
				),
				array(
					'methods'  => \WP_REST_Server::READABLE,
					'callback' => array( $this, 'get_ajax_topics' ),
				),
			) );

			register_rest_route( 'wp-discourse/v1', 'discourse-post/(?P<id>\d+)', array(
				'methods'  => 'GET',
				'callback' => array( $this, 'get_discourse_post' ),
				'args'     => array(
					'id' => array(
						'validate_callback' => function ( $param, $request, $key ) {

							return is_numeric( $param );
						}
					),
				),
			) );
		}
	}

	public function get_discourse_post( $topic_id ) {
		$topic_url = esc_url_raw( "{$this->discourse_url}/t/{$topic_id}.json" );
		$response  = wp_remote_get( $topic_url );

		if ( ! DiscourseUtilities::validate( $response ) ) {

			// Todo: add message.
			return new \WP_Error();
		}

		$topic = json_decode( wp_remote_retrieve_body( $response ), true );
		$post  = $topic['post_stream']['posts'][0]['cooked'];

		return $post;
	}

	public function get_ajax_topics( $request ) {
		if ( empty( get_option( 'wpds_update_latest' ) ) ) {
			// The content is fresh.
//			return 0;
		}

		$args = [];
		if ( ! empty( $request['max_topics'] ) ) {
			$args['max_topics'] = esc_attr( wp_unslash( $request['max_topics'] ) );
		}

		if ( ! empty( $request['display_avatars'] ) ) {
			$args['display_avatars'] = esc_attr( wp_unslash( $request['display_avatars'] ) );
		}

		if ( ! empty( $request['source'] ) ) {
			$args['source'] = esc_attr( wp_unslash( $request['source'] ) );
		}

		if ( ! empty( $request['period'] ) ) {
			$args['period'] = esc_attr( wp_unslash( $request['period'] ) );
		}

		if ( ! empty( $request['tile'] ) ) {
			$args['tile'] = esc_attr( wp_unslash( $request['tile'] ) );
		}

		$topics = $this->get_topics( $args );
		if ( is_wp_error( $topics ) || empty( $topics ) ) {

			return 0;
		}

		return $topics;
	}

	/**
	 * Update latest topics transient.
	 *
	 * @param \WP_REST_Request $data
	 *
	 * @return null
	 */
	public function update_latest_topics( $data ) {
		$data = DiscourseUtilities::verify_discourse_webhook_request( $data );

		if ( is_wp_error( $data ) ) {

			return new \WP_Error( 'discourse_response_error', 'There was an error returned from Discourse when processing the
			latest_topics webhook.' );
		}

		update_option( 'wpds_update_latest', 1 );

		return null;
	}

	/**
	 * Get the latest topics from either from the stored transient, or from Discourse.
	 *
	 * @return string|null
	 */
	public function get_topics( $args ) {
		$args = shortcode_atts( array(
			'max_topics'      => 5,
			'display_avatars' => 'true',
			'source'          => 'latest',
			'period'          => 'daily',
			'cache_duration'  => 10,
			'tile'            => 'false',
			'excerpt_length'  => null,
		), $args );
		$time = time();

		if ( 'latest' === $args['source'] ) {
			$formatted_topics = get_transient( 'wpds_latest_topics' );

			// Todo: Use the cache busting option to delete the transient.
			if ( empty( $this->options['wpds_topic_webhook_refresh'] ) ) {
				// Webhooks aren't enabled, use the cache_duration arg.
				$last_sync      = get_option( 'wpds_latest_last_sync' );
				$cache_duration = $args['cache_duration'] * 60;
				$update         = $cache_duration + $last_sync < $time;
			} else {
				$update = ! empty( get_option( 'wpds_update_latest' ) );
			}

			if ( empty( $formatted_topics ) || $update ) {

				$latest_topics = $this->fetch_topics( 'latest', $args );

				if ( empty( $latest_topics ) || is_wp_error( $latest_topics ) ) {

					return new \WP_Error( 'wpds_get_topics_error', 'There was an error retrieving the formatted latest topics.' );
				} else {
					$formatted_topics = $this->topic_formatter->format_topics( $latest_topics, $args );
					set_transient( 'wpds_latest_topics', $formatted_topics, DAY_IN_SECONDS );
					update_option( 'wpds_update_latest', 0 );
					update_option( 'wpds_latest_last_sync', $time );
				}
			}

			return $formatted_topics;
		}

		if ( 'top' === $args['source'] ) {
			$period = $args['period'];
			if ( ! preg_match( '/^(all|yearly|quarterly|monthly|weekly|daily)$/', $period ) ) {
				$period = 'yearly';
			}
			$top_key          = 'wpds_top_' . $period;
			$top_sync_key     = $top_key . '_last_sync';
			$last_sync        = get_option( $top_sync_key );
			$cache_duration   = $args['cache_duration'] * 60;
			$update           = $cache_duration + $last_sync < $time;
			$formatted_topics = get_transient( $top_key );

			if ( empty( $formatted_topics ) || $update ) {
				$source = 'top/' . $period;

				$top_topics = $this->fetch_topics( $source );

				if ( empty( $top_topics ) || is_wp_error( $top_topics ) ) {

					return new \WP_Error( 'wpds_get_topics_error', 'There was an error retrieving the formatted top topics.' );
				} else {
					$formatted_topics = $this->topic_formatter->format_topics( $top_topics, $args );
					set_transient( $top_key, $formatted_topics, DAY_IN_SECONDS );
					update_option( $top_sync_key, $time );
				}
			}

			return $formatted_topics;
		}

		return new \WP_Error( 'wpds_get_topics_error', 'A valid topics source was not provided.' );
	}

	/**
	 * Fetch the latest topics from Discourse.
	 *
	 * @return array|mixed|null|object
	 */
	protected function fetch_topics( $source, $args = null ) {
		if ( empty( $this->discourse_url ) || empty( $this->api_key ) || empty( $this->api_username ) ) {

			return new \WP_Error( 'wp_discourse_configuration_error', 'The WP Discourse plugin is not properly configured.' );
		}

		$topics_url = $this->discourse_url . "/{$source}.json";

		if ( ! empty( $this->options['wpds_display_private_topics'] ) ) {
			$topics_url = add_query_arg( array(
				'api_key'      => $this->api_key,
				'api_username' => $this->api_username,
			), $topics_url );
		}

		$topics_url = esc_url_raw( $topics_url );

		$remote = wp_remote_get( $topics_url );

		if ( ! DiscourseUtilities::validate( $remote ) ) {

			return new \WP_Error( 'wp_discourse_response_error', 'An error was returned from Discourse when fetching the latest topics.' );
		}

		$data = json_decode( wp_remote_retrieve_body( $remote ), true );

		if ( ! empty( $args['excerpt_length'] ) ) {
			$excerpt_length = ! empty( $args['excerpt_length'] ) ? $args['excerpt_length'] : 55;
			$max_topics     = ! empty( $args['max_topics'] ) ? $args['max_topics'] : null;
			$topics         = $data['topic_list']['topics'];

			$count = 0;
			foreach ( $topics as $index => $topic ) {
				if ( $count <= $max_topics && $this->display_topic( $topic ) ) {
					$cooked = $this->get_discourse_post( $topic['id'] );

					if ( is_wp_error( $cooked ) ) {
						$excerpt = '';
					} elseif ( 'full' === $excerpt_length ) {
						$excerpt = $cooked;
					} else {
						$excerpt = wp_trim_words( wp_strip_all_tags( $cooked ), $excerpt_length );
					}

					$data['topic_list']['topics'][ $index ]['cooked'] = $excerpt;
					$count                                            += 1;
				}
			}
		}

		return $data;
	}

	protected function display_topic( $topic ) {

		return ! $topic['pinned_globally'] && 'regular' === $topic['archetype'] && - 1 !== $topic['posters'][0]['user_id'];
	}
}
