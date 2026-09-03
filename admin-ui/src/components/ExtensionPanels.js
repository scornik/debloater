/**
 * Panels an extension asked to have shown.
 *
 * Rendered as text and nothing else. The bootstrap payload arrives already
 * stripped of markup by `Screen::sanitisePanels()`, and this renders it through
 * React's normal children, which escapes — so there are two independent reasons
 * an extension cannot put an element on this screen, and neither depends on the
 * other being right.
 *
 * There is deliberately no `dangerouslySetInnerHTML` anywhere in this file, and
 * a test asserts there is none anywhere in the bundle.
 */

const bootstrap = window.debloater || {};

const ExtensionPanels = () => {
	const panels = Array.isArray( bootstrap.panels ) ? bootstrap.panels : [];

	if ( panels.length === 0 ) {
		return null;
	}

	return (
		<>
			{ panels.map( ( panel, index ) => (
				<section
					className="debloater-panel debloater-panel--extension"
					key={ `${ panel.title }-${ index }` }
				>
					<h2 className="debloater-panel__title">{ panel.title }</h2>

					{ Array.isArray( panel.rows ) && panel.rows.length > 0 && (
						<dl className="debloater-extension__rows">
							{ panel.rows.map( ( row, rowIndex ) => (
								<div
									className="debloater-extension__row"
									key={ `${ row.label }-${ rowIndex }` }
								>
									<dt className="debloater-extension__label">
										{ row.label }
									</dt>
									<dd className="debloater-extension__value">
										{ row.value }
									</dd>
								</div>
							) ) }
						</dl>
					) }
				</section>
			) ) }
		</>
	);
};

export default ExtensionPanels;
