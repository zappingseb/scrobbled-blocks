<?php
/**
 * Last.fm API wrapper class.
 *
 * @package ScrobbledBlocks
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API class for Last.fm integration.
 */
class Scrobbled_Blocks_API {

	/**
	 * Last.fm API base URL.
	 */
	const API_BASE_URL = 'https://ws.audioscrobbler.com/2.0/';

	/**
	 * Cache key prefix.
	 */
	const CACHE_KEY_PREFIX = 'scrobbled_blocks_recent_';

	/**
	 * Cache key prefix for top albums.
	 *
	 * Shares the scrobbled_blocks_ prefix so the activation, deactivation and
	 * uninstall transient sweeps clear these too.
	 */
	const TOP_ALBUMS_CACHE_KEY_PREFIX = 'scrobbled_blocks_topalbums_';

	/**
	 * Periods accepted by user.getTopAlbums. Last.fm offers these fixed windows only.
	 */
	const TOP_ALBUMS_PERIODS = array( 'overall', '7day', '1month', '3month', '6month', '12month' );

	/**
	 * Asset hash of the grey star Last.fm serves when it has no cover for a release.
	 *
	 * Last.fm returns this as a normal, non-empty image URL rather than an empty
	 * string, so it has to be recognised explicitly. The hash is stable; the host
	 * is not (both lastfm.freetls.fastly.net and lastfm-img.freetls.fastly.net
	 * serve it), so matching is done on the hash alone.
	 */
	const LASTFM_PLACEHOLDER_HASH = '2a96cbd8b46e442fc41c2b86b821562f';

	/**
	 * Single instance of the class.
	 *
	 * @var Scrobbled_Blocks_API|null
	 */
	private static $instance = null;

	/**
	 * Get single instance of the class.
	 *
	 * @return Scrobbled_Blocks_API
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Private constructor to prevent direct instantiation.
	}

	/**
	 * Test the API connection.
	 *
	 * @param string $username Last.fm username.
	 * @param string $api_key  Last.fm API key.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function test_connection( $username, $api_key ) {
		$response = $this->make_request(
			array(
				'method'  => 'user.getRecentTracks',
				'user'    => $username,
				'api_key' => $api_key,
				'format'  => 'json',
				'limit'   => 1,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['error'] ) ) {
			return new WP_Error(
				'lastfm_api_error',
				$this->get_error_message( $response['error'], $response['message'] ?? '' )
			);
		}

		return true;
	}

	/**
	 * Get recent tracks.
	 *
	 * @param int  $limit         Number of tracks to fetch.
	 * @param int  $cache_minutes Cache duration in minutes.
	 * @param bool $force_refresh Force refresh the cache.
	 * @return array|WP_Error Array of tracks or WP_Error on failure.
	 */
	public function get_recent_tracks( $limit = 5, $cache_minutes = 5, $force_refresh = false ) {
		$settings = Scrobbled_Blocks_Settings::get_instance();
		$username = $settings->get_username();
		$api_key  = $settings->get_api_key();

		if ( empty( $username ) || empty( $api_key ) ) {
			return new WP_Error(
				'not_configured',
				__( 'Last.fm API is not configured.', 'scrobbled-blocks' )
			);
		}

		$cache_key = self::CACHE_KEY_PREFIX . md5( $username . '_' . $limit );

		// Check cache first unless forced refresh.
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$response = $this->make_request(
			array(
				'method'  => 'user.getRecentTracks',
				'user'    => $username,
				'api_key' => $api_key,
				'format'  => 'json',
				'limit'   => $limit,
			)
		);

		if ( is_wp_error( $response ) ) {
			// Try to return stale cache on error.
			$stale = get_transient( $cache_key . '_stale' );
			if ( false !== $stale ) {
				return $stale;
			}
			return $response;
		}

		if ( isset( $response['error'] ) ) {
			return new WP_Error(
				'lastfm_api_error',
				$this->get_error_message( $response['error'], $response['message'] ?? '' )
			);
		}

		$tracks = $this->parse_tracks( $response, $limit );

		// Cache the response.
		$cache_duration = $cache_minutes * MINUTE_IN_SECONDS;
		set_transient( $cache_key, $tracks, $cache_duration );

		// Also set a longer stale cache for fallback.
		set_transient( $cache_key . '_stale', $tracks, DAY_IN_SECONDS );

		return $tracks;
	}

