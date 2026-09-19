<?php
/**
 * The [oa7_chapter_meetings] shortcode: matching, filtering, sorting, rendering.
 *
 * @package OA7_Chapter_Meetings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OA7CM_Shortcode {

	const TAG = 'oa7_chapter_meetings';

	const FALLBACK_MESSAGE = 'No upcoming meetings found - please check calendar on oa7.org homepage';

	/** @var OA7CM_Calendar */
	private $calendar;

	/**
	 * @param OA7CM_Calendar $calendar Calendar service.
	 */
	public function __construct( OA7CM_Calendar $calendar ) {
		$this->calendar = $calendar;
		add_shortcode( self::TAG, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_style' ) );
	}

	/**
	 * Register (not enqueue) the front-end stylesheet; enqueued on demand at render.
	 */
	public function register_style() {
		wp_register_style(
			'oa7cm',
			OA7CM_URL . 'assets/css/oa7cm.css',
			array(),
			OA7CM_VERSION
		);
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML.
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'chapter' => '',
				'count'   => 3,
				'color'   => '',
			),
			$atts,
			self::TAG
		);

		wp_enqueue_style( 'oa7cm' );

		$chapter_name = trim( (string) $atts['chapter'] );
		$count        = max( 1, (int) $atts['count'] );

		$settings = get_option( OA7CM_OPT_SETTINGS, array() );
		$chapters = isset( $settings['chapters'] ) && is_array( $settings['chapters'] ) ? $settings['chapters'] : array();
		$timezone = isset( $settings['timezone'] ) ? $settings['timezone'] : '';

		// Accent color: per-shortcode `color` attr wins, else the global setting, else
		// the CSS default. All values pass through the safe color sanitizer.
		$accent = self::sanitize_color( $atts['color'] );
		if ( '' === $accent && isset( $settings['accent_color'] ) ) {
			$accent = self::sanitize_color( $settings['accent_color'] );
		}

		// Resolve the configured chapter by exact name.
		$regex = null;
		foreach ( $chapters as $chapter ) {
			if ( isset( $chapter['name'] ) && $chapter['name'] === $chapter_name && '' !== $chapter_name ) {
				$regex = isset( $chapter['regex'] ) ? $chapter['regex'] : '';
				break;
			}
		}

		if ( null === $regex ) {
			// Unknown / unconfigured chapter — public-facing fallback (never leak config details).
			return $this->render_fallback();
		}

		$upcoming = $this->get_upcoming( $regex, $timezone );

		if ( empty( $upcoming ) ) {
			return $this->render_fallback();
		}

		$upcoming = array_slice( $upcoming, 0, $count );

		return $this->render_list( $upcoming, $count, $accent );
	}

	/**
	 * Filter cached events to this chapter's upcoming meetings, sorted ascending.
	 *
	 * @param string $regex    Chapter title regex.
	 * @param string $timezone Configured IANA timezone.
	 * @return array List of {start_ts, start_is_date_only, location} sorted by start.
	 */
	private function get_upcoming( $regex, $timezone ) {
		$events = $this->calendar->get_events();
		$tz     = self::resolve_timezone( $timezone );

		try {
			$now = new DateTimeImmutable( 'now', $tz );
		} catch ( Exception $e ) {
			$now = new DateTimeImmutable( 'now' );
		}

		$upcoming = array();

		foreach ( $events as $event ) {
			if ( ! OA7CM_Calendar::title_matches( $event['title'], $regex ) ) {
				continue;
			}

			$start = $this->parse_start( $event, $tz );
			if ( null === $start ) {
				continue;
			}

			// Future/now comparison in the configured timezone. All-day events compare at
			// date granularity (start-of-day) so today's all-day meeting still counts.
			if ( $event['start_is_date_only'] ) {
				$is_future = $start->format( 'Y-m-d' ) >= $now->format( 'Y-m-d' );
			} else {
				$is_future = $start->getTimestamp() >= $now->getTimestamp();
			}

			if ( ! $is_future ) {
				continue;
			}

			$upcoming[] = array(
				'start'              => $start,
				'start_is_date_only' => $event['start_is_date_only'],
				'location'           => $event['location'],
			);
		}

		usort(
			$upcoming,
			static function ( $a, $b ) {
				return $a['start']->getTimestamp() <=> $b['start']->getTimestamp();
			}
		);

		return $upcoming;
	}

	/**
	 * Parse an event's start into a DateTimeImmutable in the target timezone.
	 *
	 * @param array        $event Normalized event.
	 * @param DateTimeZone $tz    Target timezone.
	 * @return DateTimeImmutable|null
	 */
	private function parse_start( $event, $tz ) {
		try {
			if ( $event['start_is_date_only'] ) {
				// Interpret the date in the configured timezone, at start of day.
				return new DateTimeImmutable( $event['start_raw'] . ' 00:00:00', $tz );
			}
			// RFC3339 carries its own offset; convert to configured timezone for display.
			$dt = new DateTimeImmutable( $event['start_raw'] );
			return $dt->setTimezone( $tz );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Render the meeting list / single-block widget.
	 *
	 * @param array  $meetings Upcoming meetings.
	 * @param int    $count    Requested count (drives heading + single vs list).
	 * @param string $timezone Configured timezone.
	 * @return string HTML.
	 */
	private function render_list( $meetings, $count, $accent = '' ) {
		$single = ( 1 === $count );
		$style  = ( '' !== $accent ) ? ' style="--oa7cm-accent:' . esc_attr( $accent ) . ';"' : '';

		ob_start();
		?>
		<div class="oa7cm<?php echo $single ? ' oa7cm--single' : ''; ?>"<?php echo $style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<ul class="oa7cm-list">
				<?php foreach ( $meetings as $meeting ) : ?>
					<li class="oa7cm-row">
						<span class="oa7cm-date"><?php echo esc_html( $meeting['start']->format( 'l, F j, Y' ) ); ?></span>
						<?php if ( ! $meeting['start_is_date_only'] ) : ?>
							<span class="oa7cm-time"><?php echo esc_html( $meeting['start']->format( 'g:i A' ) ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== trim( (string) $meeting['location'] ) ) : ?>
							<span class="oa7cm-location"><?php echo esc_html( $meeting['location'] ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( ! $single && count( $meetings ) < $count ) : ?>
				<p class="oa7cm-note"><?php esc_html_e( 'All upcoming meetings shown', 'oa7-chapter-meetings' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the exact required fallback message.
	 *
	 * @return string HTML.
	 */
	private function render_fallback() {
		return '<div class="oa7cm"><p class="oa7cm-empty">' . esc_html( self::FALLBACK_MESSAGE ) . '</p></div>';
	}

	/**
	 * Sanitize a user-supplied CSS color so it is safe to drop into an inline style.
	 *
	 * Accepts hex (#rgb / #rgba / #rrggbb / #rrggbbaa), rgb()/rgba(), and plain named
	 * colors (letters only). Anything else returns '' so it falls back to the default.
	 *
	 * @param string $value Raw color value.
	 * @return string Safe color, or '' if unrecognized.
	 */
	public static function sanitize_color( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value ) ) {
			return $value;
		}
		if ( preg_match( '/^rgba?\(\s*[0-9.,%\s]+\)$/i', $value ) ) {
			return $value;
		}
		if ( preg_match( '/^[a-zA-Z]+$/', $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * Resolve an IANA timezone string to a DateTimeZone, falling back to the WP
	 * site timezone when the string is empty or invalid.
	 *
	 * @param string $timezone IANA string.
	 * @return DateTimeZone
	 */
	public static function resolve_timezone( $timezone ) {
		$timezone = (string) $timezone;
		if ( '' !== $timezone ) {
			try {
				return new DateTimeZone( $timezone );
			} catch ( Exception $e ) {
				// Fall through to site default.
			}
		}
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}
		return new DateTimeZone( 'UTC' );
	}
}
