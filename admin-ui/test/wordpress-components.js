/**
 * Stand-ins for `@wordpress/components` in unit tests.
 *
 * In the browser these come from WordPress itself — the build treats the
 * package as an external, so the bundle never contains a copy. Loading the real
 * package in Jest would pull its TypeScript source and its ESM dependencies
 * into jsdom to prove things about WordPress's components rather than about
 * ours.
 *
 * The stubs render the same roles and the same accessible names, so a test that
 * looks for a button by its label still finds one.
 */

const { createElement } = require( '@wordpress/element' );

const Button = ( {
	children,
	onClick,
	disabled,
	className,
	variant,
	isBusy,
	...rest
} ) =>
	createElement(
		'button',
		{ type: 'button', onClick, disabled, className, ...rest },
		children
	);

const Notice = ( { children, status } ) =>
	createElement( 'div', { role: 'status', 'data-status': status }, children );

const Spinner = () => createElement( 'span', { 'aria-hidden': 'true' }, '…' );

const Modal = ( { title, children } ) =>
	createElement( 'div', { role: 'dialog', 'aria-label': title }, children );

const SelectControl = ( { label, value, options = [], onChange } ) =>
	createElement(
		'label',
		null,
		label,
		createElement(
			'select',
			{ value, onChange: ( event ) => onChange( event.target.value ) },
			options.map( ( option ) =>
				createElement(
					'option',
					{ key: option.value, value: option.value },
					option.label
				)
			)
		)
	);

module.exports = { Button, Notice, Spinner, Modal, SelectControl };