	/**
	 * Get top albums for a period.
	 *
	 * @param int    $limit         Number of albums to fetch.
	 * @param string $period        One of TOP_ALBUMS_PERIODS.
	 * @param int    $cache_minutes Cache duration in minutes.
	 * @param bool   $force_refresh Force refresh the cache.
	 * @return array|WP_Error Array of albums or WP_Error on failure.
	 */
	public function get_top_albums( $limit = 5, $period = '7day', $cache_minutes = 15, $force_refresh = false ) {
		$settings = Scrobbled_Blocks_Settings::get_instance();
		$username = $settings->get_username();
		$api_key  = $settings->get_api_key();

		if ( empty( $username ) || empty( $api_key ) ) {
			return new WP_Error(
				'not_configured',
				__( 'Last.fm API is not configured.', 'scrobbled-blocks' )
			);
		}

		if ( ! in_array( $period, self::TOP_ALBUMS_PERIODS, true ) ) {
			$period = '7day';
		}

		$cache_key = self::TOP_ALBUMS_CACHE_KEY_PREFIX . md5( $username . '_' . $period . '_' . $limit );

		// Check cache first unless forced refresh.
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$response = $this->make_request(
			array(
				'method'  => 'user.getTopAlbums',
				'user'    => $username,
				'api_key' => $api_key,
				'format'  => 'json',
				'period'  => $period,
				'limit'   => $limit,
			)
		);

		if ( is_wp_error( $response ) ) {
			// Try to return stale cache on error.
			$stale = get_transient( $cache_key . '_stale' );
			if ( false !== $stale ) {
				return $stale;
			}
			return $response;
		}

		if ( isset( $response['error'] ) ) {
			return new WP_Error(
				'lastfm_api_error',
				$this->get_error_message( $response['error'], $response['message'] ?? '' )
			);
		}

		$albums = $this->parse_albums( $response, $limit );

		// Cache the response.
		$cache_duration = $cache_minutes * MINUTE_IN_SECONDS;
		set_transient( $cache_key, $albums, $cache_duration );

		// Also set a longer stale cache for fallback.
		set_transient( $cache_key . '_stale', $albums, DAY_IN_SECONDS );

		return $albums;
	}

	/**
	 * Parse albums from a user.getTopAlbums response.
	 *
	 * The album objects differ from tracks in two ways that matter here: the
	 * artist is under artist.name rather than artist['#text'], and there is a
	 * playcount and a rank instead of a timestamp. The image array has the
	 * same shape, so artwork selection is shared with tracks.
	 *
	 * @param array $response API response.
	 * @param int   $limit    Maximum number of albums to return.
	 * @return array Parsed albums.
	 */
	private function parse_albums( $response, $limit = 0 ) {
		$albums = array();

		if ( ! isset( $response['topalbums']['album'] ) ) {
			return $albums;
		}

		$raw_albums = $response['topalbums']['album'];

		// Handle single album response (not an array of albums).
		if ( isset( $raw_albums['name'] ) ) {
			$raw_albums = array( $raw_albums );
		}

		$settings        = Scrobbled_Blocks_Settings::get_instance();
		$placeholder_url = $settings->get_placeholder_url();

		foreach ( $raw_albums as $raw_album ) {
			$albums[] = array(
				'name'      => $raw_album['name'] ?? '',
				'artist'    => $raw_album['artist']['name'] ?? '',
				'url'       => $raw_album['url'] ?? '',
				'playcount' => (int) ( $raw_album['playcount'] ?? 0 ),
				'rank'      => (int) ( $raw_album['@attr']['rank'] ?? count( $albums ) + 1 ),
				'artwork'   => $this->get_artwork_url( $raw_album, $placeholder_url ),
			);
		}

		if ( $limit > 0 && count( $albums ) > $limit ) {
			$albums = array_slice( $albums, 0, $limit );
		}

		return $albums;
	}

	/**
	 * Get now playing track (or most recent).
	 *
	 * @return array|WP_Error Track data or WP_Error on failure.
	 */
	public function get_now_playing() {
		$tracks = $this->get_recent_tracks( 1, 1 );

		if ( is_wp_error( $tracks ) ) {
			return $tracks;
		}

		if ( empty( $tracks ) ) {
			return new WP_Error(
				'no_tracks',
				__( 'No recent tracks found.', 'scrobbled-blocks' )
			);
		}

		return $tracks[0];
	}

	/**
	 * Make an API request.
	 *
	 * @param array $params Query parameters.
	 * @return array|WP_Error Response array or WP_Error on failure.
	 */
	private function make_request( $params ) {
		$url = add_query_arg( $params, self::API_BASE_URL );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			return new WP_Error(
				'api_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Last.fm API returned status code %d', 'scrobbled-blocks' ),
					$status_code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( null === $data ) {
			return new WP_Error(
				'json_error',
				__( 'Failed to parse Last.fm API response.', 'scrobbled-blocks' )
			);
		}

		return $data;
	}

