<?php

/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       https://gitlab.com/mercature/sc-wp-integration
 * @since      1.0.0
 *
 * @package    SC_Wp_Plugin
 * @subpackage SC_Wp_Plugin/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    SC_Wp_Plugin
 * @subpackage SC_Wp_Plugin/includes
 * @author     Trent Mercer <trent@mercature.net>
 */
class SC_Wp_Plugin_i18n {


	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'sc-wp-plugin',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}



}
