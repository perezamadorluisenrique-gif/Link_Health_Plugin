/* Native Link Health – post editor meta box. */
(function () {
	'use strict';

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.nlh-scan-post-btn');
		if (!btn) return;

		var wrap   = btn.closest('#nlh-meta-box-wrap');
		var status = wrap ? wrap.querySelector('.nlh-scan-post-status') : null;
		var postId = btn.dataset.postId;

		btn.disabled    = true;
		btn.textContent = nlh_meta_box.i18n.scanning;
		if (status) status.style.display = 'none';

		var body = new URLSearchParams();
		body.append('action',  'nlh_scan_post');
		body.append('nonce',   nlh_meta_box.nonce);
		body.append('post_id', postId);

		fetch(nlh_meta_box.ajax_url, {
			method:      'POST',
			credentials: 'same-origin',
			body:        body
		})
		.then(function (r) { return r.json(); })
		.then(function (resp) {
			btn.disabled    = false;
			btn.textContent = nlh_meta_box.i18n.scanNow;
			if (!status) return;
			status.style.display = 'inline';
			if (resp.success) {
				status.style.color   = '#46b450';
				status.textContent   = nlh_meta_box.i18n.done;
			} else {
				status.style.color   = '#dc3232';
				status.textContent   = resp.data && resp.data.message
					? resp.data.message
					: nlh_meta_box.i18n.error;
			}
		})
		.catch(function () {
			btn.disabled    = false;
			btn.textContent = nlh_meta_box.i18n.scanNow;
			if (!status) return;
			status.style.display = 'inline';
			status.style.color   = '#dc3232';
			status.textContent   = nlh_meta_box.i18n.error;
		});
	});
}());
