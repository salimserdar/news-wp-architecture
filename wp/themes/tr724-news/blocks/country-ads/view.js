( function () {
	var COOKIE = "tr724_ad_country";
	var pending = null;

	function normalizeCode( value ) {
		var code = String( value || "" ).trim().toUpperCase();
		return /^[A-Z]{2}$/.test( code ) ? code : "";
	}

	function readCookie() {
		var parts = document.cookie ? document.cookie.split( ";" ) : [];
		var i;
		var part;
		for ( i = 0; i < parts.length; i++ ) {
			part = parts[ i ].replace( /^\s+/, "" );
			if ( part.indexOf( COOKIE + "=" ) !== 0 ) {
				continue;
			}
			try {
				return normalizeCode( decodeURIComponent( part.slice( COOKIE.length + 1 ) ) );
			} catch ( error ) {
				return "";
			}
		}
		return "";
	}

	function writeCookie( code ) {
		var secure = window.location.protocol === "https:" ? "; Secure" : "";
		document.cookie = COOKIE + "=" + code + "; Path=/; Max-Age=86400; SameSite=Lax" + secure;
	}

	function configUrl() {
		var host = window.location.hostname;
		if ( host === "localhost" || host === "127.0.0.1" || host === "::1" || host === "[::1]" ) {
			return "https://www.turkishnote.com/api/ad-config";
		}
		return "/api/ad-config";
	}

	function countryCode() {
		var cached = readCookie();
		if ( cached ) {
			return Promise.resolve( cached );
		}
		if ( ! pending ) {
			pending = fetch( configUrl(), { credentials: "omit", cache: "no-store" } )
				.then( function ( response ) {
					if ( ! response.ok ) {
						return "";
					}
					return response.json();
				} )
				.then( function ( data ) {
					var code = normalizeCode( data && data.country );
					if ( code ) {
						writeCookie( code );
					}
					return code;
				} )
				.catch( function () {
					return "";
				} );
		}
		return pending;
	}

	function readConfig( root ) {
		var node = root.querySelector( ".country-ad__config" );
		if ( ! node ) {
			return null;
		}
		try {
			return JSON.parse( node.textContent || "" );
		} catch ( error ) {
			return null;
		}
	}

	function creativeFor( config, code ) {
		var countries = config && config.countries ? config.countries : {};
		if ( code && code !== "XX" && countries[ code ] && countries[ code ].src ) {
			return countries[ code ];
		}
		if ( config && config.fallback && config.fallback.src ) {
			return config.fallback;
		}
		return null;
	}

	function applyCreative( root, creative ) {
		var image = root.querySelector( ".country-ad__image" );
		var frame;
		var link;
		var box;
		if ( ! image || ! creative || ! creative.src ) {
			return;
		}
		if ( image.getAttribute( "src" ) !== creative.src ) {
			image.src = creative.src;
		}
		image.alt = creative.alt || "";
		frame = image.parentElement;
		if ( ! frame ) {
			return;
		}
		if ( creative.href ) {
			if ( frame.tagName !== "A" ) {
				link = document.createElement( "a" );
				link.className = "country-ad__link";
				link.target = "_blank";
				link.rel = "sponsored noopener noreferrer";
				frame.replaceWith( link );
				link.appendChild( image );
				frame = link;
			}
			if ( frame.getAttribute( "href" ) !== creative.href ) {
				frame.href = creative.href;
			}
			return;
		}
		if ( frame.tagName === "A" ) {
			box = document.createElement( "div" );
			box.className = "country-ad__link";
			frame.replaceWith( box );
			box.appendChild( image );
		}
	}

	function initCountryAd( root ) {
		var config;
		if ( ! root || root.dataset.tr724Ready === "1" ) {
			return;
		}
		config = readConfig( root );
		root.dataset.tr724Ready = "1";
		if ( ! config ) {
			return;
		}
		countryCode().then( function ( code ) {
			var creative = creativeFor( config, code );
			if ( creative ) {
				applyCreative( root, creative );
			}
		} );
	}

	function boot( scope ) {
		if ( ! scope || scope.nodeType !== 1 ) {
			return;
		}
		if ( scope.matches( "[data-country-ad]" ) ) {
			initCountryAd( scope );
		}
		scope.querySelectorAll( "[data-country-ad]" ).forEach( initCountryAd );
	}

	function inEditor() {
		var body = document.body;
		if ( ! body ) {
			return false;
		}
		return body.classList.contains( "block-editor-iframe__body" )
			|| body.classList.contains( "block-editor-page" )
			|| !! document.querySelector( ".editor-styles-wrapper" );
	}

	function start() {
		if ( inEditor() ) {
			return;
		}
		boot( document.body );
		if ( ! document.body ) {
			return;
		}

		var observer = new MutationObserver( function ( records ) {
			records.forEach( function ( record ) {
				record.addedNodes.forEach( function ( node ) {
					boot( node );
				} );
			} );
		} );

		observer.observe( document.body, { childList: true, subtree: true } );
	}

	if ( document.readyState === "loading" ) {
		document.addEventListener( "DOMContentLoaded", start );
	} else {
		start();
	}
} )();
