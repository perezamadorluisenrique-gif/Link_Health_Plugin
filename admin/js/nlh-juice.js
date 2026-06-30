/**
 * Link Juice admin page interactions: recompute, per-page flow diagram,
 * link management (re-point), and the global site-overview graph.
 *
 * All SVG/DOM built with createElementNS + textContent (never innerHTML on
 * post-derived data) to stay XSS-safe.
 *
 * @package NativeLinkHealth
 */
( function () {
	'use strict';

	if ( typeof window.nlh_juice === 'undefined' ) {
		return;
	}

	var cfg   = window.nlh_juice;
	var i18n  = cfg.i18n || {};
	var SVGNS = 'http://www.w3.org/2000/svg';
	var MAX_NEIGHBORS = 10;

	/* --------------------------------------------------------------- *
	 * Module-level state
	 * --------------------------------------------------------------- */

	var overview = {
		loaded: false,
		view: null,
		base: null,
		root: null,
		nodeEls: null,
		gEdges: null,
		byId: null,
		rafId: null
	};

	var currentData  = null;
	var liveRegion   = null;
	var brokenPanel  = null;

	// Single drag/pan state — window listeners registered once at DOMContentLoaded.
	var drag = {
		active: false,
		type: null,     // 'node' | 'pan'
		nodeData: null,
		nodeEl: null,
		startX: 0, startY: 0,
		lastX: 0,  lastY: 0,
		moved: false
	};

	/* --------------------------------------------------------------- *
	 * Small helpers
	 * --------------------------------------------------------------- */

	function notify( message, isError ) {
		var notice = document.getElementById( 'nlh-admin-notice' );
		if ( ! notice ) {
			return;
		}
		notice.textContent = message;
		notice.className = 'notice nlh-notice ' + ( isError ? 'is-error' : 'is-success' );
		notice.hidden = false;
	}

	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return fetch( cfg.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) { return r.json(); } );
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) { node.className = className; }
		if ( typeof text !== 'undefined' && null !== text ) { node.textContent = text; }
		return node;
	}

	function svg( tag, attrs ) {
		var node = document.createElementNS( SVGNS, tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) { node.setAttribute( k, attrs[ k ] ); } );
		}
		return node;
	}

	function truncate( str, n ) {
		str = String( str || '' );
		return str.length > n ? str.slice( 0, n - 1 ) + '…' : str;
	}

	function fmt( template, a, b ) {
		return String( template || '' ).replace( '%1$d', a ).replace( '%2$d', b ).replace( '%d', a );
	}

	/* --------------------------------------------------------------- *
	 * Recompute
	 * --------------------------------------------------------------- */

	function recompute( button ) {
		var original = button.textContent;
		button.disabled = true;
		button.textContent = i18n.recomputing || 'Working...';
		notify( i18n.recomputing || 'Working...', false );

		post( 'nlh_recompute_juice', {} ).then( function ( res ) {
			if ( res && res.success ) {
				notify( ( res.data && res.data.message ) || i18n.recomputeDone, false );
				window.location.reload();
			} else {
				notify( ( res && res.data && res.data.message ) || i18n.error, true );
				button.disabled = false;
				button.textContent = original;
			}
		} ).catch( function () {
			notify( i18n.error, true );
			button.disabled = false;
			button.textContent = original;
		} );
	}

	/* --------------------------------------------------------------- *
	 * Per-page details: tabs (Flow | Manage links)
	 * --------------------------------------------------------------- */

	function loadDetails( container, postId, defaultTab ) {
		if ( '1' === container.getAttribute( 'data-loaded' ) ) {
			if ( defaultTab ) { switchTab( container, defaultTab ); }
			return;
		}
		container.textContent = i18n.loading || 'Loading...';

		post( 'nlh_juice_details', { post_id: postId } ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				container.textContent = ( res && res.data && res.data.message ) || i18n.error;
				return;
			}
			renderTabs( container, res.data, postId, defaultTab || 'flow' );
			container.setAttribute( 'data-loaded', '1' );
		} ).catch( function () {
			container.textContent = i18n.error;
		} );
	}

	function switchTab( container, name ) {
		container.querySelectorAll( '.nlh-tab' ).forEach( function ( t ) {
			t.classList.toggle( 'is-active', t.getAttribute( 'data-tab' ) === name );
		} );
		container.querySelectorAll( '.nlh-tab-pane' ).forEach( function ( p ) {
			p.hidden = p.getAttribute( 'data-pane' ) !== name;
		} );
	}

	function renderTabs( container, flow, postId, defaultTab ) {
		container.textContent = '';

		var tabs = el( 'div', 'nlh-tabs' );
		[ [ 'flow', i18n.tabFlow ], [ 'links', i18n.tabLinks ] ].forEach( function ( def ) {
			var b = el( 'button', 'nlh-tab', def[ 1 ] );
			b.type = 'button';
			b.setAttribute( 'data-tab', def[ 0 ] );
			b.addEventListener( 'click', function () { switchTab( container, def[ 0 ] ); } );
			tabs.appendChild( b );
		} );
		container.appendChild( tabs );

		var flowPane = el( 'div', 'nlh-tab-pane' );
		flowPane.setAttribute( 'data-pane', 'flow' );
		buildFlow( flowPane, flow, container );
		container.appendChild( flowPane );

		var linksPane = el( 'div', 'nlh-tab-pane' );
		linksPane.setAttribute( 'data-pane', 'links' );
		var grid = el( 'div', 'nlh-juice-details-grid' );
		grid.appendChild( buildInbound( flow.inbound || [] ) );
		grid.appendChild( buildOutbound( postId, flow.outbound || [] ) );
		linksPane.appendChild( grid );
		container.appendChild( linksPane );

		switchTab( container, defaultTab );
	}

	/* --------------------------------------------------------------- *
	 * Flow diagram (deterministic 3-column: in -> focal -> out)
	 * --------------------------------------------------------------- */

	function buildFlow( pane, flow, container ) {
		pane.textContent = '';

		var inbound  = ( flow.inbound || [] ).slice();
		var outbound = ( flow.outbound || [] ).slice();
		var inExtra  = Math.max( 0, inbound.length - MAX_NEIGHBORS );
		var outExtra = Math.max( 0, outbound.length - MAX_NEIGHBORS );
		inbound  = inbound.slice( 0, MAX_NEIGHBORS );
		outbound = outbound.slice( 0, MAX_NEIGHBORS );

		var W = 760;
		var rowH = 44;
		var topPad = 64;
		var nIn = inbound.length + ( inExtra ? 1 : 0 );
		var nOut = outbound.length + ( outExtra ? 1 : 0 );
		var rows = Math.max( nIn, nOut, 1 );
		var H = topPad + rows * rowH + 24;
		var leftX = 130, midX = W / 2, rightX = W - 130;

		var root = svg( 'svg', {
			viewBox: '0 0 ' + W + ' ' + H,
			class: 'nlh-flow-svg',
			preserveAspectRatio: 'xMidYMid meet'
		} );
		root.appendChild( arrowDefs() );

		// Column headers.
		root.appendChild( colHeader( leftX, 28, i18n.flowIn ) );
		root.appendChild( colHeader( midX, 28, ( flow.focal && flow.focal.title ) ? truncate( flow.focal.title, 30 ) : '', 'nlh-flow-focal-head' ) );
		root.appendChild( colHeader( rightX, 28, i18n.flowOut ) );

		var midY = topPad + ( rows * rowH ) / 2 - rowH / 2;

		// Edges first (under nodes).
		var startY = topPad;
		if ( ! inbound.length ) {
			root.appendChild( mutedLabel( leftX, midY, i18n.flowNoIn ) );
		}
		inbound.forEach( function ( item, i ) {
			var y = startY + i * rowH + rowH / 2;
			root.appendChild( flowEdge( leftX + 95, y, midX - 95, midY, 'in' ) );
		} );
		if ( inExtra ) {
			var yIn = startY + inbound.length * rowH + rowH / 2;
			root.appendChild( flowEdge( leftX + 95, yIn, midX - 95, midY, 'in' ) );
		}

		if ( ! outbound.length ) {
			root.appendChild( mutedLabel( rightX, midY, i18n.flowNoOut ) );
		}
		outbound.forEach( function ( item, i ) {
			var y = startY + i * rowH + rowH / 2;
			root.appendChild( flowEdge( midX + 95, midY, rightX - 95, y, 'out' ) );
		} );
		if ( outExtra ) {
			var yOut = startY + outbound.length * rowH + rowH / 2;
			root.appendChild( flowEdge( midX + 95, midY, rightX - 95, yOut, 'out' ) );
		}

		// Nodes.
		inbound.forEach( function ( item, i ) {
			var y = startY + i * rowH + rowH / 2;
			flowNode( root, leftX, y, item.title, 'nlh-fnode-in', item.post_id, container );
		} );
		if ( inExtra ) {
			extraNode( root, leftX, startY + inbound.length * rowH + rowH / 2, inExtra );
		}

		flowNode( root, midX, midY, ( flow.focal && flow.focal.title ) || '', 'nlh-fnode-focal', 0, null );

		outbound.forEach( function ( item, i ) {
			var y = startY + i * rowH + rowH / 2;
			var id = item.target_post_id || 0;
			var label = ( 'internal' === item.link_type ) ? ( item.title || item.target_url ) : item.target_url;
			flowNode( root, rightX, y, label, 'internal' === item.link_type ? 'nlh-fnode-out' : 'nlh-fnode-ext', id, container );
		} );
		if ( outExtra ) {
			extraNode( root, rightX, startY + outbound.length * rowH + rowH / 2, outExtra );
		}

		pane.appendChild( root );
	}

	function arrowDefs() {
		var defs = svg( 'defs' );
		[ [ 'in', 'nlh-arrow-in' ], [ 'out', 'nlh-arrow-out' ] ].forEach( function ( d ) {
			var m = svg( 'marker', {
				id: d[ 1 ], viewBox: '0 0 10 10', refX: '9', refY: '5',
				markerWidth: '7', markerHeight: '7', orient: 'auto-start-reverse'
			} );
			m.appendChild( svg( 'path', { d: 'M 0 0 L 10 5 L 0 10 z', class: 'nlh-arrow-' + d[ 0 ] } ) );
			defs.appendChild( m );
		} );
		return defs;
	}

	function colHeader( x, y, text, cls ) {
		var t = svg( 'text', { x: x, y: y, 'text-anchor': 'middle', class: 'nlh-flow-head ' + ( cls || '' ) } );
		t.textContent = text;
		return t;
	}

	function mutedLabel( x, y, text ) {
		var t = svg( 'text', { x: x, y: y, 'text-anchor': 'middle', class: 'nlh-flow-muted' } );
		t.textContent = text;
		return t;
	}

	function flowEdge( ax, ay, bx, by, dir ) {
		var mx = ( ax + bx ) / 2;
		var d = 'M ' + ax + ' ' + ay + ' C ' + mx + ' ' + ay + ' ' + mx + ' ' + by + ' ' + bx + ' ' + by;
		return svg( 'path', { d: d, class: 'nlh-fedge nlh-fedge-' + dir, 'marker-end': 'url(#nlh-arrow-' + dir + ')' } );
	}

	function flowNode( root, x, y, label, cls, postId, container ) {
		var w = 'nlh-fnode-focal' === cls ? 210 : 190;
		var h = 34;
		var g = svg( 'g', { class: 'nlh-fnode-group' + ( ( postId && container ) ? ' is-clickable' : '' ) } );

		var rect = svg( 'rect', { x: x - w / 2, y: y - h / 2, width: w, height: h, rx: 8, class: 'nlh-fnode ' + cls } );
		var title = svg( 'title' );
		title.textContent = label || '';
		rect.appendChild( title );
		g.appendChild( rect );

		var t = svg( 'text', { x: x, y: y + 4, 'text-anchor': 'middle', class: 'nlh-fnode-label' } );
		t.textContent = truncate( label, 'nlh-fnode-focal' === cls ? 26 : 22 );
		g.appendChild( t );

		if ( postId && container ) {
			g.addEventListener( 'click', function () {
				var details = container;
				details.setAttribute( 'data-loaded', '0' );
				loadDetails( details, postId, 'flow' );
			} );
		}

		root.appendChild( g );
	}

	function extraNode( root, x, y, count ) {
		var t = svg( 'text', { x: x, y: y + 4, 'text-anchor': 'middle', class: 'nlh-flow-muted' } );
		t.textContent = fmt( i18n.andMore, count );
		root.appendChild( t );
	}

	/* --------------------------------------------------------------- *
	 * Manage-links lists (inbound list + outbound re-point forms)
	 * --------------------------------------------------------------- */

	function buildInbound( inbound ) {
		var wrap = el( 'div', 'nlh-juice-block' );
		wrap.appendChild( el( 'h4', null, i18n.inboundTitle ) );
		if ( ! inbound.length ) {
			wrap.appendChild( el( 'p', 'nlh-juice-muted', i18n.noInbound ) );
			return wrap;
		}
		var list = el( 'ul', 'nlh-juice-link-list' );
		inbound.forEach( function ( item ) {
			var li = el( 'li' );
			if ( item.edit ) {
				var a = el( 'a', null, item.title || ( '#' + item.post_id ) );
				a.href = item.edit;
				li.appendChild( a );
			} else {
				li.appendChild( el( 'span', null, item.title || ( '#' + item.post_id ) ) );
			}
			list.appendChild( li );
		} );
		wrap.appendChild( list );
		return wrap;
	}

	function buildOutbound( postId, outbound ) {
		var wrap = el( 'div', 'nlh-juice-block' );
		wrap.appendChild( el( 'h4', null, i18n.outboundTitle ) );
		if ( ! outbound.length ) {
			wrap.appendChild( el( 'p', 'nlh-juice-muted', i18n.noOutbound ) );
			return wrap;
		}
		var list = el( 'ul', 'nlh-juice-link-list nlh-juice-outbound' );
		outbound.forEach( function ( item ) {
			var li = el( 'li', 'nlh-juice-outbound-item' );

			var label = el( 'div', 'nlh-juice-outbound-target' );
			var isInt = 'internal' === item.link_type;
			label.appendChild( el( 'span', 'nlh-flag ' + ( isInt ? 'nlh-flag-internal' : 'nlh-flag-external' ), isInt ? ( item.title || item.target_url ) : i18n.external ) );
			var urlSpan = el( 'span', 'nlh-juice-url', item.target_url );
			label.appendChild( urlSpan );
			li.appendChild( label );

			var form = el( 'div', 'nlh-juice-relink' );
			var input = el( 'input' );
			input.type = 'url';
			input.className = 'regular-text';
			input.placeholder = i18n.newUrl;
			form.appendChild( input );

			var btn = el( 'button', 'button button-small', i18n.repoint );
			btn.type = 'button';
			btn.addEventListener( 'click', function () {
				var newUrl = input.value.trim();
				if ( ! newUrl || ! window.confirm( i18n.confirmRelink ) ) { return; }
				btn.disabled = true;
				btn.textContent = i18n.relinking;
				post( 'nlh_juice_relink', { post_id: postId, old_url: item.target_url, new_url: newUrl } ).then( function ( res ) {
					if ( res && res.success ) {
						notify( ( res.data && res.data.message ) || i18n.relinked, false );
						urlSpan.textContent = newUrl;
						item.target_url = newUrl;
						input.value = '';
					} else {
						notify( ( res && res.data && res.data.message ) || i18n.error, true );
					}
					btn.textContent = i18n.repoint;
					btn.disabled = false;
				} ).catch( function () {
					notify( i18n.error, true );
					btn.textContent = i18n.repoint;
					btn.disabled = false;
				} );
			} );
			form.appendChild( btn );
			li.appendChild( form );
			list.appendChild( li );
		} );
		wrap.appendChild( list );
		return wrap;
	}

	/* --------------------------------------------------------------- *
	 * Shared pan / zoom helpers
	 * --------------------------------------------------------------- */

	function applyView() {
		if ( overview.root && overview.view ) {
			var v = overview.view;
			overview.root.setAttribute( 'viewBox', v.x + ' ' + v.y + ' ' + v.w + ' ' + v.h );
		}
	}

	function updateEdges() {
		if ( ! overview.gEdges || ! overview.byId ) { return; }
		Array.prototype.forEach.call( overview.gEdges.childNodes, function ( line ) {
			var a = overview.byId[ line._s ], b = overview.byId[ line._t ];
			if ( a && b ) {
				var dx = b.x - a.x, dy = b.y - a.y;
				var len = Math.sqrt( dx * dx + dy * dy ) || 1;
				var offset = ( b.r || 8 ) + 4;
				line.setAttribute( 'x1', a.x );
				line.setAttribute( 'y1', a.y );
				line.setAttribute( 'x2', b.x - ( dx / len ) * offset );
				line.setAttribute( 'y2', b.y - ( dy / len ) * offset );
			}
		} );
	}

	function ovArrowDefs() {
		var defs = svg( 'defs' );
		var m = svg( 'marker', {
			id: 'nlh-ov-arrow',
			viewBox: '0 0 10 10',
			refX: '9', refY: '5',
			markerWidth: '7', markerHeight: '7',
			orient: 'auto'
		} );
		m.appendChild( svg( 'path', { d: 'M 0 0 L 10 5 L 0 10 z', fill: '#8c8f94' } ) );
		defs.appendChild( m );
		return defs;
	}

	// Zoom around the current view centre.
	function zoomStep( factor ) {
		if ( ! overview.view || ! overview.base ) { return; }
		var v = overview.view;
		var nw = Math.max( 120, Math.min( overview.base.w * 3, v.w * factor ) );
		var nh = nw * ( overview.base.h / overview.base.w );
		v.x += ( v.w - nw ) * 0.5;
		v.y += ( v.h - nh ) * 0.5;
		v.w = nw; v.h = nh;
		applyView();
	}

	// Create +/- overlay buttons inside the canvas div.
	function createZoomButtons( canvas ) {
		var existing = canvas.querySelector( '.nlh-zoom-btns' );
		if ( existing ) { existing.parentNode.removeChild( existing ); }

		var wrap = el( 'div', 'nlh-zoom-btns' );

		var btnIn = el( 'button', 'button nlh-zoom-btn', '+' );
		btnIn.type = 'button';
		btnIn.setAttribute( 'aria-label', i18n.zoomIn || 'Zoom in' );
		btnIn.setAttribute( 'title', i18n.zoomIn || 'Zoom in' );
		btnIn.addEventListener( 'click', function () { zoomStep( 0.75 ); } );

		var btnOut = el( 'button', 'button nlh-zoom-btn', '−' );
		btnOut.type = 'button';
		btnOut.setAttribute( 'aria-label', i18n.zoomOut || 'Zoom out' );
		btnOut.setAttribute( 'title', i18n.zoomOut || 'Zoom out' );
		btnOut.addEventListener( 'click', function () { zoomStep( 1.33 ); } );

		wrap.appendChild( btnIn );
		wrap.appendChild( btnOut );
		canvas.appendChild( wrap );
	}

	// Attach pan (mousedown only) to a root SVG; window listeners handle the rest.
	function enablePan( root ) {
		root.addEventListener( 'mousedown', function ( ev ) {
			if ( drag.active ) { return; }
			drag.active  = true;
			drag.type    = 'pan';
			drag.startX  = drag.lastX = ev.clientX;
			drag.startY  = drag.lastY = ev.clientY;
			drag.moved   = false;
			root.classList.add( 'is-panning' );
		} );
	}

	// Wire click-to-focus and background-clear for a rendered overview graph.
	function setupInteraction( root, nodeEls, gEdges ) {
		root.addEventListener( 'click', function () {
			if ( ! drag.moved ) {
				clearFocus( nodeEls, gEdges );
			}
		} );
	}

	/* --------------------------------------------------------------- *
	 * Global site overview — force-directed with RAF animation
	 * --------------------------------------------------------------- */

	function loadOverview( canvas ) {
		if ( overview.loaded ) { return; }
		canvas.textContent = i18n.loading || 'Loading...';

		post( 'nlh_juice_graph', {} ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				canvas.textContent = ( res && res.data && res.data.message ) || i18n.error;
				return;
			}
			renderOverview( canvas, res.data );
			overview.loaded = true;
		} ).catch( function () {
			canvas.textContent = i18n.error;
		} );
	}

	function renderOverview( canvas, data ) {
		// Cancel any running simulation from a previous view.
		if ( overview.rafId ) { cancelAnimationFrame( overview.rafId ); overview.rafId = null; }
		drag.active = false;

		currentData = data;
		canvas.textContent = '';
		var nodes = data.nodes || [];
		var edges = data.edges || [];

		if ( ! nodes.length ) {
			canvas.appendChild( el( 'p', 'nlh-juice-muted', i18n.noOutbound || 'No data.' ) );
			return;
		}

		if ( data.total > data.shown ) {
			canvas.appendChild( el( 'p', 'nlh-overview-cap', fmt( i18n.overviewCap, data.shown, data.total ) ) );
		}

		var W = 900, H = 560;
		var byId = {};
		nodes.forEach( function ( n, i ) {
			// Spread on a circle — simulation pulls to final positions.
			var ang = ( i / nodes.length ) * Math.PI * 2;
			n.x = W / 2 + Math.cos( ang ) * 220;
			n.y = H / 2 + Math.sin( ang ) * 200;
			n.vx = 0; n.vy = 0;
			byId[ n.id ] = n;
		} );

		var links = edges.filter( function ( e ) { return byId[ e.s ] && byId[ e.t ]; } );

		var maxPr = nodes.reduce( function ( m, n ) { return Math.max( m, n.pr ); }, 0 ) || 1;
		nodes.forEach( function ( n ) { n.r = 5 + Math.sqrt( n.pr / maxPr ) * 15; } );

		var root = svg( 'svg', {
			viewBox: '0 0 ' + W + ' ' + H,
			class: 'nlh-overview-svg',
			role: 'img',
			'aria-label': i18n.overviewHint || 'Site authority map. Bigger nodes hold more authority.'
		} );
		root.appendChild( ovArrowDefs() );

		var gEdges = svg( 'g', { class: 'nlh-ov-edges' } );
		links.forEach( function ( e ) {
			var a = byId[ e.s ], b = byId[ e.t ];
			var dx = b.x - a.x, dy = b.y - a.y;
			var len = Math.sqrt( dx * dx + dy * dy ) || 1;
			var offset = b.r + 4;
			var line = svg( 'line', {
				x1: a.x, y1: a.y,
				x2: b.x - ( dx / len ) * offset,
				y2: b.y - ( dy / len ) * offset,
				class: 'nlh-ov-edge',
				'marker-end': 'url(#nlh-ov-arrow)'
			} );
			line._s = e.s; line._t = e.t;
			gEdges.appendChild( line );
		} );
		root.appendChild( gEdges );

		var gNodes = svg( 'g', { class: 'nlh-ov-nodes' } );
		var nodeEls = {};
		var labelCut = nodes.slice().sort( function ( a, b ) { return b.pr - a.pr; } )[ Math.min( 11, nodes.length - 1 ) ];
		var labelThreshold = labelCut ? labelCut.pr : 0;

		nodes.forEach( function ( n ) {
			var r = n.r;
			var ariaLabel = n.title + ' — ' + ( n.pr * 100 ).toFixed( 2 ) + '%' + ( n.broken_count ? ' — ' + n.broken_count + ' broken link(s)' : '' );
			var g = svg( 'g', {
				class: 'nlh-ov-node nlh-ov-' + n.flag,
				transform: 'translate(' + n.x + ',' + n.y + ')',
				role: 'button',
				tabindex: '0',
				'aria-label': ariaLabel
			} );
			var c = svg( 'circle', { r: r, class: 'nlh-ov-circle' } );
			var title = svg( 'title' );
			title.textContent = ariaLabel;
			c.appendChild( title );
			g.appendChild( c );

			if ( n.pr >= labelThreshold ) {
				var t = svg( 'text', { x: 0, y: r + 11, 'text-anchor': 'middle', class: 'nlh-ov-label' } );
				t.textContent = truncate( n.title, 18 );
				g.appendChild( t );
			}

			// Mousedown on node: drag node (not pan). stopPropagation prevents pan start.
			g.addEventListener( 'mousedown', function ( ev ) {
				ev.stopPropagation();
				drag.active   = true;
				drag.type     = 'node';
				drag.nodeData = n;
				drag.nodeEl   = g;
				drag.startX   = drag.lastX = ev.clientX;
				drag.startY   = drag.lastY = ev.clientY;
				drag.moved    = false;
			} );

			g.addEventListener( 'click', function ( ev ) {
				ev.stopPropagation();
				if ( ! drag.moved ) {
					focusNode( n.id, byId, nodeEls, gEdges );
				}
			} );
			g.addEventListener( 'keydown', function ( ev ) {
				if ( ev.key === 'Enter' || ev.key === ' ' ) {
					ev.preventDefault();
					focusNode( n.id, byId, nodeEls, gEdges );
				}
			} );
			g.addEventListener( 'keyup', function ( ev ) {
				if ( ev.key === 'Escape' ) {
					clearFocus( nodeEls, gEdges );
				}
			} );
			gNodes.appendChild( g );
			nodeEls[ n.id ] = g;
		} );
		root.appendChild( gNodes );

		setupInteraction( root, nodeEls, gEdges );

		canvas.appendChild( root );

		// aria-live region for screen reader focus announcements.
		if ( ! liveRegion && canvas.parentNode ) {
			liveRegion = el( 'div', 'screen-reader-text' );
			liveRegion.setAttribute( 'aria-live', 'polite' );
			liveRegion.setAttribute( 'aria-atomic', 'true' );
			canvas.parentNode.appendChild( liveRegion );
		}

		// Broken-details panel shown on demand when a broken node is focused.
		if ( ! brokenPanel && canvas.parentNode ) {
			brokenPanel = el( 'div', 'nlh-broken-panel' );
			brokenPanel.hidden = true;
			canvas.parentNode.appendChild( brokenPanel );
		}

		overview.root    = root;
		overview.base    = { x: 0, y: 0, w: W, h: H };
		overview.view    = { x: 0, y: 0, w: W, h: H };
		overview.nodeEls = nodeEls;
		overview.gEdges  = gEdges;
		overview.byId    = byId;

		applyView();
		enablePan( root );
		createZoomButtons( canvas );

		// Animate small graphs; run synchronous Barnes-Hut for large ones.
		if ( nodes.length <= 200 ) {
			startSimulationRaf( nodes, links, byId, W, H );
		} else {
			simulateBarnesHut( nodes, links, byId, W, H );
			nodes.forEach( function ( n ) {
				var g = nodeEls[ n.id ];
				if ( g ) { g.setAttribute( 'transform', 'translate(' + n.x + ',' + n.y + ')' ); }
			} );
			updateEdges();
		}
	}

	// RAF-driven simulation (≤200 nodes). Runs ~4 physics steps per frame so
	// the layout animates visibly (~1.2 s) rather than snapping to final state.
	// Pinned/dragged nodes are skipped each step so drag feels immediate.
	function startSimulationRaf( nodes, links, byId, W, H ) {
		var TOTAL_ITERS    = 300;
		var ITERS_PER_FRAME = 4;
		var REP    = 8000;   // stronger repulsion for better spread
		var SPRING = 0.008;
		var REST   = 130;    // longer rest length to reduce clumping
		var CENTER = 0.008;  // weaker centre pull
		var cx = W / 2, cy = H / 2;
		var iter = 0;

		function tick() {
			var end = Math.min( iter + ITERS_PER_FRAME, TOTAL_ITERS );
			for ( var it = iter; it < end; it++ ) {
				var cool = 1 - it / TOTAL_ITERS;
				nodes.forEach( function ( n ) { n.fx = 0; n.fy = 0; } );

				for ( var i = 0; i < nodes.length; i++ ) {
					for ( var j = i + 1; j < nodes.length; j++ ) {
						var a = nodes[ i ], b = nodes[ j ];
						var dx = a.x - b.x, dy = a.y - b.y;
						var d2 = dx * dx + dy * dy + 0.01;
						var d  = Math.sqrt( d2 );
						var f  = REP / d2;
						var ux = dx / d, uy = dy / d;
						a.fx += ux * f; a.fy += uy * f;
						b.fx -= ux * f; b.fy -= uy * f;
					}
				}

				links.forEach( function ( e ) {
					var a = byId[ e.s ], b = byId[ e.t ];
					if ( ! a || ! b ) { return; }
					var dx = b.x - a.x, dy = b.y - a.y;
					var d = Math.sqrt( dx * dx + dy * dy ) || 1;
					var f = SPRING * ( d - REST );
					var ux = dx / d, uy = dy / d;
					a.fx += ux * f; a.fy += uy * f;
					b.fx -= ux * f; b.fy -= uy * f;
				} );

				nodes.forEach( function ( n ) {
					// Dragged node is pinned — skip physics so drag feels responsive.
					if ( drag.type === 'node' && drag.nodeData === n ) { return; }
					n.fx += ( cx - n.x ) * CENTER;
					n.fy += ( cy - n.y ) * CENTER;
					n.vx = ( n.vx + n.fx ) * 0.85 * cool;
					n.vy = ( n.vy + n.fy ) * 0.85 * cool;
					n.x += Math.max( -20, Math.min( 20, n.vx ) );
					n.y += Math.max( -20, Math.min( 20, n.vy ) );
					n.x = Math.max( 20, Math.min( W - 20, n.x ) );
					n.y = Math.max( 20, Math.min( H - 20, n.y ) );
				} );
			}
			iter = end;

			// Update DOM positions each frame (both nodes and edges).
			nodes.forEach( function ( n ) {
				var g = overview.nodeEls && overview.nodeEls[ n.id ];
				if ( g ) { g.setAttribute( 'transform', 'translate(' + n.x + ',' + n.y + ')' ); }
			} );
			updateEdges();

			if ( iter < TOTAL_ITERS ) {
				overview.rafId = requestAnimationFrame( tick );
			} else {
				overview.rafId = null;
			}
		}

		overview.rafId = requestAnimationFrame( tick );
	}

	/* --------------------------------------------------------------- *
	 * Concentric (rings) view — with edges, labels, focus/dim, pan/zoom
	 * --------------------------------------------------------------- */

	function renderConcentric( canvas, data ) {
		if ( overview.rafId ) { cancelAnimationFrame( overview.rafId ); overview.rafId = null; }
		drag.active = false;

		canvas.textContent = '';
		var nodes = ( data.nodes || [] ).slice().sort( function ( a, b ) { return b.pr - a.pr; } );
		if ( ! nodes.length ) {
			canvas.appendChild( el( 'p', 'nlh-juice-muted', i18n.noOutbound || 'No data.' ) );
			return;
		}

		var W = 900, H = 560;
		// Rings sized to stay clear of the canvas edges.
		var rings = [ 55, 120, 185, 248 ];
		var ringLabels = [
			i18n.ringHighest || 'Top authority',
			i18n.ringHigh    || 'High authority',
			i18n.ringMid     || 'Medium authority',
			i18n.ringLow     || 'Lower authority'
		];

		var buckets = [ [], [], [], [] ];
		nodes.forEach( function ( n, i ) {
			buckets[ Math.min( 3, Math.floor( i / Math.max( 1, nodes.length / 4 ) ) ) ].push( n );
		} );
		buckets.forEach( function ( bucket, ri ) {
			var r = rings[ ri ];
			bucket.forEach( function ( n, i ) {
				var ang = ( i / Math.max( 1, bucket.length ) ) * Math.PI * 2 - Math.PI / 2;
				n.x = W / 2 + Math.cos( ang ) * r;
				n.y = H / 2 + Math.sin( ang ) * r;
			} );
		} );

		var edges = data.edges || [];
		var byId  = {};
		nodes.forEach( function ( n ) { byId[ n.id ] = n; } );
		var links = edges.filter( function ( e ) { return byId[ e.s ] && byId[ e.t ]; } );

		var maxPr = nodes.reduce( function ( m, n ) { return Math.max( m, n.pr ); }, 0 ) || 1;
		nodes.forEach( function ( n ) { n.r = 4 + Math.sqrt( n.pr / maxPr ) * 12; } );

		var root = svg( 'svg', {
			viewBox: '0 0 ' + W + ' ' + H,
			class: 'nlh-overview-svg',
			role: 'img',
			'aria-label': i18n.overviewHint || 'Site authority map'
		} );
		root.appendChild( ovArrowDefs() );

		// Ring circles.
		rings.forEach( function ( r ) {
			root.appendChild( svg( 'circle', {
				cx: W / 2, cy: H / 2, r: r,
				fill: 'none', stroke: '#e0e0e2', 'stroke-width': 1, 'stroke-dasharray': '4 3'
			} ) );
		} );

		// Ring labels at the 3-o'clock position of each ring.
		rings.forEach( function ( r, ri ) {
			var t = svg( 'text', {
				x: W / 2 + r + 8,
				y: H / 2 + 4,
				class: 'nlh-ring-label'
			} );
			t.textContent = ringLabels[ ri ];
			root.appendChild( t );
		} );

		// Edges (drawn behind nodes).
		var gEdges = svg( 'g', { class: 'nlh-ov-edges' } );
		links.forEach( function ( e ) {
			var a = byId[ e.s ], b = byId[ e.t ];
			var dx = b.x - a.x, dy = b.y - a.y;
			var len = Math.sqrt( dx * dx + dy * dy ) || 1;
			var offset = b.r + 4;
			var line = svg( 'line', {
				x1: a.x, y1: a.y,
				x2: b.x - ( dx / len ) * offset,
				y2: b.y - ( dy / len ) * offset,
				class: 'nlh-ov-edge',
				'marker-end': 'url(#nlh-ov-arrow)'
			} );
			line._s = e.s; line._t = e.t;
			gEdges.appendChild( line );
		} );
		root.appendChild( gEdges );

		// Nodes — top 8 by authority get visible labels.
		var gNodes = svg( 'g' );
		var nodeEls = {};
		var TOP_LABELS = 8;

		nodes.forEach( function ( n, idx ) {
			var r = n.r;
			var ariaLabel = n.title + ' — ' + ( n.pr * 100 ).toFixed( 2 ) + '%';
			var g = svg( 'g', {
				class: 'nlh-ov-node nlh-ov-' + n.flag,
				transform: 'translate(' + n.x + ',' + n.y + ')',
				role: 'button',
				tabindex: '0',
				'aria-label': ariaLabel
			} );
			var c = svg( 'circle', { r: r, class: 'nlh-ov-circle' } );
			var title = svg( 'title' );
			title.textContent = n.title + ' — ' + ( n.pr * 100 ).toFixed( 2 ) + '%';
			c.appendChild( title );
			g.appendChild( c );

			if ( idx < TOP_LABELS ) {
				var t = svg( 'text', { x: 0, y: r + 11, 'text-anchor': 'middle', class: 'nlh-ov-label' } );
				t.textContent = truncate( n.title, 14 );
				g.appendChild( t );
			}

			g.addEventListener( 'click', function ( ev ) {
				ev.stopPropagation();
				if ( ! drag.moved ) { focusNode( n.id, byId, nodeEls, gEdges ); }
			} );
			g.addEventListener( 'keydown', function ( ev ) {
				if ( ev.key === 'Enter' || ev.key === ' ' ) {
					ev.preventDefault();
					focusNode( n.id, byId, nodeEls, gEdges );
				}
			} );
			g.addEventListener( 'keyup', function ( ev ) {
				if ( ev.key === 'Escape' ) { clearFocus( nodeEls, gEdges ); }
			} );

			gNodes.appendChild( g );
			nodeEls[ n.id ] = g;
		} );
		root.appendChild( gNodes );

		setupInteraction( root, nodeEls, gEdges );

		canvas.appendChild( root );

		overview.root    = root;
		overview.base    = { x: 0, y: 0, w: W, h: H };
		overview.view    = { x: 0, y: 0, w: W, h: H };
		overview.nodeEls = nodeEls;
		overview.gEdges  = gEdges;
		overview.byId    = byId;

		applyView();
		enablePan( root );
		createZoomButtons( canvas );
	}

	/* --------------------------------------------------------------- *
	 * Bubble scatter view — with edges, axis titles, focus/dim, pan/zoom
	 * --------------------------------------------------------------- */

	function renderBubbleScatter( canvas, data ) {
		if ( overview.rafId ) { cancelAnimationFrame( overview.rafId ); overview.rafId = null; }
		drag.active = false;

		canvas.textContent = '';
		var nodes = data.nodes || [];
		if ( ! nodes.length ) {
			canvas.appendChild( el( 'p', 'nlh-juice-muted', i18n.noOutbound || 'No data.' ) );
			return;
		}

		var W = 900, H = 560, PAD = 62;
		var maxInb = nodes.reduce( function ( m, n ) { return Math.max( m, n.inb ); }, 1 ) || 1;
		var maxOut = nodes.reduce( function ( m, n ) { return Math.max( m, n.out ); }, 1 ) || 1;
		var maxPr  = nodes.reduce( function ( m, n ) { return Math.max( m, n.pr  ); }, 0 ) || 1;

		// Assign scatter positions.
		var byId = {};
		nodes.forEach( function ( n ) {
			n.x = PAD + ( n.inb / maxInb ) * ( W - PAD * 2 );
			n.y = H - PAD - ( n.out / maxOut ) * ( H - PAD * 2 );
			n.r = 4 + Math.sqrt( n.pr / maxPr ) * 14;
			byId[ n.id ] = n;
		} );

		var edges = data.edges || [];
		var links = edges.filter( function ( e ) { return byId[ e.s ] && byId[ e.t ]; } );

		var root = svg( 'svg', {
			viewBox: '0 0 ' + W + ' ' + H,
			class: 'nlh-overview-svg',
			role: 'img',
			'aria-label': i18n.overviewHint || 'Site authority map'
		} );
		root.appendChild( ovArrowDefs() );

		// Chart sub-title explaining bubble size.
		var chartTitle = svg( 'text', { x: W / 2, y: 20, 'text-anchor': 'middle', class: 'nlh-scatter-title' } );
		chartTitle.textContent = i18n.scatterTitle || 'Bubble size = authority (link juice) · Click a bubble to see its connections';
		root.appendChild( chartTitle );

		// Light grid.
		for ( var gi = 1; gi <= 4; gi++ ) {
			var gx = PAD + ( gi / 4 ) * ( W - PAD * 2 );
			var gy = H - PAD - ( gi / 4 ) * ( H - PAD * 2 );
			root.appendChild( svg( 'line', { x1: gx, y1: PAD, x2: gx, y2: H - PAD, stroke: '#f0f0f1', 'stroke-width': 1 } ) );
			root.appendChild( svg( 'line', { x1: PAD, y1: gy, x2: W - PAD, y2: gy, stroke: '#f0f0f1', 'stroke-width': 1 } ) );
		}

		// Axes.
		root.appendChild( svg( 'line', { x1: PAD, y1: H - PAD, x2: W - PAD, y2: H - PAD, stroke: '#c3c4c7', 'stroke-width': 1 } ) );
		root.appendChild( svg( 'line', { x1: PAD, y1: PAD,     x2: PAD,     y2: H - PAD, stroke: '#c3c4c7', 'stroke-width': 1 } ) );

		// Axis labels.
		var xLabel = svg( 'text', { x: W / 2, y: H - 10, 'text-anchor': 'middle', fill: '#646970', 'font-size': '11' } );
		xLabel.textContent = i18n.scatterX || 'Inbound links (links this page receives)';
		root.appendChild( xLabel );

		var yLabel = svg( 'text', {
			x: 14, y: H / 2, 'text-anchor': 'middle',
			fill: '#646970', 'font-size': '11',
			transform: 'rotate(-90,14,' + ( H / 2 ) + ')'
		} );
		yLabel.textContent = i18n.scatterY || 'Outbound links (links this page sends)';
		root.appendChild( yLabel );

		// Edges (behind nodes).
		var gEdges = svg( 'g', { class: 'nlh-ov-edges' } );
		links.forEach( function ( e ) {
			var a = byId[ e.s ], b = byId[ e.t ];
			var dx = b.x - a.x, dy = b.y - a.y;
			var len = Math.sqrt( dx * dx + dy * dy ) || 1;
			var offset = b.r + 4;
			var line = svg( 'line', {
				x1: a.x, y1: a.y,
				x2: b.x - ( dx / len ) * offset,
				y2: b.y - ( dy / len ) * offset,
				class: 'nlh-ov-edge',
				'marker-end': 'url(#nlh-ov-arrow)'
			} );
			line._s = e.s; line._t = e.t;
			gEdges.appendChild( line );
		} );
		root.appendChild( gEdges );

		// Nodes.
		var gNodes = svg( 'g' );
		var nodeEls = {};
		nodes.forEach( function ( n ) {
			var r = n.r;
			var ariaLabel = n.title + ' — inb:' + n.inb + ' out:' + n.out;
			var g = svg( 'g', {
				class: 'nlh-ov-node nlh-ov-' + n.flag,
				transform: 'translate(' + n.x + ',' + n.y + ')',
				role: 'button',
				tabindex: '0',
				'aria-label': ariaLabel
			} );
			var c = svg( 'circle', { r: r, class: 'nlh-ov-circle', opacity: '0.85' } );
			var title = svg( 'title' );
			title.textContent = n.title + ' — ' + ( n.pr * 100 ).toFixed( 2 ) + '% authority · inbound: ' + n.inb + ' · outbound: ' + n.out;
			c.appendChild( title );
			g.appendChild( c );

			g.addEventListener( 'click', function ( ev ) {
				ev.stopPropagation();
				if ( ! drag.moved ) { focusNode( n.id, byId, nodeEls, gEdges ); }
			} );
			g.addEventListener( 'keydown', function ( ev ) {
				if ( ev.key === 'Enter' || ev.key === ' ' ) {
					ev.preventDefault();
					focusNode( n.id, byId, nodeEls, gEdges );
				}
			} );
			g.addEventListener( 'keyup', function ( ev ) {
				if ( ev.key === 'Escape' ) { clearFocus( nodeEls, gEdges ); }
			} );

			gNodes.appendChild( g );
			nodeEls[ n.id ] = g;
		} );
		root.appendChild( gNodes );

		setupInteraction( root, nodeEls, gEdges );

		canvas.appendChild( root );

		overview.root    = root;
		overview.base    = { x: 0, y: 0, w: W, h: H };
		overview.view    = { x: 0, y: 0, w: W, h: H };
		overview.nodeEls = nodeEls;
		overview.gEdges  = gEdges;
		overview.byId    = byId;

		applyView();
		enablePan( root );
		createZoomButtons( canvas );
	}

	/* --------------------------------------------------------------- *
	 * Barnes-Hut simulation (>200 nodes, synchronous)
	 * --------------------------------------------------------------- */

	function simulateBarnesHut( nodes, links, byId, W, H ) {
		var ITERS  = 220;
		var THETA  = 0.8;
		var REP    = 8000;
		var SPRING = 0.008;
		var REST   = 130;
		var CENTER = 0.008;
		var cx = W / 2, cy = H / 2;

		for ( var it = 0; it < ITERS; it++ ) {
			var cool = 1 - it / ITERS;
			nodes.forEach( function ( n ) { n.fx = 0; n.fy = 0; } );

			// Build bounding box.
			var minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
			nodes.forEach( function ( n ) {
				if ( n.x < minX ) { minX = n.x; } if ( n.x > maxX ) { maxX = n.x; }
				if ( n.y < minY ) { minY = n.y; } if ( n.y > maxY ) { maxY = n.y; }
			} );
			var size = Math.max( maxX - minX, maxY - minY ) + 1;

			function buildTree( pts, x0, y0, s ) {
				if ( ! pts.length ) { return null; }
				var node = { cx: 0, cy: 0, mass: pts.length, children: null };
				pts.forEach( function ( p ) { node.cx += p.x; node.cy += p.y; } );
				node.cx /= pts.length; node.cy /= pts.length;
				if ( pts.length === 1 ) { return node; }
				var half = s / 2;
				var mid = x0 + half, midy = y0 + half;
				var q = [ [], [], [], [] ];
				pts.forEach( function ( p ) {
					q[ ( p.x < mid ? 0 : 1 ) + ( p.y < midy ? 0 : 2 ) ].push( p );
				} );
				node.children = [
					buildTree( q[ 0 ], x0,        y0,        half ),
					buildTree( q[ 1 ], x0 + half,  y0,        half ),
					buildTree( q[ 2 ], x0,         y0 + half, half ),
					buildTree( q[ 3 ], x0 + half,  y0 + half, half )
				];
				node.s = s;
				return node;
			}

			var tree = buildTree( nodes.slice(), minX, minY, size );

			nodes.forEach( function ( n ) {
				function applyTree( t ) {
					if ( ! t ) { return; }
					var dx = n.x - t.cx, dy = n.y - t.cy;
					var d2 = dx * dx + dy * dy + 0.01;
					var d  = Math.sqrt( d2 );
					if ( ! t.children || ( t.s && t.s / d < THETA ) ) {
						var f = REP * t.mass / d2;
						n.fx += ( dx / d ) * f;
						n.fy += ( dy / d ) * f;
					} else {
						t.children.forEach( applyTree );
					}
				}
				applyTree( tree );
			} );

			links.forEach( function ( e ) {
				var a = byId[ e.s ], b = byId[ e.t ];
				if ( ! a || ! b ) { return; }
				var dx = b.x - a.x, dy = b.y - a.y;
				var d  = Math.sqrt( dx * dx + dy * dy ) || 1;
				var f  = SPRING * ( d - REST );
				var ux = dx / d, uy = dy / d;
				a.fx += ux * f; a.fy += uy * f;
				b.fx -= ux * f; b.fy -= uy * f;
			} );

			nodes.forEach( function ( n ) {
				n.fx += ( cx - n.x ) * CENTER;
				n.fy += ( cy - n.y ) * CENTER;
				n.vx = ( n.vx + n.fx ) * 0.85 * cool;
				n.vy = ( n.vy + n.fy ) * 0.85 * cool;
				n.x += Math.max( -20, Math.min( 20, n.vx ) );
				n.y += Math.max( -20, Math.min( 20, n.vy ) );
				n.x = Math.max( 20, Math.min( W - 20, n.x ) );
				n.y = Math.max( 20, Math.min( H - 20, n.y ) );
			} );
		}
	}

	/* --------------------------------------------------------------- *
	 * Broken-details panel
	 * --------------------------------------------------------------- */

	function showBrokenPanel( title, links ) {
		if ( ! brokenPanel ) { return; }
		brokenPanel.textContent = '';

		var heading = el( 'strong', 'nlh-bp-heading', title + ' — ' + ( i18n.brokenLinks || 'Broken links' ) );
		brokenPanel.appendChild( heading );

		if ( ! links || ! links.length ) {
			brokenPanel.appendChild( el( 'p', 'nlh-bp-empty', i18n.noBroken || 'No broken links found.' ) );
		} else {
			var list = el( 'ul', 'nlh-bp-list' );
			links.forEach( function ( link ) {
				var li   = el( 'li', 'nlh-bp-item' );
				var code = el( 'span', 'nlh-bp-code', link.status_code ? String( link.status_code ) : '—' );
				var url  = el( 'span', 'nlh-bp-url', truncate( link.raw_url || link.target_url || '?', 80 ) );
				url.title = link.raw_url || link.target_url || '';
				li.appendChild( code );
				li.appendChild( url );
				list.appendChild( li );
			} );
			brokenPanel.appendChild( list );
		}

		brokenPanel.hidden = false;
	}

	function hideBrokenPanel() {
		if ( brokenPanel ) { brokenPanel.hidden = true; brokenPanel.textContent = ''; }
	}

	/* --------------------------------------------------------------- *
	 * Focus / clear — works for all three views
	 * --------------------------------------------------------------- */

	function focusNode( id, byId, nodeEls, gEdges ) {
		var neighbors = {};
		neighbors[ id ] = true;
		Array.prototype.forEach.call( gEdges.childNodes, function ( line ) {
			if ( line._s === id ) { neighbors[ line._t ] = true; }
			if ( line._t === id ) { neighbors[ line._s ] = true; }
		} );

		Object.keys( nodeEls ).forEach( function ( nid ) {
			nodeEls[ nid ].classList.toggle( 'is-dim',   ! neighbors[ nid ] );
			nodeEls[ nid ].classList.toggle( 'is-focus', String( nid ) === String( id ) );
		} );

		Array.prototype.forEach.call( gEdges.childNodes, function ( line ) {
			line.classList.remove( 'is-in', 'is-out', 'is-dim' );
			if ( line._s === id )      { line.classList.add( 'is-out' ); }
			else if ( line._t === id ) { line.classList.add( 'is-in'  ); }
			else                       { line.classList.add( 'is-dim' ); }
		} );

		// aria-live + broken-details panel.
		var nodeMeta = currentData && currentData.nodes && currentData.nodes.find( function ( n ) { return n.id === id; } );
		if ( nodeMeta ) {
			var announcement = nodeMeta.title + ' — ' + ( nodeMeta.pr * 100 ).toFixed( 1 ) + '% authority';
			if ( nodeMeta.broken_count ) {
				announcement += ' — ' + nodeMeta.broken_count + ' broken link(s)';
			}
			if ( liveRegion ) { liveRegion.textContent = announcement; }

			if ( nodeMeta.broken_count && cfg.brokenDetailsAction ) {
				post( cfg.brokenDetailsAction, { post_id: id } ).then( function ( r ) {
					if ( r && r.success ) {
						showBrokenPanel( nodeMeta.title, r.data );
						if ( liveRegion ) { liveRegion.textContent = announcement; }
					}
				} ).catch( function () {} );
			} else {
				hideBrokenPanel();
			}
		}
	}

	function clearFocus( nodeEls, gEdges ) {
		Object.keys( nodeEls ).forEach( function ( nid ) {
			nodeEls[ nid ].classList.remove( 'is-dim', 'is-focus' );
		} );
		Array.prototype.forEach.call( gEdges.childNodes, function ( line ) {
			line.classList.remove( 'is-in', 'is-out', 'is-dim' );
		} );
		if ( liveRegion ) { liveRegion.textContent = ''; }
		hideBrokenPanel();
	}

	/* --------------------------------------------------------------- *
	 * Wiring
	 * --------------------------------------------------------------- */

	function expandRow( row, defaultTab ) {
		var toggle = row.querySelector( '.nlh-juice-toggle' );
		var detailsRow = row.nextElementSibling;
		if ( ! toggle || ! detailsRow || ! detailsRow.classList.contains( 'nlh-juice-details-row' ) ) { return; }

		var container = detailsRow.querySelector( '.nlh-juice-details' );
		var postId = parseInt( row.getAttribute( 'data-post-id' ), 10 );
		toggle.setAttribute( 'aria-expanded', 'true' );
		detailsRow.hidden = false;
		if ( container && postId ) { loadDetails( container, postId, defaultTab || 'flow' ); }
	}

	document.addEventListener( 'DOMContentLoaded', function () {

		// Global drag/pan: one set of window listeners for the whole page.
		window.addEventListener( 'mousemove', function ( ev ) {
			if ( ! drag.active ) { return; }
			var moved = Math.abs( ev.clientX - drag.startX ) > 3 || Math.abs( ev.clientY - drag.startY ) > 3;
			if ( moved ) { drag.moved = true; }

			if ( drag.type === 'node' && overview.root ) {
				var rect = overview.root.getBoundingClientRect();
				var v    = overview.view;
				var svgX = v.x + ( ( ev.clientX - rect.left ) / rect.width  ) * v.w;
				var svgY = v.y + ( ( ev.clientY - rect.top  ) / rect.height ) * v.h;
				drag.nodeData.x = svgX;
				drag.nodeData.y = svgY;
				drag.nodeData.vx = 0;
				drag.nodeData.vy = 0;
				drag.nodeEl.setAttribute( 'transform', 'translate(' + svgX + ',' + svgY + ')' );
				updateEdges();
			} else if ( drag.type === 'pan' && overview.root ) {
				var rect2 = overview.root.getBoundingClientRect();
				var v2    = overview.view;
				v2.x -= ( ev.clientX - drag.lastX ) * ( v2.w / rect2.width  );
				v2.y -= ( ev.clientY - drag.lastY ) * ( v2.h / rect2.height );
				applyView();
			}

			drag.lastX = ev.clientX;
			drag.lastY = ev.clientY;
		} );

		window.addEventListener( 'mouseup', function () {
			if ( drag.active ) {
				drag.active = false;
				if ( overview.root ) { overview.root.classList.remove( 'is-panning' ); }
			}
		} );

		// Recompute buttons.
		[ 'nlh-recompute-juice', 'nlh-recompute-juice-empty', 'nlh-recompute-juice-stale' ].forEach( function ( id ) {
			var b = document.getElementById( id );
			if ( b ) { b.addEventListener( 'click', function () { recompute( b ); } ); }
		} );

		// Per-row expand toggles.
		document.querySelectorAll( '.nlh-juice-toggle' ).forEach( function ( toggle ) {
			toggle.addEventListener( 'click', function () {
				var row = toggle.closest( 'tr' );
				var detailsRow = row && row.nextElementSibling;
				if ( ! detailsRow || ! detailsRow.classList.contains( 'nlh-juice-details-row' ) ) { return; }
				var expanded = 'true' === toggle.getAttribute( 'aria-expanded' );
				toggle.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
				detailsRow.hidden = expanded;
				if ( ! expanded ) {
					var container = detailsRow.querySelector( '.nlh-juice-details' );
					var postId = parseInt( row.getAttribute( 'data-post-id' ), 10 );
					if ( container && postId ) { loadDetails( container, postId, 'flow' ); }
				}
			} );
		} );

		// Recommendation "Manage links" jump.
		document.querySelectorAll( '.nlh-rec-jump' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var pid = btn.getAttribute( 'data-post-id' );
				if ( ! /^\d+$/.test( pid ) ) { return; }
				var row = document.querySelector( '.nlh-juice-table tr[data-post-id="' + pid + '"]' );
				if ( ! row ) { return; }
				expandRow( row, 'links' );
				row.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			} );
		} );

		// Site overview toggle.
		var ovToggle = document.getElementById( 'nlh-overview-toggle' );
		var ovPanel  = document.getElementById( 'nlh-overview-panel' );
		if ( ovToggle && ovPanel ) {
			ovToggle.addEventListener( 'click', function () {
				var open = 'true' === ovToggle.getAttribute( 'aria-expanded' );
				ovToggle.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
				ovPanel.hidden = open;
				var label = ovToggle.querySelector( '.nlh-overview-toggle-label' );
				if ( label ) { label.textContent = open ? i18n.showOverview : i18n.hideOverview; }
				if ( ! open ) {
					var canvas = document.getElementById( 'nlh-overview-canvas' );
					if ( canvas ) { loadOverview( canvas ); }
				}
			} );
		}

		var ovCanvas = document.getElementById( 'nlh-overview-canvas' );
		if ( ovCanvas && ovPanel && ! ovPanel.hidden ) {
			loadOverview( ovCanvas );
		}

		// View selector (Force / Concentric / Scatter).
		document.querySelectorAll( '.nlh-view-selector .button' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				document.querySelectorAll( '.nlh-view-selector .button' ).forEach( function ( b ) {
					b.classList.remove( 'is-active' );
				} );
				btn.classList.add( 'is-active' );
				var canvas2 = document.getElementById( 'nlh-overview-canvas' );
				if ( ! canvas2 || ! currentData ) { return; }
				var view = btn.getAttribute( 'data-view' );
				if ( 'force' === view ) {
					renderOverview( canvas2, currentData );
				} else if ( 'rings' === view ) {
					renderConcentric( canvas2, currentData );
				} else if ( 'scatter' === view ) {
					renderBubbleScatter( canvas2, currentData );
				}
			} );
		} );

		// Reset view button.
		var ovReset = document.getElementById( 'nlh-overview-reset' );
		if ( ovReset ) {
			ovReset.addEventListener( 'click', function () {
				if ( overview.base ) {
					overview.view = { x: overview.base.x, y: overview.base.y, w: overview.base.w, h: overview.base.h };
					applyView();
				}
			} );
		}
	} );
} )();
