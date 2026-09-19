<?php
/**
 * Admin settings page: fields, sanitization, the chapter repeater, the manual
 * refresh button, and the two health-check warning systems.
 *
 * @package OA7_Chapter_Meetings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OA7CM_Settings {

	const PAGE_SLUG    = 'oa7-chapter-meetings';
	const OPTION_GROUP = 'oa7cm_settings_group';
	const REFRESH_ACTION = 'oa7cm_manual_refresh';

	/** Default stale-match threshold in days. */
	const DEFAULT_STALE_DAYS = 45;

	/** @var OA7CM_Calendar */
	private $calendar;

	/**
	 * @param OA7CM_Calendar $calendar Calendar service.
	 */
	public function __construct( OA7CM_Calendar $calendar ) {
		$this->calendar = $calendar;

		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_' . self::REFRESH_ACTION, array( $this, 'handle_manual_refresh' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Register the Settings page under the Settings menu.
	 */
	public function add_menu() {
		add_options_page(
			__( 'OA7 Chapter Meetings', 'oa7-chapter-meetings' ),
			__( 'OA7 Chapter Meetings', 'oa7-chapter-meetings' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the single settings option with a sanitize callback.
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			OA7CM_OPT_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Load the admin row-repeater JS only on our settings page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'oa7cm-admin',
			OA7CM_URL . 'assets/js/oa7cm-admin.js',
			array(),
			OA7CM_VERSION,
			true
		);
	}

	/**
	 * Sanitize all submitted settings.
	 *
	 * @param array $input Raw submitted values.
	 * @return array Clean values.
	 */
	public function sanitize( $input ) {
		$clean = array();

		$clean['api_key']     = isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '';
		$clean['calendar_id'] = isset( $input['calendar_id'] ) ? sanitize_text_field( $input['calendar_id'] ) : '';

		// Timezone: stored as entered (sanitized); validity is surfaced as a warning, not blocked.
		$clean['timezone'] = isset( $input['timezone'] ) ? sanitize_text_field( $input['timezone'] ) : '';

		// Accent color: shared safe sanitizer (hex / rgb / named); empty = use CSS default.
		$clean['accent_color'] = isset( $input['accent_color'] ) ? OA7CM_Shortcode::sanitize_color( $input['accent_color'] ) : '';

		$threshold = isset( $input['stale_threshold_days'] ) ? (int) $input['stale_threshold_days'] : self::DEFAULT_STALE_DAYS;
		$clean['stale_threshold_days'] = $threshold > 0 ? $threshold : self::DEFAULT_STALE_DAYS;

		// Chapters: parallel name/regex arrays from the repeater. Drop fully empty rows.
		$clean['chapters'] = array();
		if ( isset( $input['chapters'] ) && is_array( $input['chapters'] ) ) {
			$names   = isset( $input['chapters']['name'] ) ? (array) $input['chapters']['name'] : array();
			$regexes = isset( $input['chapters']['regex'] ) ? (array) $input['chapters']['regex'] : array();
			$count   = max( count( $names ), count( $regexes ) );

			for ( $i = 0; $i < $count; $i++ ) {
				$name  = isset( $names[ $i ] ) ? sanitize_text_field( $names[ $i ] ) : '';
				// No wp_unslash() here: wp-admin/options.php already unslashes the whole
				// submitted value before update_option() triggers this callback. Unslashing
				// again would eat the backslash in any pattern token like \s or \d.
				$regex = isset( $regexes[ $i ] ) ? trim( (string) $regexes[ $i ] ) : '';
				// Regex may legitimately contain characters sanitize_text_field strips, so keep it raw-ish
				// but strip tags/control chars defensively.
				$regex = wp_strip_all_tags( $regex );

				if ( '' === $name && '' === $regex ) {
					continue;
				}
				$clean['chapters'][] = array(
					'name'  => $name,
					'regex' => $regex,
				);
			}
		}

		// The chapter list may have changed, so re-evaluate match bookkeeping against the
		// events already in cache. Without this a newly added chapter reads as "never
		// matched" — and gets flagged stale — until the next API refresh, up to 6h later.
		$this->calendar->refresh_match_bookkeeping( $clean );

		return $clean;
	}

	/**
	 * Handle the "Refresh Cache Now" button (admin-post endpoint).
	 */
	public function handle_manual_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'oa7-chapter-meetings' ) );
		}
		check_admin_referer( self::REFRESH_ACTION );

		$result = $this->calendar->fetch_and_cache();
		$status = is_wp_error( $result ) ? 'error' : 'success';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => self::PAGE_SLUG,
					'oa7cm_refreshed' => $status,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings  = wp_parse_args(
			get_option( OA7CM_OPT_SETTINGS, array() ),
			array(
				'api_key'              => '',
				'calendar_id'          => '',
				'timezone'             => '',
				'accent_color'         => '',
				'stale_threshold_days' => self::DEFAULT_STALE_DAYS,
				'chapters'             => array(),
			)
		);
		$chapters  = is_array( $settings['chapters'] ) ? $settings['chapters'] : array();
		$threshold = (int) $settings['stale_threshold_days'];
		$accent    = '' !== $settings['accent_color'] ? $settings['accent_color'] : '#2b6cb0';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OA7 Chapter Meetings', 'oa7-chapter-meetings' ); ?></h1>

			<?php $this->render_notices( $settings ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="oa7cm_api_key"><?php esc_html_e( 'Google Calendar API key', 'oa7-chapter-meetings' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="oa7cm_api_key" name="<?php echo esc_attr( OA7CM_OPT_SETTINGS ); ?>[api_key]" value="<?php echo esc_attr( $settings['api_key'] ); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'A Google Cloud API key with the Google Calendar API enabled.', 'oa7-chapter-meetings' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="oa7cm_calendar_id"><?php esc_html_e( 'Calendar ID', 'oa7-chapter-meetings' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="oa7cm_calendar_id" name="<?php echo esc_attr( OA7CM_OPT_SETTINGS ); ?>[calendar_id]" value="<?php echo esc_attr( $settings['calendar_id'] ); ?>" />
							<p class="description"><?php esc_html_e( 'The public calendar to read, e.g. xxxxx@group.calendar.google.com. No default — enter your own.', 'oa7-chapter-meetings' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="oa7cm_timezone"><?php esc_html_e( 'Timezone', 'oa7-chapter-meetings' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="oa7cm_timezone" name="<?php echo esc_attr( OA7CM_OPT_SETTINGS ); ?>[timezone]" value="<?php echo esc_attr( $settings['timezone'] ); ?>" />
							<p class="description"><?php esc_html_e( 'IANA timezone string used for all display and future/past comparisons, e.g. America/Chicago. No default.', 'oa7-chapter-meetings' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="oa7cm_accent_color"><?php esc_html_e( 'Accent color', 'oa7-chapter-meetings' ); ?></label></th>
						<td>
							<input type="color" id="oa7cm_accent_color" name="<?php echo esc_attr( OA7CM_OPT_SETTINGS ); ?>[accent_color]" value="<?php echo esc_attr( $accent ); ?>" />
							<p class="description"><?php esc_html_e( 'Color of the left edge stripe on each meeting row. Applies to all chapters; override per widget with the shortcode color attribute.', 'oa7-chapter-meetings' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="oa7cm_threshold"><?php esc_html_e( 'Stale-match warning threshold (days)', 'oa7-chapter-meetings' ); ?></label></th>
						<td>
							<input type="number" min="1" step="1" class="small-text" id="oa7cm_threshold" name="<?php echo esc_attr( OA7CM_OPT_SETTINGS ); ?>[stale_threshold_days]" value="<?php echo esc_attr( $threshold ); ?>" />
							<p class="description"><?php esc_html_e( 'Warn on the admin page if a chapter regex has matched no events for longer than this many days. Default 45.', 'oa7-chapter-meetings' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Chapters', 'oa7-chapter-meetings' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Each row maps a chapter display name to a regex matched against event titles only. Use the chapter name in the shortcode.', 'oa7-chapter-meetings' ); ?></p>
				<p class="description"><?php esc_html_e( 'Not comfortable with regex? Paste the event title exactly as it appears on the calendar, then click "Make forgiving" — it becomes a pattern that ignores capitalization, extra spaces, and straight vs. curly quotes and dashes. Click it again after editing the title to rebuild.', 'oa7-chapter-meetings' ); ?></p>

				<table class="widefat" id="oa7cm-chapters" style="max-width:900px;margin-top:10px;">
					<thead>
						<tr>
							<th style="width:28%;"><?php esc_html_e( 'Chapter name', 'oa7-chapter-meetings' ); ?></th>
							<th style="width:32%;"><?php esc_html_e( 'Title regex', 'oa7-chapter-meetings' ); ?></th>
							<th style="width:30%;"><?php esc_html_e( 'Last matched event', 'oa7-chapter-meetings' ); ?></th>
							<th style="width:10%;"></th>
						</tr>
					</thead>
					<tbody id="oa7cm-chapters-body">
						<?php
						if ( empty( $chapters ) ) {
							// One blank starter row.
							echo $this->render_chapter_row( '', '', $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} else {
							foreach ( $chapters as $chapter ) {
								echo $this->render_chapter_row( $chapter['name'], $chapter['regex'], $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							}
						}
						?>
					</tbody>
				</table>

				<p>
					<button type="button" class="button" id="oa7cm-add-row"><?php esc_html_e( '+ Add chapter', 'oa7-chapter-meetings' ); ?></button>
				</p>

				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Cache', 'oa7-chapter-meetings' ); ?></h2>
			<p>
				<strong><?php esc_html_e( 'Last refreshed at:', 'oa7-chapter-meetings' ); ?></strong>
				<?php echo esc_html( $this->format_timestamp( (int) get_option( OA7CM_OPT_LAST_REFRESH, 0 ), $settings['timezone'] ) ); ?>
			</p>
			<p class="description"><?php esc_html_e( 'Tip: this refreshes using your saved settings. If you just changed the API key, Calendar ID, or chapters, click Save Changes above first, then refresh.', 'oa7-chapter-meetings' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::REFRESH_ACTION ); ?>" />
				<?php wp_nonce_field( self::REFRESH_ACTION ); ?>
				<?php submit_button( __( 'Refresh Cache Now', 'oa7-chapter-meetings' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php
			// Hidden template row for the JS "add" button.
			?>
			<script type="text/template" id="oa7cm-row-template">
				<?php echo $this->render_chapter_row( '', '', $settings, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</script>
		</div>
		<?php
	}

	/**
	 * Render a single chapter repeater row.
	 *
	 * @param string $name       Chapter name.
	 * @param string $regex      Regex pattern.
	 * @param array  $settings   Settings (for threshold/timezone).
	 * @param bool   $is_template Whether this is the blank JS template row.
	 * @return string HTML.
	 */
	private function render_chapter_row( $name, $regex, $settings, $is_template = false ) {
		$base      = OA7CM_OPT_SETTINGS;
		$matched_html = '';

		if ( ! $is_template && '' !== $name ) {
			$matched_html = $this->render_last_matched( $name, $settings );
		} elseif ( ! $is_template ) {
			$matched_html = '<span class="description">' . esc_html__( '—', 'oa7-chapter-meetings' ) . '</span>';
		} else {
			$matched_html = '<span class="description">' . esc_html__( 'Save to track', 'oa7-chapter-meetings' ) . '</span>';
		}

		ob_start();
		?>
		<tr class="oa7cm-chapter-row">
			<td>
				<input type="text" class="regular-text" name="<?php echo esc_attr( $base ); ?>[chapters][name][]" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'Portage Creek', 'oa7-chapter-meetings' ); ?>" />
			</td>
			<td>
				<input type="text" class="regular-text code oa7cm-regex" name="<?php echo esc_attr( $base ); ?>[chapters][regex][]" value="<?php echo esc_attr( $regex ); ?>" placeholder="<?php esc_attr_e( '^Portage Creek', 'oa7-chapter-meetings' ); ?>" />
				<button type="button" class="button button-small oa7cm-forgiving" style="margin-top:4px;"><?php esc_html_e( 'Make forgiving', 'oa7-chapter-meetings' ); ?></button>
			</td>
			<td class="oa7cm-last-matched"><?php echo $matched_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
			<td>
				<button type="button" class="button-link oa7cm-remove-row" style="color:#b32d2e;"><?php esc_html_e( 'Remove', 'oa7-chapter-meetings' ); ?></button>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the "last matched event" cell content (date + optional stale warning).
	 *
	 * @param string $name     Chapter name.
	 * @param array  $settings Settings.
	 * @return string HTML.
	 */
	private function render_last_matched( $name, $settings ) {
		$matched = get_option( OA7CM_OPT_LAST_MATCHED, array() );
		$ts      = is_array( $matched ) && isset( $matched[ $name ] ) ? (int) $matched[ $name ] : 0;
		$threshold_days = (int) $settings['stale_threshold_days'];
		$threshold_days = $threshold_days > 0 ? $threshold_days : self::DEFAULT_STALE_DAYS;

		if ( 0 === $ts ) {
			$out = '<span class="description">' . esc_html__( 'Never matched', 'oa7-chapter-meetings' ) . '</span>';

			// Only a genuine non-match is worth flagging. With nothing in the cache to
			// compare against, every chapter would look stale — the feed-wide notice
			// already covers that case.
			if ( ! empty( $this->calendar->get_events() ) ) {
				$out .= $this->stale_badge( $name, $threshold_days );
			}

			return $out;
		}

		$out = '<span>' . esc_html( $this->format_timestamp( $ts, $settings['timezone'], false ) ) . '</span>';

		if ( ( time() - $ts ) > ( $threshold_days * DAY_IN_SECONDS ) ) {
			$out .= $this->stale_badge( $name, $threshold_days );
		}

		return $out;
	}

	/**
	 * Stale-match warning badge for a chapter.
	 *
	 * @param string $name           Chapter name.
	 * @param int    $threshold_days Threshold.
	 * @return string HTML.
	 */
	private function stale_badge( $name, $threshold_days ) {
		return '<div style="color:#b32d2e;margin-top:4px;">' . esc_html(
			sprintf(
				/* translators: 1: chapter name, 2: number of days. */
				__( '⚠ No matching events found for \'%1$s\' in over %2$d days — check that the regex pattern still matches actual event titles on the calendar', 'oa7-chapter-meetings' ),
				$name,
				$threshold_days
			)
		) . '</div>';
	}

	/**
	 * Render top-of-page notices: refresh result + feed-failure warning.
	 *
	 * @param array $settings Settings.
	 */
	private function render_notices( $settings ) {
		// Manual refresh result.
		if ( isset( $_GET['oa7cm_refreshed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$status = sanitize_key( wp_unslash( $_GET['oa7cm_refreshed'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'success' === $status ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Cache refreshed from Google Calendar.', 'oa7-chapter-meetings' ) . '</p></div>';
			} else {
				$err = get_option( OA7CM_OPT_LAST_ERROR );
				$msg = is_array( $err ) && ! empty( $err['message'] ) ? $err['message'] : __( 'Unknown error.', 'oa7-chapter-meetings' );
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Refresh failed:', 'oa7-chapter-meetings' ) . ' ' . esc_html( $msg ) . '</p></div>';
			}
		}

		// Feed-wide failure warning: cache itself is too old (refreshes failing).
		if ( OA7CM_Calendar::feed_is_stale() ) {
			$err     = get_option( OA7CM_OPT_LAST_ERROR );
			$err_msg = is_array( $err ) && ! empty( $err['message'] ) ? $err['message'] : '';
			$last    = (int) get_option( OA7CM_OPT_LAST_REFRESH, 0 );

			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Calendar data may be out of date.', 'oa7-chapter-meetings' ) . '</strong> ';
			if ( 0 === $last ) {
				echo esc_html__( 'The calendar has never been successfully fetched. Check the API key and Calendar ID, then use Refresh Cache Now.', 'oa7-chapter-meetings' );
			} else {
				echo esc_html__( 'The cache has not refreshed successfully in over 24 hours — the API connection should be checked.', 'oa7-chapter-meetings' );
			}
			if ( '' !== $err_msg ) {
				echo ' ' . esc_html__( 'Last error:', 'oa7-chapter-meetings' ) . ' ' . esc_html( $err_msg );
			}
			echo '</p></div>';
		}

		// Timezone validity hint.
		if ( '' !== $settings['timezone'] && ! in_array( $settings['timezone'], timezone_identifiers_list(), true ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html(
				sprintf(
					/* translators: %s: the entered timezone string. */
					__( 'The timezone "%s" is not a recognized IANA timezone. Dates may fall back to the server timezone.', 'oa7-chapter-meetings' ),
					$settings['timezone']
				)
			) . '</p></div>';
		}
	}

	/**
	 * Format a unix timestamp in the configured timezone for admin display.
	 *
	 * @param int    $ts        Unix timestamp (0 = never).
	 * @param string $timezone  IANA timezone.
	 * @param bool   $with_time Include the time component.
	 * @return string
	 */
	private function format_timestamp( $ts, $timezone, $with_time = true ) {
		if ( $ts <= 0 ) {
			return __( 'Never', 'oa7-chapter-meetings' );
		}
		$format = $with_time ? 'F j, Y g:i A' : 'F j, Y';
		$tz     = OA7CM_Shortcode::resolve_timezone( $timezone );

		try {
			$dt = new DateTimeImmutable( '@' . $ts );
			$dt = $dt->setTimezone( $tz );
			return $dt->format( $format ) . ( $with_time ? ' (' . $tz->getName() . ')' : '' );
		} catch ( Exception $e ) {
			return gmdate( $format, $ts );
		}
	}
}