	/**
	 * Parse tracks from API response.
	 *
	 * @param array $response API response.
	 * @param int   $limit    Maximum number of tracks to return.
	 * @return array Parsed tracks.
	 */
	private function parse_tracks( $response, $limit = 0 ) {
		$tracks = array();

		if ( ! isset( $response['recenttracks']['track'] ) ) {
			return $tracks;
		}

		$raw_tracks = $response['recenttracks']['track'];

		// Handle single track response (not an array of tracks).
		if ( isset( $raw_tracks['name'] ) ) {
			$raw_tracks = array( $raw_tracks );
		}

		$settings        = Scrobbled_Blocks_Settings::get_instance();
		$placeholder_url = $settings->get_placeholder_url();

		foreach ( $raw_tracks as $raw_track ) {
			$track = array(
				'name'       => $raw_track['name'] ?? '',
				'artist'     => $raw_track['artist']['#text'] ?? '',
				'album'      => $raw_track['album']['#text'] ?? '',
				'url'        => $raw_track['url'] ?? '',
				'timestamp'  => isset( $raw_track['date']['uts'] ) ? (int) $raw_track['date']['uts'] : null,
				'nowplaying' => isset( $raw_track['@attr']['nowplaying'] ) && 'true' === $raw_track['@attr']['nowplaying'],
				'artwork'    => $this->get_artwork_url( $raw_track, $placeholder_url ),
			);

			$tracks[] = $track;
		}

		// Last.fm returns "now playing" track in addition to the limit.
		// Slice to the requested limit to avoid returning extra tracks.
		if ( $limit > 0 && count( $tracks ) > $limit ) {
			$tracks = array_slice( $tracks, 0, $limit );
		}

		return $tracks;
	}

	/**
	 * Check whether an image URL is a placeholder rather than real cover art.
	 *
	 * Treats both an empty value and Last.fm's own grey-star asset as "no artwork",
	 * so the configured placeholder applies in either case.
	 *
	 * @param string $url Image URL from the API.
	 * @return bool True when the URL is not real cover art.
	 */
	private function is_placeholder_image( $url ) {
		if ( empty( $url ) ) {
			return true;
		}

		return false !== strpos( $url, self::LASTFM_PLACEHOLDER_HASH );
	}

	/**
	 * Get artwork URL from track data.
	 *
	 * @param array  $track           Track data from API.
	 * @param string $placeholder_url Placeholder URL to use if no artwork.
	 * @return string Artwork URL.
	 */
	private function get_artwork_url( $track, $placeholder_url ) {
		if ( ! isset( $track['image'] ) || ! is_array( $track['image'] ) ) {
			return $placeholder_url;
		}

		// Look for extralarge size (300x300).
		foreach ( $track['image'] as $image ) {
			if ( isset( $image['size'] ) && 'extralarge' === $image['size'] && ! $this->is_placeholder_image( $image['#text'] ?? '' ) ) {
				return $image['#text'];
			}
		}

		// Fallback to large size.
		foreach ( $track['image'] as $image ) {
			if ( isset( $image['size'] ) && 'large' === $image['size'] && ! $this->is_placeholder_image( $image['#text'] ?? '' ) ) {
				return $image['#text'];
			}
		}

		// Fallback to any available image.
		foreach ( $track['image'] as $image ) {
			if ( ! $this->is_placeholder_image( $image['#text'] ?? '' ) ) {
				return $image['#text'];
			}
		}

		return $placeholder_url;
	}

	/**
	 * Get human-readable error message.
	 *
	 * @param int    $error_code Error code from Last.fm.
	 * @param string $message    Error message from Last.fm.
	 * @return string Human-readable error message.
	 */
	private function get_error_message( $error_code, $message ) {
		$errors = array(
			2  => __( 'Invalid API service. This error should not happen.', 'scrobbled-blocks' ),
			3  => __( 'Invalid API method. This error should not happen.', 'scrobbled-blocks' ),
			4  => __( 'Authentication failed. Please check your API key.', 'scrobbled-blocks' ),
			5  => __( 'Invalid format. This error should not happen.', 'scrobbled-blocks' ),
			6  => __( 'Invalid parameters. Please check your username.', 'scrobbled-blocks' ),
			7  => __( 'Invalid resource. This error should not happen.', 'scrobbled-blocks' ),
			8  => __( 'Operation failed. Please try again later.', 'scrobbled-blocks' ),
			9  => __( 'Invalid session key. Please try again.', 'scrobbled-blocks' ),
			10 => __( 'Invalid API key. Please check your API key.', 'scrobbled-blocks' ),
			11 => __( 'Service temporarily offline. Please try again later.', 'scrobbled-blocks' ),
			13 => __( 'Invalid method signature. This error should not happen.', 'scrobbled-blocks' ),
			16 => __( 'Temporary error. Please try again later.', 'scrobbled-blocks' ),
			26 => __( 'API key suspended. Please check your Last.fm account.', 'scrobbled-blocks' ),
			29 => __( 'Rate limit exceeded. Please try again later.', 'scrobbled-blocks' ),
		);

		if ( isset( $errors[ $error_code ] ) ) {
			return $errors[ $error_code ];
		}

		if ( ! empty( $message ) ) {
			return $message;
		}

		return __( 'An unknown error occurred.', 'scrobbled-blocks' );
	}

	/**
	 * Clear all caches.
	 */
	public function clear_cache() {
		global $wpdb;

		// Delete all transients with our prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk transient cleanup requires direct query.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				'_transient_' . self::CACHE_KEY_PREFIX . '%',
				'_transient_timeout_' . self::CACHE_KEY_PREFIX . '%',
				'_transient_' . self::TOP_ALBUMS_CACHE_KEY_PREFIX . '%',
				'_transient_timeout_' . self::TOP_ALBUMS_CACHE_KEY_PREFIX . '%'
			)
		);
	}
}
