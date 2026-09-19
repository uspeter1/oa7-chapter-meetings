<?php
/**
 * Plugin Name: OA7 Chapter Meetings
 * Plugin URI:  https://oa7.org/
 * Description: Displays each lodge chapter's upcoming meeting(s) on their page, pulled live from a shared Google Calendar. Fully reusable against any public Google Calendar — all calendar/chapter values are configured in settings.
 * Version:     1.1.1
 * Author:      OA7
 * License:     GPL-2.0-or-later
 * Text Domain: oa7-chapter-meetings
 *
 * @package OA7_Chapter_Meetings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'OA7CM_VERSION', '1.1.1' );
define( 'OA7CM_FILE', __FILE__ );
define( 'OA7CM_DIR', plugin_dir_path( __FILE__ ) );
define( 'OA7CM_URL', plugin_dir_url( __FILE__ ) );

// Option / transient keys (kept in one place so every class agrees).
define( 'OA7CM_OPT_SETTINGS', 'oa7cm_settings' );       // User config (array).
define( 'OA7CM_OPT_LAST_REFRESH', 'oa7cm_last_refresh' ); // Unix ts of last successful fetch.
define( 'OA7CM_OPT_LAST_ERROR', 'oa7cm_last_error' );     // {message, time} of last failure.
define( 'OA7CM_OPT_LAST_MATCHED', 'oa7cm_last_matched' ); // chapter_name => unix ts of newest match.
define( 'OA7CM_OPT_BACKUP', 'oa7cm_events_backup' );      // Long-lived mirror of last good payload.
define( 'OA7CM_TRANSIENT', 'oa7cm_events' );              // Cached normalized event list (6h TTL).
define( 'OA7CM_CRON_HOOK', 'oa7cm_refresh_event' );
define( 'OA7CM_CRON_SCHEDULE', 'oa7cm_six_hours' );

require_once OA7CM_DIR . 'includes/class-oa7cm-calendar.php';
require_once OA7CM_DIR . 'includes/class-oa7cm-settings.php';
require_once OA7CM_DIR . 'includes/class-oa7cm-shortcode.php';
require_once OA7CM_DIR . 'includes/class-oa7cm-plugin.php';

// Boot.
OA7CM_Plugin::instance();

// Activation: schedule the recurring refresh.
register_activation_hook( __FILE__, array( 'OA7CM_Plugin', 'activate' ) );

// Deactivation: clear the scheduled refresh.
register_deactivation_hook( __FILE__, array( 'OA7CM_Plugin', 'deactivate' ) );
