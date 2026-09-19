<?php
/**
 * Calendar fetching, caching, cron, and per-chapter match bookkeeping.
 *
 * @package OA7_Chapter_Meetings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OA7CM_Calendar {

	/** Forward window to fetch, in days. A full year so higher `count` values
	 * (e.g. several months of a monthly meeting) have enough events to show. */
	const WINDOW_DAYS = 365;

	/** Cache lifetime in seconds (6 hours). */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/** Feed considered "failing" once cache is older than this (24 hours). */
	const STALE_FEED_SECONDS = 24 * HOUR_IN_SECONDS;

	/**
	 * Get the cached, normalized event list.
	 *
	 * Serves the transient if present, otherwise the long-lived backup mirror
	 * (so data survives transient expiry during an API outage). Never fetches
	 * synchronously here — public page loads must stay fast.
	 *
	 * @return array List of normalized events.
	 */
	public function get_events() {
		$events = get_transient( OA7CM_TRANSIENT );
		if ( is_array( $events ) ) {
			return $events;
		}

		$backup = get_option( OA7CM_OPT_BACKUP );
		if ( is_array( $backup ) ) {
			return $backup;
		}

		return array();
	}

	/**
	 * Fetch from the Google Calendar API and (re)populate the cache.
	 *
	 * On success: stores transient + backup, updates last-refresh, clears last-error,
	 * runs match bookkeeping. On failure: records last-error, logs it, and leaves the
	 * existing cache/backup in place so stale-but-good data keeps serving.
	 *
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function fetch_and_cache() {
		$settings = get_option( OA7CM_OPT_SETTINGS, array() );

		$api_key     = isset( $settings['api_key'] ) ? trim( $settings['api_key'] ) : '';
		$calendar_id = isset( $settings['calendar_id'] ) ? trim( $settings['calendar_id'] ) : '';
		$timezone    = isset( $settings['timezone'] ) ? trim( $settings['timezone'] ) : '';

		$missing = array();
		if ( '' === $api_key ) {
			$missing[] = __( 'API key', 'oa7-chapter-meetings' );
		}
		if ( '' === $calendar_id ) {
			$missing[] = __( 'Calendar ID', 'oa7-chapter-meetings' );
		}
		if ( ! empty( $missing ) ) {
			return $this->record_error(
				sprintf(
					/* translators: %s: comma-separated list of missing setting field names. */
					__( 'These settings are empty: %s. Enter them and click Save Changes before refreshing.', 'oa7-chapter-meetings' ),
					implode( ', ', $missing )
				)
			);
		}

		$now  = time();
		$args = array(
			'key'          => $api_key,
			'timeMin'      => gmdate( 'c', $now ),
			'timeMax'      => gmdate( 'c', $now + ( self::WINDOW_DAYS * DAY_IN_SECONDS ) ),
			'singleEvents' => 'true',
			'orderBy'      => 'startTime',
			'maxResults'   => 250,
		);
		if ( '' !== $timezone ) {
			$args['timeZone'] = $timezone;
		}

		$url = sprintf(
			'https://www.googleapis.com/calendar/v3/calendars/%s/events',
			rawurlencode( $calendar_id )
		);
		$url = add_query_arg( array_map( 'rawurlencode', $args ), $url );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->record_error( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== (int) $code ) {
			$detail = '';
			$json   = json_decode( $body, true );
			if ( isset( $json['error']['message'] ) ) {
				$detail = ' — ' . $json['error']['message'];
			}
			/* translators: %d: HTTP status code. */
			return $this->record_error( sprintf( __( 'Google Calendar API returned HTTP %d', 'oa7-chapter-meetings' ), (int) $code ) . $detail );
		}

		$json = json_decode( $body, true );
		if ( ! is_array( $json ) || ! isset( $json['items'] ) || ! is_array( $json['items'] ) ) {
			return $this->record_error( __( 'Unexpected response from Google Calendar API.', 'oa7-chapter-meetings' ) );
		}

		$events = $this->normalize( $json['items'] );

		// Persist: short-lived transient for normal reads, long-lived backup for outages.
		set_transient( OA7CM_TRANSIENT, $events, self::CACHE_TTL );
		update_option( OA7CM_OPT_BACKUP, $events, false );
		update_option( OA7CM_OPT_LAST_REFRESH, time(), false );
		delete_option( OA7CM_OPT_LAST_ERROR );

		$this->update_match_bookkeeping( $events, $settings );

		return true;
	}

	/**
	 * Normalize raw Google event items into a lean internal shape.
	 *
	 * @param array $items Raw `items` array from the API.
	 * @return array
	 */
	private function normalize( array $items ) {
		$events = array();

		foreach ( $items as $item ) {
			if ( isset( $item['status'] ) && 'cancelled' === $item['status'] ) {
				continue;
			}

			$start = isset( $item['start'] ) ? $item['start'] : array();

			// All-day events use `date` (YYYY-MM-DD); timed events use `dateTime` (RFC3339).
			if ( ! empty( $start['dateTime'] ) ) {
				$start_raw     = $start['dateTime'];
				$is_date_only  = false;
			} elseif ( ! empty( $start['date'] ) ) {
				$start_raw     = $start['date'];
				$is_date_only  = true;
			} else {
				continue; // No usable start.
			}

			$events[] = array(
				'title'              => isset( $item['summary'] ) ? (string) $item['summary'] : '',
				'location'           => isset( $item['location'] ) ? (string) $item['location'] : '',
				'start_raw'          => $start_raw,
				'start_is_date_only' => $is_date_only,
			);
		}

		return $events;
	}

	/**
	 * Re-run match bookkeeping against the already-cached events.
	 *
	 * Used when the chapter list changes (settings save) so a newly added chapter
	 * is evaluated immediately instead of looking "never matched" until the next
	 * API refresh, which can be up to 6 hours away.
	 *
	 * @param array $settings Plugin settings (post-sanitize).
	 */
	public function refresh_match_bookkeeping( array $settings ) {
		$events = $this->get_events();
		if ( empty( $events ) ) {
			return; // Nothing cached to judge against; leave existing bookkeeping alone.
		}

		$this->update_match_bookkeeping( $events, $settings );
	}

	/**
	 * For each configured chapter, record the newest start date among events whose
	 * title matches its regex (anywhere in the window, past or future). Drives the
	 * admin-only stale-match health check; never affects public output.
	 *
	 * @param array $events   Normalized events.
	 * @param array $settings Plugin settings.
	 */
	private function update_match_bookkeeping( array $events, array $settings ) {
		$chapters = isset( $settings['chapters'] ) && is_array( $settings['chapters'] ) ? $settings['chapters'] : array();

		// Rebuilt from scratch rather than merged into the stored value, so a chapter
		// that was removed, renamed, or re-pointed at a pattern that no longer matches
		// does not keep reporting the timestamp it earned under its old pattern.
		$matched = array();

		foreach ( $chapters as $chapter ) {
			$name  = isset( $chapter['name'] ) ? $chapter['name'] : '';
			$regex = isset( $chapter['regex'] ) ? $chapter['regex'] : '';
			if ( '' === $name ) {
				continue;
			}

			$newest = 0;
			foreach ( $events as $event ) {
				if ( self::title_matches( $event['title'], $regex ) ) {
					$ts = strtotime( $event['start_raw'] );
					if ( $ts && $ts > $newest ) {
						$newest = $ts;
					}
				}
			}

			if ( $newest > 0 ) {
				$matched[ $name ] = $newest;
			}
		}

		update_option( OA7CM_OPT_LAST_MATCHED, $matched, false );
	}

	/**
	 * Safely test an event title against a (user-supplied) regex pattern.
	 *
	 * The pattern is treated as the inner expression and wrapped in delimiters here,
	 * so admins enter plain patterns like `^Portage Creek`. Invalid patterns never
	 * fatal — they simply match nothing.
	 *
	 * @param string $title Event title.
	 * @param string $regex User pattern (without delimiters).
	 * @return bool
	 */
	public static function title_matches( $title, $regex ) {
		$regex = (string) $regex;
		if ( '' === $regex ) {
			return false;
		}

		$pattern = '/' . str_replace( '/', '\/', $regex ) . '/u';

		// Suppress warnings from malformed patterns; a false return == no match.
		$result = @preg_match( $pattern, (string) $title ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return 1 === $result;
	}

	/**
	 * Record a fetch failure and log it. Leaves existing cache untouched.
	 *
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	private function record_error( $message ) {
		update_option(
			OA7CM_OPT_LAST_ERROR,
			array(
				'message' => $message,
				'time'    => time(),
			),
			false
		);

		error_log( 'OA7 Chapter Meetings: calendar fetch failed — ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		return new WP_Error( 'oa7cm_fetch_failed', $message );
	}

	/**
	 * Whether the feed as a whole is failing (cache older than the stale bound, or
	 * never successfully populated).
	 *
	 * @return bool
	 */
	public static function feed_is_stale() {
		$last = (int) get_option( OA7CM_OPT_LAST_REFRESH, 0 );
		if ( 0 === $last ) {
			return true;
		}
		return ( time() - $last ) > self::STALE_FEED_SECONDS;
	}
}
