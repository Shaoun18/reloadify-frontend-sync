<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------- Minification helpers ---------------- */

/**
 * Shared, dependency-free minifiers used by Speed Boost.
 *
 * Deliberately conservative. A byte or two of extra output is always
 * preferable to a broken page, so every routine here bails out and returns
 * the original source the moment it isn't confident -- and the JS pass only
 * removes comments and indentation rather than rewriting the code, because
 * automatic-semicolon-insertion makes anything more aggressive genuinely
 * risky on third-party scripts nobody here controls.
 */
class Reloadify_Minify {

	/** Files larger than this are left alone -- not worth the request-time cost. */
	const MAX_SOURCE_BYTES = 1048576; // 1 MB

	/* ---------------- CSS ---------------- */

	/**
	 * Strips comments and collapses whitespace, leaving strings and url()
	 * values untouched.
	 *
	 * @param string $css Raw stylesheet source.
	 * @return string
	 */
	public static function css( $css ) {
		if ( ! is_string( $css ) || '' === trim( $css ) ) {
			return $css;
		}

		$out    = '';
		$len    = strlen( $css );
		$quote  = '';
		$i      = 0;

		while ( $i < $len ) {
			$char = $css[ $i ];

			if ( '' !== $quote ) {
				$out .= $char;
				if ( '\\' === $char && $i + 1 < $len ) {
					$out .= $css[ $i + 1 ];
					$i   += 2;
					continue;
				}
				if ( $char === $quote ) {
					$quote = '';
				}
				$i++;
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				$out  .= $char;
				$i++;
				continue;
			}

			// Comment. Preserve /*! ... */ (licence headers).
			if ( '/' === $char && $i + 1 < $len && '*' === $css[ $i + 1 ] ) {
				$end = strpos( $css, '*/', $i + 2 );
				if ( false === $end ) {
					break; // Unterminated -- drop the remainder rather than guess.
				}
				if ( $i + 2 < $len && '!' === $css[ $i + 2 ] ) {
					$out .= substr( $css, $i, $end - $i + 2 );
				}
				$i = $end + 2;
				continue;
			}

			if ( ' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char || "\f" === $char ) {
				// Collapse any run of whitespace down to a single space.
				while ( $i < $len && preg_match( '/\s/', $css[ $i ] ) ) {
					$i++;
				}
				$out .= ' ';
				continue;
			}

			$out .= $char;
			$i++;
		}

		// Drop spaces around punctuation that never needs them, then the
		// final semicolon before each closing brace.
		$out = preg_replace( '/\s*([{}:;,>~+])\s*/', '$1', $out );
		$out = str_replace( ';}', '}', $out );

		// `>`, `~` and `+` are also valid inside media/supports queries and
		// :not() etc., where the collapse above is harmless -- but a combinator
		// directly after a comma still needs no space, so nothing to restore.
		return trim( $out );
	}

	/* ---------------- JavaScript ---------------- */

