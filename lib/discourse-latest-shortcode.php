<?php

namespace WPDiscourse\LatestTopics;

use WPDiscourse\Utilities\Utilities as DiscourseUtilities;

class DiscourseLatestShortcode {
	/**
	 * The plugin options.
	 *
	 * @access protected
	 * @var array
	 */
	protected $options;

	/**
	 * The Discourse forum URL.
	 *
	 * @var string
	 */
	protected $discourse_url;

	/**
	 * DiscourseLatestShortcode constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'setup_options' ) );
		add_shortcode( 'discourse_latest', array( $this, 'discourse_latest' ) );
	}

	/**
	 * Set the plugin options.
	 */
	public function setup_options() {
		$this->options       = DiscourseUtilities::get_options();
		$this->discourse_url = ! empty( $this->options['url'] ) ? $this->options['url'] : null;
	}

	/**
	 * Create the shortcode.
	 *
	 * @param array $atts The shortcode attributes.
	 *
	 * @return string
	 */
	public function discourse_latest( $atts ) {

		if ( ! $this->discourse_url ) {
			return '';
		}

		$attributes = shortcode_atts( array(
			'max_topics'     => 5,
			'cache_duration' => 10,
		), $atts );

		// Force WordPress to fetch new topics from Discourse.
		$force = ! empty( $this->options['dclt_clear_topics_cache'] ) ? $this->options['dclt_clear_topics_cache'] : null;

		$discourse_topics = $this->get_latest_topics( $attributes['cache_duration'], $force );

		return $this->format_topics( $discourse_topics, $attributes );
	}

	/**
	 * Get the latest topics from Discourse.
	 *
	 * @param int|string $cache_duration The cache duration for the topics.
	 * @param bool $force Whether to force retrieving new topics from Discourse.
	 *
	 * @return array|mixed|null|object
	 */
	protected function get_latest_topics( $cache_duration, $force ) {
		$latest_url = esc_url_raw( $this->discourse_url . '/latest.json' );

		$discourse_topics = get_transient( 'dclt_latest_topics' );
		if ( empty( $discourse_topics ) || $force ) {

			$remote = wp_remote_get( $latest_url );
			if ( ! DiscourseUtilities::validate( $remote ) ) {

				return null;
			}

			$discourse_topics = json_decode( wp_remote_retrieve_body( $remote ), true );
			set_transient( 'dclt_latest_topics', $discourse_topics, $cache_duration * MINUTE_IN_SECONDS );
		}

		return $discourse_topics;
	}

	/**
	 * Format the Discourse topics.
	 *
	 * @param array $discourse_topics The array of topics.
	 * @param array $args The shortcode attributes.
	 *
	 * @return string
	 */
	protected function format_topics( $discourse_topics, $args ) {

		if ( empty( $discourse_topics['topic_list'] ) ) {
			return '';
		}

		$topics = $discourse_topics['topic_list']['topics'];

		// If the first topic is pinned, don't display it.
		if ( ! empty( $topics[0]['pinned'] ) && 1 === intval( $topics[0]['pinned'] ) ) {
			$topics = array_slice( $topics, 1, $args['max_topics'] );
			write_log( 'topics', $topics);
		} else {
			$topics = array_slice( $topics, 0, $args['max_topics'] );
		}

		$users             = $discourse_topics['users'];
		$poster_avatar_url = '';
		$poster_username   = '';

		$output = '<ul class="discourse-topiclist">';

		foreach ( $topics as $topic ) {
			$topic_url            = esc_url_raw( $this->options['url'] . "/t/{$topic['slug']}/{$topic['id']}" );
			$created_at           = date_create( get_date_from_gmt( $topic['created_at'] ) );
			$created_at_formatted = date_format( $created_at, 'F j, Y' );
			$last_activity        = $topic['last_posted_at'];
			$category             = $this->find_discourse_category( $topic );
			$posters              = $topic['posters'];
			foreach ( $posters as $poster ) {
				if ( preg_match( '/Original Poster/', $poster['description'] ) ) {
					$original_poster_id = $poster['user_id'];
					foreach ( $users as $user ) {
						if ( $original_poster_id === $user['id'] ) {
							$poster_username   = $user['username'];
							$avatar_template   = str_replace( '{size}', 22, $user['avatar_template'] );
							$poster_avatar_url = esc_url_raw( $this->options['url'] . $avatar_template );
						}
					}
				}
			}

			$output .= '<li class="discourse-topic">';
			$output .= '<div class="discourse-topic-poster-meta">';
			$avatar_image = '<img class="discourse-latest-avatar" src="' . $poster_avatar_url . '">';
			$output .= apply_filters( 'wp_discourse_shorcodes_avatar', $avatar_image, $poster_avatar_url );
			$output .= '<span class="discourse-username">' . $poster_username . '</span>' . ' posted on ' . '<span class="discourse-created-at">' . $created_at_formatted . '</span><br>';
			$output .= 'in <span class="discourse-shortcode-category" >' . $this->discourse_category_badge( $category ) . '</span>';
			$output .= '</div>';
//			$output .= '<a href="' . $topic_url . '">';
			$output .= '<h3 class="discourse-topic-title"><a href="' . esc_url( $topic_url) . '">"' . $topic['title'] . '</a></h3>';
//			$output .= '</a>';
			$output .= '<div class="discourse-topic-activity-meta">';
			$output .= 'replies <span class="discourse-num-replies">' . ( $topic['posts_count'] - 1 ) . '</span> last activity <span class="discourse-last-activity">' . $this->calculate_last_activity( $last_activity ) . '</span>';
			$output .= '</div>';
			$output .= '</li>';
		}

		$output .= '</ul>';

		return $output;
	}

	protected function find_discourse_category( $topic ) {
		$categories  = DiscourseUtilities::get_discourse_categories();
		$category_id = $topic['category_id'];

		foreach ( $categories as $category ) {
			if ( $category_id === $category['id'] ) {
				return $category;
			}
		}

		return null;
	}

	protected function discourse_category_badge( $category ) {
		$category_name  = $category['name'];
		$category_color = '#' . $category['color'];
		$category_badge = '<span class="discourse-shortcode-category-badge" style="width: 8px; height: 8px; background-color: ' . $category_color . '; display: inline-block;"></span><span class="discourse-category-name"> ' . $category_name . '</span>';

		return $category_badge;
	}

	protected function calculate_last_activity( $last_activity ) {
		$now           = time();
		$last_activity = strtotime( $last_activity );
		$seconds       = $now - $last_activity;

		$minutes = intval( $seconds / 60 );
		if ( $minutes < 60 ) {
			return 1 === $minutes ? '1 minute ago' : $minutes . ' minutes ago';
		}

		$hours = intval( $minutes / 60 );
		if ( $hours < 24 ) {
			return 1 === $hours ? '1 hour ago' : $hours . ' hours ago';
		}

		$days = intval( $hours / 24 );
		if ( $days < 30 ) {
			return 1 === $days ? '1 day ago' : $days . ' days ago';
		}

		$months = intval( $days / 30 );
		if ( $months < 12 ) {
			return 1 === $months ? '1 month ago' : $months . ' months ago';
		}

		$years = intval( $months / 12 );

		return 1 === $years ? '1 year ago' : $years . ' years ago';
	}


}