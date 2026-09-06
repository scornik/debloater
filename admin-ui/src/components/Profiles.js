/**
 * Profiles: save what this site has, take it elsewhere, bring one back.
 *
 * The three buttons do three different amounts. Save writes a name against the
 * selection this site has committed. Export hands over the file the server
 * encoded, byte for byte, so it matches what `wp debloater profile export`
 * writes. Import reads a file, says what it could not use, and opens the
 * ordinary preview with the rest ticked.
 *
 * Import applies nothing. It cannot: there is no apply here, only a preview
 * that issues its own confirmation token like every other preview
 * (docs/DECISIONS.md D-0063, BUILD-SPEC §13 rule 8). A file somebody was
 * emailed does not get a shortcut past the screen that shows what it touches.
 */

import { useState, useRef, useEffect } from '@wordpress/element';
import { Button, Notice, Spinner, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { get, post } from '../api/client';
import { useResource } from '../api/useResource';

/**
 * The query argument another plugin uses to ask for a profile's preview.
 *
 * Pro's own screen has a Profiles panel too, and its Apply button links here
 * rather than applying anything itself. What arrives is an id: this screen looks
 * it up in its own listing and opens the ordinary preview with that profile's
 * changes ticked. A link nobody sent — an id that names nothing, or one edited
 * by hand — finds no row and opens nothing, so the worst a crafted URL can do is
 * show somebody a preview of changes this site already offered them.
 *
 * The preview is where anything is decided, and it issues its own confirmation
 * token exactly as it does when the button was on this page (BUILD-SPEC §13
 * rule 8). A URL cannot apply anything, and this is the code that would have to
 * change for that sentence to stop being true.
 *
 * Documented in `docs/HOOKS.md` as a contract, because something outside this
 * repository depends on the name.
 */
const PRESELECT = 'debloater_profile';

/**
 * Offer a string to the browser as a file.
 *
 * The bytes are the server's, unchanged. Re-encoding the document here would
 * produce a file that differs from the command line's in whitespace and key
 * order, and the two would drift apart the first time either side changed.
 *
 * @param {string} name     File name.
 * @param {string} contents Exact bytes to save.
 */
const download = ( name, contents ) => {
	const blob = new Blob( [ contents ], { type: 'application/json' } );
	const url = URL.createObjectURL( blob );
	const link = document.createElement( 'a' );

	link.href = url;
	link.download = name;
	document.body.appendChild( link );
	link.click();
	document.body.removeChild( link );
	URL.revokeObjectURL( url );
};

/**
 * A file name for a profile.
 *
 * @param {string} name The profile's name.
 * @return {string} File name.
 */
const fileName = ( name ) =>
	`${
		name
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-|-$/g, '' ) || 'profile'
	}.json`;

/**
 * The profiles panel.
 *
 * @param {Object}   props           Component props.
 * @param {Function} props.onPreview Called with a list of tweak ids to preview.
 * @param {number}   props.epoch     Bumped by the app when the site changes.
 * @return {Object} The panel.
 */
export default function Profiles( { onPreview, epoch } ) {
	const profiles = useResource( () => get( '/profiles' ), [ epoch ] );

	const [ name, setName ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ failure, setFailure ] = useState( null );
	const [ imported, setImported ] = useState( null );
	const [ saved, setSaved ] = useState( null );
	const fileInput = useRef( null );

	const save = async () => {
		setBusy( true );
		setFailure( null );
		setSaved( null );

		try {
			const result = await post( '/profiles/save', { name } );

			setSaved( result.name );
			setName( '' );
			profiles.reload();
		} catch ( error ) {
			setFailure( error );
		} finally {
			setBusy( false );
		}
	};

	const importFile = async ( file ) => {
		if ( ! file ) {
			return;
		}

		setBusy( true );
		setFailure( null );
		setImported( null );

		try {
			const contents = await file.text();
			const result = await post( '/profiles/import', {
				document: contents,
			} );

			setImported( result );
			profiles.reload();
		} catch ( error ) {
			setFailure( error );
		} finally {
			setBusy( false );

			// So the same file can be chosen twice running.
			if ( fileInput.current ) {
				fileInput.current.value = '';
			}
		}
	};

	const rows = profiles.data?.profiles || [];

	// Once, when the listing first arrives. `onPreview` opens a dialog, and a
	// dialog that reopened every time this component re-rendered would be one
	// nobody could dismiss.
	const asked = useRef( false );

	useEffect( () => {
		if ( asked.current || profiles.status !== 'ready' ) {
			return;
		}

		asked.current = true;

		const wanted = new URLSearchParams( window.location.search ).get(
			PRESELECT
		);

		if ( ! wanted ) {
			return;
		}

		const row = rows.find( ( entry ) => entry.id === wanted );

		if ( row && row.selection.length > 0 ) {
			onPreview( row.selection );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ profiles.status ] );

	return (
		<section className="debloater-panel debloater-profiles">
			<h2 className="debloater-panel__title">
				{ __( 'Profiles', 'debloater' ) }
			</h2>

			<p className="debloater-panel__lede">
				{ __(
					'A profile is a named set of changes. Save what this site has, take it to another site, or bring one back — importing shows you a preview and applies nothing on its own.',
					'debloater'
				) }
			</p>

			{ failure && (
				<Notice
					status="error"
					isDismissible={ false }
					className="debloater-profiles__failure"
				>
					{ failure.message }
				</Notice>
			) }

			{ saved && (
				<Notice
					status="success"
					isDismissible
					onRemove={ () => setSaved( null ) }
				>
					{ sprintf(
						/* translators: %s: profile name. */
						__( 'Saved "%s".', 'debloater' ),
						saved
					) }
				</Notice>
			) }

			{ imported && (
				<Notice
					status={
						imported.skipped.length > 0 ? 'warning' : 'success'
					}
					isDismissible
					onRemove={ () => setImported( null ) }
				>
					<p>
						{ sprintf(
							/* translators: %s: profile name. */
							__(
								'Read "%s". Nothing has been applied — the preview below is where that is decided.',
								'debloater'
							),
							imported.name
						) }
					</p>

					{ imported.skipped.length > 0 && (
						<p>
							{ sprintf(
								/* translators: %s: comma-separated change ids. */
								__(
									'This site does not have these changes, so they were left out: %s',
									'debloater'
								),
								imported.skipped.join( ', ' )
							) }
						</p>
					) }

					{ ! imported.registry_match && (
						<p>
							{ __(
								'It was written against a different version of the change list, so a change may mean something slightly different here. The preview shows what it would do.',
								'debloater'
							) }
						</p>
					) }

					{ imported.selection.length > 0 && (
						<Button
							variant="primary"
							onClick={ () => onPreview( imported.selection ) }
						>
							{ __( 'Preview these changes', 'debloater' ) }
						</Button>
					) }
				</Notice>
			) }

			<div className="debloater-profiles__save">
				<TextControl
					label={ __( 'Save this setup as a profile', 'debloater' ) }
					help={ __(
						'Records the changes this site has applied, under a name you choose.',
						'debloater'
					) }
					value={ name }
					onChange={ setName }
					disabled={ busy }
					maxLength={ 80 }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<Button
					variant="secondary"
					onClick={ save }
					disabled={ busy || name.trim() === '' }
				>
					{ __( 'Save profile', 'debloater' ) }
				</Button>
			</div>

			<div className="debloater-profiles__import">
				<Button
					variant="secondary"
					onClick={ () => fileInput.current?.click() }
					disabled={ busy }
				>
					{ __( 'Import a profile…', 'debloater' ) }
				</Button>
				<input
					ref={ fileInput }
					type="file"
					accept="application/json,.json"
					className="debloater-profiles__file"
					onChange={ ( event ) =>
						importFile( event.target.files?.[ 0 ] )
					}
				/>
			</div>

			{ profiles.status === 'loading' && (
				<p className="debloater-loading">
					<Spinner /> { __( 'Reading profiles…', 'debloater' ) }
				</p>
			) }

			{ rows.length > 0 && (
				<table className="debloater-profiles__list widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Profile', 'debloater' ) }</th>
							<th>{ __( 'Changes', 'debloater' ) }</th>
							<th>{ __( 'Actions', 'debloater' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row ) => (
							<tr key={ row.id }>
								<td>
									{ row.name }
									{ row.builtin && (
										<span className="debloater-profiles__builtin">
											{ __( 'built in', 'debloater' ) }
										</span>
									) }
								</td>
								<td>{ row.changes }</td>
								<td>
									<Button
										variant="link"
										onClick={ () =>
											download(
												fileName( row.name ),
												row.document
											)
										}
									>
										{ __( 'Export', 'debloater' ) }
									</Button>
									{ row.selection.length > 0 && (
										<Button
											variant="link"
											onClick={ () =>
												onPreview( row.selection )
											}
										>
											{ __( 'Preview', 'debloater' ) }
										</Button>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</section>
	);
}
