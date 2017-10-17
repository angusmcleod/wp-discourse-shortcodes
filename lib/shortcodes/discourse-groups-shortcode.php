<?php

namespace WPDiscourse\Shortcodes;

class DiscourseGroupsShortcode {

	/**
	 * @var DiscourseGroups An instance of DiscourseGroups.
	 */
	protected $discourse_groups;

	/**
	 * DiscourseGroupsShortcode constructor.
	 *
	 * @param DiscourseGroups $discourse_groups An instance of DiscourseGroups.
	 */
	public function __construct( $discourse_groups ) {
		$this->discourse_groups = $discourse_groups;

		add_shortcode( 'discourse_groups', array( $this, 'discourse_groups' ) );
	}

	/**
	 * Returns the output for the 'discourse_groups' shortcode.
	 *
	 * @return string
	 */
	public function discourse_groups( $args ) {

		$groups = $this->discourse_groups->get_formatted_groups( $args );

		if ( is_wp_error( $groups ) ) {

			return '';
		}

		return $groups;
	}
}
