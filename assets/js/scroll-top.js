(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var cfg = window.ReloadifyScrollTop || {};
		var showAfter = typeof cfg.showAfter === 'number' ? cfg.showAfter : 300;
		var position = cfg.position === 'left' ? 'left' : 'right';
		var bg = cfg.bgColor || '#4f46e5';

		var style = document.createElement('style');
		style.textContent =
			'.reloadify-scroll-top{position:fixed;bottom:28px;z-index:99999;width:44px;height:44px;border-radius:50%;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;box-shadow:0 4px 14px rgba(0,0,0,.22);opacity:0;visibility:hidden;transform:translateY(12px);transition:opacity .25s ease,transform .25s ease,visibility .25s;padding:0;}' +
			'.reloadify-scroll-top.is-visible{opacity:1;visibility:visible;transform:translateY(0);}' +
			'.reloadify-scroll-top--right{right:24px;}' +
			'.reloadify-scroll-top--left{left:24px;}' +
			'.reloadify-scroll-top:hover{filter:brightness(1.08);}' +
			'.reloadify-scroll-top:focus-visible{outline:2px solid #fff;outline-offset:2px;}' +
			'@media (max-width:600px){.reloadify-scroll-top{width:40px;height:40px;bottom:18px;}.reloadify-scroll-top--right{right:16px;}.reloadify-scroll-top--left{left:16px;}}';
		document.head.appendChild(style);

		var btn = document.createElement('button');
		btn.type = 'button';
		btn.setAttribute('aria-label', 'Scroll to top');
		btn.className = 'reloadify-scroll-top reloadify-scroll-top--' + position;
		btn.style.background = bg;
		btn.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="18 15 12 9 6 15"></polyline></svg>';

		document.body.appendChild(btn);

		var ticking = false;

		function updateVisibility() {
			if (window.scrollY > showAfter) {
				btn.classList.add('is-visible');
			} else {
				btn.classList.remove('is-visible');
			}
			ticking = false;
		}

		function onScroll() {
			if (ticking) {
				return;
			}
			ticking = true;
			window.requestAnimationFrame(updateVisibility);
		}

		window.addEventListener('scroll', onScroll, { passive: true });
		updateVisibility();

		btn.addEventListener('click', function () {
			window.scrollTo({ top: 0, behavior: 'smooth' });
		});
	});
})();