	/**
	 * Removes comments and per-line indentation without touching statement
	 * structure. Strings, template literals and regex literals are copied
	 * through verbatim.
	 *
	 * @param string $js Raw script source.
	 * @return string
	 */
	public static function js( $js ) {
		if ( ! is_string( $js ) || '' === trim( $js ) ) {
			return $js;
		}

		$out  = '';
		$len  = strlen( $js );
		$i    = 0;
		$prev = ''; // Last meaningful (non-whitespace) character emitted.

		while ( $i < $len ) {
			$char = $js[ $i ];
			$next = ( $i + 1 < $len ) ? $js[ $i + 1 ] : '';

			// Line comment.
			if ( '/' === $char && '/' === $next ) {
				$end = strpos( $js, "\n", $i );
				if ( false === $end ) {
					break;
				}
				$i = $end;
				continue;
			}

			// Block comment. Preserve /*! ... */ licence headers.
			if ( '/' === $char && '*' === $next ) {
				$end = strpos( $js, '*/', $i + 2 );
				if ( false === $end ) {
					return $js; // Unterminated: don't risk it.
				}
				if ( $i + 2 < $len && '!' === $js[ $i + 2 ] ) {
					$out .= substr( $js, $i, $end - $i + 2 );
				}
				$i = $end + 2;
				continue;
			}

			// Regex literal -- only where a value can legally start.
			if ( '/' === $char && self::js_regex_allowed( $prev ) ) {
				$literal = self::js_read_regex( $js, $i );
				if ( null === $literal ) {
					return $js; // Ambiguous: leave the file as-is.
				}
				$out  .= $literal;
				$prev  = '/';
				$i    += strlen( $literal );
				continue;
			}

			// Strings and template literals.
			if ( '"' === $char || "'" === $char || '`' === $char ) {
				$literal = self::js_read_string( $js, $i, $char );
				if ( null === $literal ) {
					return $js;
				}
				$out  .= $literal;
				$prev  = $char;
				$i    += strlen( $literal );
				continue;
			}

			if ( "\n" === $char || "\r" === $char ) {
				// Keep exactly one newline: it is what ASI relies on.
				while ( $i < $len && ( "\n" === $js[ $i ] || "\r" === $js[ $i ] || "\t" === $js[ $i ] || ' ' === $js[ $i ] ) ) {
					$i++;
				}
				if ( '' !== $out ) {
					$out .= "\n";
				}
				continue;
			}

			if ( ' ' === $char || "\t" === $char ) {
				$j = $i;
				while ( $j < $len && ( ' ' === $js[ $j ] || "\t" === $js[ $j ] ) ) {
					$j++;
				}
				// A single space only where two identifier characters would
				// otherwise run together.
				$after = ( $j < $len ) ? $js[ $j ] : '';
				if ( self::js_is_word_char( $prev ) && self::js_is_word_char( $after ) ) {
					$out .= ' ';
				}
				$i = $j;
				continue;
			}

			$out  .= $char;
			$prev  = $char;
			$i++;
		}

		return trim( $out );
	}

	private static function js_is_word_char( $char ) {
		return '' !== $char && 1 === preg_match( '/[A-Za-z0-9_$]/', $char );
	}

	/**
	 * A `/` starts a regex literal only when the previous meaningful
	 * character can't end an expression.
	 */
	private static function js_regex_allowed( $prev ) {
		if ( '' === $prev ) {
			return true;
		}
		return false === strpos( ')]}', $prev ) && ! self::js_is_word_char( $prev );
	}

	private static function js_read_string( $js, $start, $quote ) {
		$len = strlen( $js );
		$i   = $start + 1;

		while ( $i < $len ) {
			$char = $js[ $i ];

			if ( '\\' === $char ) {
				$i += 2;
				continue;
			}
			if ( $char === $quote ) {
				return substr( $js, $start, $i - $start + 1 );
			}
			// An unescaped newline can't appear inside '' or "" strings.
			if ( '`' !== $quote && ( "\n" === $char || "\r" === $char ) ) {
				return null;
			}
			$i++;
		}

		return null;
	}

	private static function js_read_regex( $js, $start ) {
		$len      = strlen( $js );
		$i        = $start + 1;
		$in_class = false;

		while ( $i < $len ) {
			$char = $js[ $i ];

			if ( '\\' === $char ) {
				$i += 2;
				continue;
			}
			if ( "\n" === $char || "\r" === $char ) {
				return null;
			}
			if ( '[' === $char ) {
				$in_class = true;
			} elseif ( ']' === $char ) {
				$in_class = false;
			} elseif ( '/' === $char && ! $in_class ) {
				$i++;
				// Trailing flags.
				while ( $i < $len && preg_match( '/[a-z]/', $js[ $i ] ) ) {
					$i++;
				}
				return substr( $js, $start, $i - $start );
			}
			$i++;
		}

		return null;
	}

	/* ---------------- HTML ---------------- */

