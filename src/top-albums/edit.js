/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	ToggleControl,
	RangeControl,
	SelectControl,
	Placeholder,
	Spinner,
	Notice,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * The periods user.getTopAlbums accepts. Last.fm offers these fixed windows only.
 */
const PERIODS = [
	{ value: '7day', label: __( 'Last 7 days', 'scrobbled-blocks' ) },
	{ value: '1month', label: __( 'Last 30 days', 'scrobbled-blocks' ) },
	{ value: '3month', label: __( 'Last 90 days', 'scrobbled-blocks' ) },
	{ value: '6month', label: __( 'Last 180 days', 'scrobbled-blocks' ) },
	{ value: '12month', label: __( 'Last 365 days', 'scrobbled-blocks' ) },
	{ value: 'overall', label: __( 'All time', 'scrobbled-blocks' ) },
];

/**
 * Format a play count for display. Mirrors scrobbled_blocks_format_playcount().
 *
 * @param {number} count Number of plays.
 * @return {string} Formatted string.
 */
function formatPlaycount( count ) {
	return sprintf(
		/* translators: %s: number of plays */
		_n( '%s play', '%s plays', count, 'scrobbled-blocks' ),
		count.toLocaleString()
	);
}

/**
 * Edit component
 *
 * @param {Object}   props               Component props
 * @param {Object}   props.attributes    Block attributes
 * @param {Function} props.setAttributes Set attributes function
 * @return {JSX.Element} Edit component
 */
export default function Edit( { attributes, setAttributes } ) {
	const {
		numberOfItems,
		period,
		layout,
		gridColumns,
		showArtwork,
		showPlaycount,
		linkToLastFm,
	} = attributes;

	const [ albums, setAlbums ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	// The featured mosaic is made of artwork; without it there is nothing to show.
	const artworkVisible = layout === 'featured' || showArtwork;

	const blockProps = useBlockProps( {
		className: `wp-block-scrobble-blocks-top-albums is-layout-${ layout }`,
		style:
			layout === 'grid' ? { '--grid-columns': gridColumns } : undefined,
	} );

	useEffect( () => {
		setIsLoading( true );
		setError( null );

		apiFetch( {
			path: `/scrobble-blocks/v1/top-albums?limit=${ numberOfItems }&period=${ period }`,
		} )
			.then( ( response ) => {
				if ( ! response.success ) {
					setError( response.error );
					setAlbums( [] );
				} else if ( response.albums && response.albums.length > 0 ) {
					setAlbums( response.albums );
				} else {
					setError(
						__(
							'No albums found for this period.',
							'scrobbled-blocks'
						)
					);
					setAlbums( [] );
				}
			} )
			.catch( ( err ) => {
				setError(
					err.message ||
						__( 'Failed to fetch album data.', 'scrobbled-blocks' )
				);
				setAlbums( [] );
			} )
			.finally( () => {
				setIsLoading( false );
			} );
	}, [ numberOfItems, period ] );

	const renderAlbumInner = ( album ) => {
		const AlbumName = linkToLastFm ? 'a' : 'span';
		const albumProps = linkToLastFm
			? { href: album.url, target: '_blank', rel: 'noopener noreferrer' }
			: {};

		return (
			<>
				{ artworkVisible && (
					<div className="scrobble-artwork">
						{ linkToLastFm ? (
							<a
								href={ album.url }
								target="_blank"
								rel="noopener noreferrer"
							>
								<img
									src={ album.artwork }
									alt={ `${ album.name } by ${ album.artist }` }
								/>
							</a>
						) : (
							<img
								src={ album.artwork }
								alt={ `${ album.name } by ${ album.artist }` }
							/>
						) }
					</div>
				) }
				<div className="scrobble-info">
					<span className="scrobble-album-name">
						<AlbumName { ...albumProps }>{ album.name }</AlbumName>
					</span>
					<span className="scrobble-artist">{ album.artist }</span>
					{ showPlaycount && (
						<span className="scrobble-playcount">
							{ formatPlaycount( album.playcount ) }
						</span>
					) }
				</div>
			</>
		);
	};

	const renderAlbums = () => {
		if ( layout === 'list' ) {
			return (
				<ol>
					{ albums.map( ( album, index ) => (
						<li className="scrobble-album" key={ index }>
							<span className="scrobble-rank">
								{ album.rank }
							</span>
							{ renderAlbumInner( album ) }
						</li>
					) ) }
				</ol>
			);
		}

		return (
			<>
				{ albums.map( ( album, index ) => (
					<div className="scrobble-album" key={ index }>
						{ renderAlbumInner( album ) }
					</div>
				) ) }
			</>
		);
	};

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Display Settings', 'scrobbled-blocks' ) }
				>
					<SelectControl
						label={ __( 'Period', 'scrobbled-blocks' ) }
						value={ period }
						options={ PERIODS }
						onChange={ ( value ) =>
							setAttributes( { period: value } )
						}
					/>
					<RangeControl
						label={ __( 'Number of albums', 'scrobbled-blocks' ) }
						value={ numberOfItems }
						onChange={ ( value ) =>
							setAttributes( { numberOfItems: value } )
						}
						min={ 1 }
						max={ 20 }
					/>
					<ToggleGroupControl
						label={ __( 'Layout', 'scrobbled-blocks' ) }
						value={ layout }
						onChange={ ( value ) =>
							setAttributes( { layout: value } )
						}
						isBlock
					>
						<ToggleGroupControlOption
							value="featured"
							label={ __( 'Featured', 'scrobbled-blocks' ) }
						/>
						<ToggleGroupControlOption
							value="grid"
							label={ __( 'Grid', 'scrobbled-blocks' ) }
						/>
						<ToggleGroupControlOption
							value="list"
							label={ __( 'List', 'scrobbled-blocks' ) }
						/>
					</ToggleGroupControl>
					{ layout === 'grid' && (
						<RangeControl
							label={ __( 'Grid columns', 'scrobbled-blocks' ) }
							value={ gridColumns }
							onChange={ ( value ) =>
								setAttributes( { gridColumns: value } )
							}
							min={ 2 }
							max={ 6 }
						/>
					) }
					{ layout !== 'featured' && (
						<ToggleControl
							label={ __( 'Show artwork', 'scrobbled-blocks' ) }
							checked={ showArtwork }
							onChange={ ( value ) =>
								setAttributes( { showArtwork: value } )
							}
						/>
					) }
					<ToggleControl
						label={ __( 'Show play count', 'scrobbled-blocks' ) }
						checked={ showPlaycount }
						onChange={ ( value ) =>
							setAttributes( { showPlaycount: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Link to Last.fm', 'scrobbled-blocks' ) }
						checked={ linkToLastFm }
						onChange={ ( value ) =>
							setAttributes( { linkToLastFm: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				{ isLoading && (
					<Placeholder
						icon="album"
						label={ __( 'Top Albums', 'scrobbled-blocks' ) }
					>
						<Spinner />
					</Placeholder>
				) }
				{ ! isLoading && error && (
					<Placeholder
						icon="album"
						label={ __( 'Top Albums', 'scrobbled-blocks' ) }
					>
						<Notice status="warning" isDismissible={ false }>
							{ error }
						</Notice>
					</Placeholder>
				) }
				{ ! isLoading &&
					! error &&
					albums.length > 0 &&
					renderAlbums() }
			</div>
		</>
	);
}
