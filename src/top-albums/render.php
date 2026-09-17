<?php
/**
 * Server-side rendering for the Top Albums block.
 *
 * @package ScrobbledBlocks
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Wrap in IIFE to avoid global variable pollution.
$scrobbled_blocks_output = ( static function ( $scrobbled_blocks_attributes ) {
	$scrobbled_settings = Scrobbled_Blocks_Settings::get_instance();

	// Check if plugin is configured.
	if ( ! $scrobbled_settings->is_configured() ) {
		return '';
	}

	$scrobbled_number_of_items = $scrobbled_blocks_attributes['numberOfItems'] ?? 5;
	$scrobbled_period          = $scrobbled_blocks_attributes['period'] ?? '7day';
	$scrobbled_layout          = $scrobbled_blocks_attributes['layout'] ?? 'featured';
	$scrobbled_grid_columns    = $scrobbled_blocks_attributes['gridColumns'] ?? 3;
	$scrobbled_show_artwork    = $scrobbled_blocks_attributes['showArtwork'] ?? true;
	$scrobbled_show_playcount  = $scrobbled_blocks_attributes['showPlaycount'] ?? true;
	$scrobbled_link_to_lastfm  = $scrobbled_blocks_attributes['linkToLastFm'] ?? true;

	// The featured mosaic is made of artwork; the toggle does not apply to it.
	if ( 'featured' === $scrobbled_layout ) {
		$scrobbled_show_artwork = true;
	}

	$scrobbled_api    = Scrobbled_Blocks_API::get_instance();
	$scrobbled_albums = $scrobbled_api->get_top_albums( $scrobbled_number_of_items, $scrobbled_period, 15 );

	// Graceful degradation - render nothing if API fails or no albums.
	if ( is_wp_error( $scrobbled_albums ) || empty( $scrobbled_albums ) ) {
		return '';
	}

	$scrobbled_class_name = 'wp-block-scrobble-blocks-top-albums is-layout-' . esc_attr( $scrobbled_layout );
	$scrobbled_style      = '';

	if ( 'grid' === $scrobbled_layout ) {
		$scrobbled_style = '--grid-columns: ' . absint( $scrobbled_grid_columns ) . ';';
	}

	$scrobbled_wrapper_attributes = get_block_wrapper_attributes(
		array(
			'class' => $scrobbled_class_name,
			'style' => $scrobbled_style,
		)
	);

	/**
	 * Render the inside of one album item.
	 *
	 * @param array $scrobbled_album_data   Album data.
	 * @param bool  $scrobbled_show_art     Whether to show artwork.
	 * @param bool  $scrobbled_show_plays   Whether to show the play count.
	 * @param bool  $scrobbled_link_lastfm  Whether to link to Last.fm.
	 * @return string HTML for the album item.
	 */
	$scrobbled_render_album_inner = static function ( $scrobbled_album_data, $scrobbled_show_art, $scrobbled_show_plays, $scrobbled_link_lastfm ) {
		$scrobbled_item_name        = $scrobbled_album_data['name'];
		$scrobbled_item_artist      = $scrobbled_album_data['artist'];
		$scrobbled_item_artwork_url = $scrobbled_album_data['artwork'];
		$scrobbled_item_url         = $scrobbled_album_data['url'];
		$scrobbled_item_playcount   = $scrobbled_album_data['playcount'];
		$scrobbled_item_alt         = sprintf( '%s by %s', $scrobbled_item_name, $scrobbled_item_artist );

		ob_start();
		?>
	<?php if ( $scrobbled_show_art ) : ?>
		<div class="scrobble-artwork">
			<?php if ( $scrobbled_link_lastfm && $scrobbled_item_url ) : ?>
				<a href="<?php echo esc_url( $scrobbled_item_url ); ?>" target="_blank" rel="noopener noreferrer">
					<img src="<?php echo esc_url( $scrobbled_item_artwork_url ); ?>" alt="<?php echo esc_attr( $scrobbled_item_alt ); ?>" loading="lazy" />
				</a>
			<?php else : ?>
				<img src="<?php echo esc_url( $scrobbled_item_artwork_url ); ?>" alt="<?php echo esc_attr( $scrobbled_item_alt ); ?>" loading="lazy" />
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<div class="scrobble-info">
		<span class="scrobble-album-name">
			<?php if ( $scrobbled_link_lastfm && $scrobbled_item_url ) : ?>
				<a href="<?php echo esc_url( $scrobbled_item_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $scrobbled_item_name ); ?></a>
			<?php else : ?>
				<?php echo esc_html( $scrobbled_item_name ); ?>
			<?php endif; ?>
		</span>
		<span class="scrobble-artist"><?php echo esc_html( $scrobbled_item_artist ); ?></span>
		<?php if ( $scrobbled_show_plays ) : ?>
			<span class="scrobble-playcount"><?php echo esc_html( scrobbled_blocks_format_playcount( $scrobbled_item_playcount ) ); ?></span>
		<?php endif; ?>
	</div>
		<?php
		return ob_get_clean();
	};

	ob_start();

	if ( 'list' === $scrobbled_layout ) :
		?>
<ol <?php echo wp_kses_post( $scrobbled_wrapper_attributes ); ?>>
		<?php foreach ( $scrobbled_albums as $scrobbled_album_item ) : ?>
		<li class="scrobble-album">
			<span class="scrobble-rank"><?php echo esc_html( $scrobbled_album_item['rank'] ); ?></span>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in the render function.
			echo $scrobbled_render_album_inner( $scrobbled_album_item, $scrobbled_show_artwork, $scrobbled_show_playcount, $scrobbled_link_to_lastfm );
			?>
		</li>
	<?php endforeach; ?>
</ol>
	<?php else : ?>
<div <?php echo wp_kses_post( $scrobbled_wrapper_attributes ); ?>>
		<?php foreach ( $scrobbled_albums as $scrobbled_album_item ) : ?>
		<div class="scrobble-album">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in the render function.
			echo $scrobbled_render_album_inner( $scrobbled_album_item, $scrobbled_show_artwork, $scrobbled_show_playcount, $scrobbled_link_to_lastfm );
			?>
		</div>
	<?php endforeach; ?>
</div>
		<?php
	endif;

	return ob_get_clean();
} )( $attributes );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped within the closure.
echo $scrobbled_blocks_output;
