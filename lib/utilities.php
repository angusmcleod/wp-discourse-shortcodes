<?php

namespace WPDiscourse\Shortcodes;

use WPDiscourse\Utilities\Utilities as DiscourseUtilities;

// Todo: don't do this! Just get the WP Discourse functions from the Utilities class.
trait Utilities {
	public function get_options() {

		return DiscourseUtilities::get_options();
	}

	public function validate( $response ) {

		return DiscourseUtilities::validate( $response );
	}

	public function get_discourse_categories() {

		return DiscourseUtilities::get_discourse_categories();
	}

	public function verify_discourse_webhook_request( $data ) {

		return DiscourseUtilities::verify_discourse_webhook_request( $data );
	}
}