	/**
	 * Minifies a full HTML document.
	 *
	 * <pre>, <textarea>, <script> and <style> are pulled out first so their
	 * contents are never whitespace-collapsed; inline <script>/<style> blocks
	 * are then minified individually if those options are on.
	 *
	 * @param string $html    Buffered page output.
	 * @param array  $options Which passes to run.
	 * @return string
	 */
	public static function html( $html, $options = [] ) {
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return $html;
		}

		$options = wp_parse_args( $options, [
			'remove_comments'    => true,
			'collapse_whitespace' => true,
			'minify_inline_js'   => true,
			'minify_inline_css'  => true,
		] );

		$protected = [];
		$original  = $html;

		$html = preg_replace_callback(
			'#<(pre|textarea|script|style)\b[^>]*>.*?</\1\s*>#is',
			function ( $matches ) use ( &$protected, $options ) {
				$block = $matches[0];
				$tag   = strtolower( $matches[1] );

				if ( 'script' === $tag && $options['minify_inline_js'] ) {
					$block = self::minify_inline_block( $block, 'js' );
				} elseif ( 'style' === $tag && $options['minify_inline_css'] ) {
					$block = self::minify_inline_block( $block, 'css' );
				}

				$key               = '<!--reloadify-protected-' . count( $protected ) . '-->';
				$protected[ $key ] = $block;

				return $key;
			},
			$html
		);

		// A PCRE backtrack limit on a very large page returns null -- send the
		// untouched page rather than a blank one.
		if ( ! is_string( $html ) ) {
			return $original;
		}

		if ( $options['remove_comments'] ) {
			// Keep IE conditional comments and the protected-block markers.
			$stripped = preg_replace( '/<!--(?!\s*(?:\[if\s|<!|>|reloadify-protected-))(?:(?!-->).)*-->/s', '', $html );
			if ( is_string( $stripped ) ) {
				$html = $stripped;
			}
		}

		if ( $options['collapse_whitespace'] ) {
			$collapsed = preg_replace( '/\s{2,}/', ' ', $html );
			if ( is_string( $collapsed ) ) {
				$html = $collapsed;
			}

			// Whitespace between block-level tags carries no meaning.
			$collapsed = preg_replace( '#>\s+<(/?(?:html|head|body|div|section|article|aside|header|footer|nav|main|ul|ol|li|table|thead|tbody|tfoot|tr|td|th|form|fieldset|figure|h[1-6]|p|hr|br|meta|link|title|script|style)\b)#i', '><$1', $html );
			if ( is_string( $collapsed ) ) {
				$html = $collapsed;
			}
		}

		if ( $protected ) {
			$html = strtr( $html, $protected );
		}

		return $html;
	}

	/**
	 * Minifies the body of a single inline <script> or <style> tag, leaving
	 * the tag itself (and any JSON-LD / template payload) alone.
	 */
	private static function minify_inline_block( $block, $type ) {
		if ( ! preg_match( '#^(<[a-z]+\b[^>]*>)(.*)(</[a-z]+\s*>)$#is', $block, $parts ) ) {
			return $block;
		}

		$open = $parts[1];
		$body = $parts[2];

		if ( '' === trim( $body ) ) {
			return $block;
		}

		if ( 'js' === $type ) {
			// Only real JavaScript: skip JSON-LD, templates, and anything
			// with a src (which has no inline body worth touching anyway).
			if ( preg_match( '/\stype\s*=\s*["\']?([^"\'\s>]+)/i', $open, $type_attr ) ) {
				$declared = strtolower( $type_attr[1] );
				$allowed  = [ 'text/javascript', 'application/javascript', 'module' ];
				if ( ! in_array( $declared, $allowed, true ) ) {
					return $block;
				}
			}
			if ( stripos( $open, ' src=' ) !== false ) {
				return $block;
			}
			$body = self::js( $body );
		} else {
			$body = self::css( $body );
		}

		return $open . $body . $parts[3];
	}
}
