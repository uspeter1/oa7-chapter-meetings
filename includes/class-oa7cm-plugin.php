<?php
/**
 * Plugin bootstrap: wires the calendar, settings, and shortcode together and
 * owns the WP-Cron schedule.
 *
 * @package OA7_Chapter_Meetings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OA7CM_Plugin {

	/** @var OA7CM_Plugin */
	private static $instance = null;

	/** @var OA7CM_Calendar */
	public $calendar;

	/** @var OA7CM_Settings */
	public $settings;

	/** @var OA7CM_Shortcode */
	public $shortcode;

	/**
	 * Singleton accessor.
	 *
	 * @return OA7CM_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->calendar  = new OA7CM_Calendar();
		$this->settings  = new OA7CM_Settings( $this->calendar );
		$this->shortcode = new OA7CM_Shortcode( $this->calendar );

		// Register a custom 6-hour cron interval and the refresh hook.
		add_filter( 'cron_schedules', array( $this, 'register_schedule' ) );
		add_action( OA7CM_CRON_HOOK, array( $this->calendar, 'fetch_and_cache' ) );
	}

	/**
	 * Add a 6-hour interval to WP-Cron.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function register_schedule( $schedules ) {
		$schedules[ OA7CM_CRON_SCHEDULE ] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 hours (OA7 Chapter Meetings)', 'oa7-chapter-meetings' ),
		);
		return $schedules;
	}

	/**
	 * Activation: schedule the recurring refresh if not already scheduled.
	 */
	public static function activate() {
		if ( ! wp_next_scheduled( OA7CM_CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, OA7CM_CRON_SCHEDULE, OA7CM_CRON_HOOK );
		}
	}

	/**
	 * Deactivation: clear the recurring refresh.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( OA7CM_CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, OA7CM_CRON_HOOK );
		}
		wp_clear_scheduled_hook( OA7CM_CRON_HOOK );
	}
}
