(function () {
	'use strict';

	function init() {
		// Do not render inside visual-builder canvases. This is intentionally also
		// checked in JavaScript so cached assets cannot add the button to a builder
		// preview after the server-side guard has skipped it.
		var builderFlags = ['elementor-preview', 'et_fb', 'et_pb_preview', 'fl_builder', 'fl_builder_ui', 'ct_builder', 'brizy-edit', 'brizy-edit-iframe', 'bricks', 'tve', 'vc_editable', 'customize_changeset_uuid'];
		var query = (window.location.search || '').toLowerCase();
		var bodyClasses = document.body ? (' ' + document.body.className + ' ') : '';
		var isBuilderPreview = builderFlags.some(function (flag) { return query.indexOf(flag) !== -1; }) || / (elementor-editor-active|et-fb|fl-builder-edit|bricks-is-frontend|vc_editor) /.test(bodyClasses);

		if (isBuilderPreview) {
			return;
		}

		var cfg = window.ReloadifyScrollTop || {};
		var showAfter = typeof cfg.showAfter === 'number' ? cfg.showAfter : 300;
		var position = cfg.position === 'left' ? 'left' : 'right';
		var verticalPosition = cfg.verticalPosition === 'top' ? 'top' : 'bottom';
		var horizontalOffset = parseInt(cfg.horizontalOffset, 10);
		var verticalOffset = parseInt(cfg.verticalOffset, 10);
		horizontalOffset = Math.min(1000, Math.max(0, isNaN(horizontalOffset) ? 24 : horizontalOffset));
		verticalOffset = Math.min(1000, Math.max(0, isNaN(verticalOffset) ? 28 : verticalOffset));
		var bg = /^#[0-9a-fA-F]{6}$/.test(cfg.bgColor || '') ? cfg.bgColor : '#4f46e5';
		var animation = ['fade', 'slide', 'zoom', 'bounce'].indexOf(cfg.animation) !== -1 ? cfg.animation : 'slide';
		var hover = ['none', 'lift', 'pulse', 'wiggle'].indexOf(cfg.hover) !== -1 ? cfg.hover : 'lift';
		var ring = String(cfg.ring) !== '0' && cfg.ring !== false;
		var label = cfg.label || 'Scroll to top';
		var icon = typeof cfg.icon === 'string' && /^dashicons-[a-z0-9-]+$/.test(cfg.icon) ? cfg.icon : 'default';
		var iconCss = typeof cfg.iconCss === 'string' && /^(https?:)?\//.test(cfg.iconCss) ? cfg.iconCss : '';

		var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

		var CIRCUMFERENCE = 2 * Math.PI * 21;

		var style = document.createElement('style');
		style.textContent =
			'.reloadify-scroll-top{position:fixed;z-index:99999;width:48px;height:48px;border-radius:50%;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;box-shadow:0 6px 18px rgba(0,0,0,.24);opacity:0;visibility:hidden;padding:0;transition:opacity .28s ease,transform .28s cubic-bezier(.22,1,.36,1),box-shadow .2s ease,visibility .28s;-webkit-tap-highlight-color:transparent;}' +
			'.reloadify-scroll-top--right{right:var(--reloadify-horizontal-offset);}.reloadify-scroll-top--left{left:var(--reloadify-horizontal-offset);}.reloadify-scroll-top--bottom{bottom:var(--reloadify-vertical-offset);}.reloadify-scroll-top--top{top:var(--reloadify-vertical-offset);}' +
			'.rst-anim-slide{transform:translateY(18px);}.rst-anim-zoom{transform:scale(.4);}.rst-anim-fade,.rst-anim-bounce{transform:none;}' +
			'.reloadify-scroll-top.is-visible{opacity:1;visibility:visible;transform:none;}' +
			'.rst-anim-bounce.is-visible{animation:rst-bounce-in .6s cubic-bezier(.34,1.56,.64,1) both;}' +
			'.rst-hover-lift.is-visible:hover{transform:translateY(-4px);box-shadow:0 12px 26px rgba(0,0,0,.3);}' +
			'.rst-hover-pulse.is-visible:hover{animation:rst-pulse 1s ease-in-out infinite;}' +
			'.rst-hover-wiggle.is-visible:hover .rst-icon{animation:rst-nudge .7s ease-in-out infinite;}' +
			'.reloadify-scroll-top.is-visible:active{transform:scale(.93);}' +
			'.reloadify-scroll-top:focus-visible{outline:3px solid #fff;outline-offset:2px;box-shadow:0 0 0 5px rgba(0,0,0,.35);}' +
			'.rst-ring{position:absolute;inset:0;width:100%;height:100%;transform:rotate(-90deg);pointer-events:none;}' +
			'.rst-ring circle{fill:none;stroke-width:3;}.rst-ring-track{stroke:rgba(255,255,255,.28);}' +
			'.rst-ring-bar{stroke:#fff;stroke-linecap:round;transition:stroke-dashoffset .12s linear;}' +
			'.rst-icon{position:relative;display:inline-flex;align-items:center;justify-content:center;line-height:1;}' +
			'.rst-icon .dashicons{width:24px;height:24px;font-size:24px;opacity:0;transition:opacity .2s ease;}.rst-icon.is-ready .dashicons{opacity:1;}' +
			'@keyframes rst-bounce-in{0%{opacity:0;transform:translateY(26px) scale(.7);}55%{opacity:1;transform:translateY(-7px) scale(1.06);}78%{transform:translateY(2px) scale(.98);}100%{transform:none;}}' +
			'@keyframes rst-pulse{0%,100%{transform:scale(1);}50%{transform:scale(1.12);}}' +
			'@keyframes rst-nudge{0%,100%{transform:translateY(0);}50%{transform:translateY(-4px);}}' +
			'@media (max-width:600px){.reloadify-scroll-top{width:44px;height:44px;}}' +
			'@media (prefers-reduced-motion:reduce){.reloadify-scroll-top,.rst-ring-bar{transition:opacity .15s linear;}.reloadify-scroll-top,.reloadify-scroll-top *{animation:none!important;}.reloadify-scroll-top.is-visible,.reloadify-scroll-top.is-visible:hover{transform:none;}}';
		document.head.appendChild(style);

		var btn = document.createElement('button');
		btn.type = 'button';
		btn.setAttribute('aria-label', label);
		btn.title = label;
		btn.className = 'reloadify-scroll-top reloadify-scroll-top--' + position + ' reloadify-scroll-top--' + verticalPosition + ' rst-anim-' + animation + ' rst-hover-' + hover;
		btn.style.background = bg;
		btn.style.setProperty('--reloadify-horizontal-offset', horizontalOffset + 'px');
		btn.style.setProperty('--reloadify-vertical-offset', verticalOffset + 'px');

		var progressBar = null;

		if (ring) {
			var svgNS = 'http://www.w3.org/2000/svg';
			var svg = document.createElementNS(svgNS, 'svg');
			svg.setAttribute('class', 'rst-ring');
			svg.setAttribute('viewBox', '0 0 48 48');
			svg.setAttribute('aria-hidden', 'true');
			var track = document.createElementNS(svgNS, 'circle');
			track.setAttribute('class', 'rst-ring-track');
			track.setAttribute('cx', '24');
			track.setAttribute('cy', '24');
			track.setAttribute('r', '21');
			progressBar = document.createElementNS(svgNS, 'circle');
			progressBar.setAttribute('class', 'rst-ring-bar');
			progressBar.setAttribute('cx', '24');
			progressBar.setAttribute('cy', '24');
			progressBar.setAttribute('r', '21');
			progressBar.style.strokeDasharray = String(CIRCUMFERENCE);
			progressBar.style.strokeDashoffset = String(CIRCUMFERENCE);
			svg.appendChild(track);
			svg.appendChild(progressBar);
			btn.appendChild(svg);
		}

		var iconWrap = document.createElement('span');
		iconWrap.className = 'rst-icon';
		iconWrap.setAttribute('aria-hidden', 'true');

		function builtInIcon() {
			iconWrap.className = 'rst-icon is-ready';
			iconWrap.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" focusable="false"><polyline points="18 15 12 9 6 15"></polyline></svg>';
		}

		var iconStarted = false;

		// Dashicons are WordPress's own icon font. It is only downloaded once the visitor
		// starts scrolling (or immediately when the button is always visible), and the
		// built-in chevron takes over if the font can't be loaded.
		function loadIconFont() {
			if (iconStarted || icon === 'default') {
				return;
			}
			iconStarted = true;

			var done = false;
			var link = document.createElement('link');
			link.rel = 'stylesheet';
			link.href = iconCss;
			link.onload = function () {
				done = true;
				iconWrap.classList.add('is-ready');
			};
			link.onerror = function () {
				if (!done) {
					builtInIcon();
				}
			};
			document.head.appendChild(link);
		}

		if (icon === 'default' || !iconCss) {
			builtInIcon();
		} else {
			var glyph = document.createElement('span');
			glyph.className = 'dashicons ' + icon;
			iconWrap.appendChild(glyph);
		}

		btn.appendChild(iconWrap);
		document.body.appendChild(btn);

		var ticking = false;

		function update() {
			var y = window.pageYOffset || document.documentElement.scrollTop || 0;
			btn.classList.toggle('is-visible', y > showAfter);

			if (progressBar) {
				var max = Math.max(1, (document.documentElement.scrollHeight || 1) - (window.innerHeight || 1));
				var ratio = Math.min(1, Math.max(0, y / max));
				progressBar.style.strokeDashoffset = String(CIRCUMFERENCE * (1 - ratio));
			}

			ticking = false;
		}

		function onScroll() {
			loadIconFont();
			if (ticking) {
				return;
			}
			ticking = true;
			window.requestAnimationFrame(update);
		}

		window.addEventListener('scroll', onScroll, { passive: true });
		window.addEventListener('resize', onScroll, { passive: true });
		update();

		if (showAfter === 0 && icon !== 'default' && iconCss) {
			loadIconFont();
		}

		btn.addEventListener('click', function () {
			window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
