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

import { useState, useRef } from '@wordpress/element';
import { Button, Notice, Spinner, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { get, post } from '../api/client';
import { useResource } from '../api/useResource';

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
