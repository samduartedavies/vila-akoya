/**
 * Re-run Tailwind against the block/Site Editor canvas iframe (utilities are otherwise missing).
 */
(function () {
	function findEditorCanvas() {
		return (
			document.querySelector('iframe[name="editor-canvas"]') ||
			document.querySelector('iframe.edit-site-visual-editor__iframe') ||
			document.querySelector('.block-editor-iframe__container iframe')
		);
	}

	function refresh() {
		if (typeof tailwind === 'undefined' || typeof tailwind.refresh !== 'function') {
			return;
		}
		var iframe = findEditorCanvas();
		if (iframe && iframe.contentDocument && iframe.contentDocument.documentElement) {
			try {
				tailwind.refresh(iframe.contentDocument);
				return;
			} catch (e) {
				/* Older Tailwind CDN may not accept a Document argument. */
			}
		}
		try {
			tailwind.refresh();
		} catch (e2) {
			/* ignore */
		}
	}

	if (typeof wp !== 'undefined' && wp.domReady) {
		wp.domReady(function () {
			setTimeout(refresh, 100);
			setTimeout(refresh, 600);
			setTimeout(refresh, 2500);
		});
	}

	if (typeof wp !== 'undefined' && wp.data && typeof wp.data.subscribe === 'function') {
		var timer;
		wp.data.subscribe(function () {
			clearTimeout(timer);
			timer = setTimeout(refresh, 400);
		});
	}
})();
