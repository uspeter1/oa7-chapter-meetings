/**
 * OA7 Chapter Meetings — admin settings page: add / remove chapter rows,
 * and build forgiving title patterns from a plain event title.
 */
( function () {
	'use strict';

	// Characters that must be escaped to match literally. '/' is deliberately absent:
	// the PHP matcher adds the delimiters and escapes slashes itself, so escaping one
	// here would produce a double escape and break the pattern.
	var META = '.\\+*?[^]$(){}=!<>|:#';

	var APOS  = "['\u2019]";        // straight or curly apostrophe.
	var DASH  = '[-\u2013\u2014]'; // hyphen, en dash, or em dash.
	var FLAGS = '(?i)';              // inline case-insensitive modifier (PCRE).

	/**
	 * Turn a plain event title into a pattern that tolerates the ways the same
	 * meeting gets typed on a calendar: any capitalization, any run of whitespace,
	 * and either form of apostrophe or dash. Left unanchored so a title with an
	 * added suffix ("... - Zoom") still matches.
	 */
	function buildForgiving( text ) {
		var trimmed = String( text ).trim().replace( /\s+/g, ' ' );
		var out     = '';
		var ch;
		var i;

		if ( ! trimmed ) {
			return '';
		}

		for ( i = 0; i < trimmed.length; i++ ) {
			ch = trimmed.charAt( i );

			if ( ' ' === ch ) {
				out += '\\s+';
			} else if ( "'" === ch || '\u2019' === ch ) {
				out += APOS;
			} else if ( '-' === ch || '\u2013' === ch || '\u2014' === ch ) {
				out += DASH;
			} else if ( -1 !== META.indexOf( ch ) ) {
				out += '\\' + ch;
			} else {
				out += ch;
			}
		}

		return FLAGS + out;
	}

	/**
	 * Inverse of buildForgiving, so clicking the button on an already-built pattern
	 * rebuilds it rather than escaping the escapes. Only recognizes what this script
	 * emits; a hand-written pattern is returned close enough to edit.
	 */
	function plainFromForgiving( pattern ) {
		var rest = String( pattern ).replace( /^\(\?i\)/, '' );
		var out  = '';
		var i    = 0;

		while ( i < rest.length ) {
			if ( '\\s+' === rest.substr( i, 3 ) ) {
				out += ' ';
				i   += 3;
			} else if ( APOS === rest.substr( i, APOS.length ) ) {
				out += "'";
				i   += APOS.length;
			} else if ( DASH === rest.substr( i, DASH.length ) ) {
				out += '-';
				i   += DASH.length;
			} else if ( '\\' === rest.charAt( i ) && i + 1 < rest.length ) {
				out += rest.charAt( i + 1 );
				i   += 2;
			} else {
				out += rest.charAt( i );
				i   += 1;
			}
		}

		return out;
	}

	function isBuilt( value ) {
		return 0 === String( value ).indexOf( FLAGS );
	}

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		var body     = document.getElementById( 'oa7cm-chapters-body' );
		var addBtn   = document.getElementById( 'oa7cm-add-row' );
		var template = document.getElementById( 'oa7cm-row-template' );

		if ( ! body || ! addBtn || ! template ) {
			return;
		}

		// Add a new blank row from the template.
		addBtn.addEventListener( 'click', function () {
			var wrap = document.createElement( 'tbody' );
			wrap.innerHTML = template.innerHTML.trim();
			var row = wrap.querySelector( 'tr' );
			if ( row ) {
				body.appendChild( row );
			}
		} );

		// "Make forgiving": rewrite the regex box from the plain title it holds.
		// An empty box falls back to the chapter name, which is usually the event title.
		body.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '.oa7cm-forgiving' ) : null;
			if ( ! btn ) {
				return;
			}
			e.preventDefault();

			var row = btn.closest( '.oa7cm-chapter-row' );
			if ( ! row ) {
				return;
			}

			var field = row.querySelector( '.oa7cm-regex' );
			var name  = row.querySelector( 'input[type="text"]' );
			if ( ! field ) {
				return;
			}

			var source = field.value.trim();
			if ( isBuilt( source ) ) {
				source = plainFromForgiving( source );
			}
			if ( ! source && name && name !== field ) {
				source = name.value.trim();
			}

			if ( ! source ) {
				field.focus();
				return;
			}

			field.value = buildForgiving( source );
		} );

		// Remove a row (event delegation). Keep at least one row present.
		body.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '.oa7cm-remove-row' ) : null;
			if ( ! btn ) {
				return;
			}
			e.preventDefault();
			var rows = body.querySelectorAll( '.oa7cm-chapter-row' );
			var row  = btn.closest( '.oa7cm-chapter-row' );
			if ( ! row ) {
				return;
			}
			if ( rows.length > 1 ) {
				row.parentNode.removeChild( row );
			} else {
				// Last row: just clear its inputs instead of removing.
				row.querySelectorAll( 'input' ).forEach( function ( input ) {
					input.value = '';
				} );
			}
		} );
	} );
} )();
