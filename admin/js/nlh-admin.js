(function () {
	'use strict';

	function ajaxPost(data) {
		return fetch(nlh_ajax.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: new URLSearchParams(data).toString()
		}).then(function (response) {
			return response.json().then(function (json) {
				if (!response.ok || !json.success) {
					var message = json && json.data && json.data.message ? json.data.message : nlh_ajax.i18n.error;
					throw new Error(message);
				}

				return json.data || {};
			});
		});
	}

	function escapeHtml(value) {
		return String(value || '').replace(/[&<>"']/g, function (char) {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			}[char];
		});
	}

	function showNotice(message, type) {
		var notice = document.getElementById('nlh-admin-notice');

		if (!notice) {
			return;
		}

		notice.textContent = message;
		notice.className = 'notice nlh-notice is-' + (type || 'success');
		notice.hidden = false;
	}

	function getDataRow(element) {
		return element ? element.closest('.nlh-link-card, .nlh-link-row') : null;
	}

	function removeDataAndEditRows(row) {
		if (!row) {
			return;
		}

		if (row.classList.contains('nlh-link-card')) {
			row.remove();
			updateGroupVisibility();
			return;
		}

		var editRow = row.nextElementSibling;

		if (editRow && editRow.classList.contains('nlh-inline-edit-row')) {
			editRow.remove();
		}

		row.remove();
	}

	function renderStatusBadge(statusCode) {
		var code = parseInt(statusCode, 10) || 0;
		var className = 'nlh-status-unknown';
		var label = code > 0 ? String(code) : nlh_ajax.i18n.unknown;

		if (code >= 400 && code < 500) {
			className = 'nlh-status-4xx';
		} else if (code >= 500) {
			className = 'nlh-status-5xx';
		}

		return '<span class="nlh-status-badge ' + className + '">' + escapeHtml(label) + '</span>';
	}

	function shortenUrl(url) {
		if (!url || url.length <= 72) {
			return url || '';
		}

		return url.substring(0, 72) + '...';
	}

	function updateRowAfterBrokenCheck(row, data) {
		var url = data.url || row.dataset.url;
		var statusBadge = row.querySelector('.nlh-status-badge');
		var errorCell = row.querySelector('.nlh-error-cell');
		var urlLink = row.querySelector('.nlh-url-link');

		row.dataset.url = url;

		if (data.record_id) {
			row.dataset.recordId = data.record_id;
		}

		if (urlLink) {
			urlLink.href = url;
			urlLink.title = url;
			urlLink.textContent = shortenUrl(url);
		}

		if (statusBadge) {
			statusBadge.outerHTML = renderStatusBadge(data.status_code);
		}

		if (errorCell) {
			errorCell.textContent = data.error || '';
		}

		var inlineEdit = row.querySelector('.nlh-inline-edit-row');

		if (!inlineEdit && row.nextElementSibling && row.nextElementSibling.classList.contains('nlh-inline-edit-row')) {
			inlineEdit = row.nextElementSibling;
		}

		if (inlineEdit) {
			var recordField = inlineEdit.querySelector('[name="record_id"]');
			var oldUrlField = inlineEdit.querySelector('[name="old_url"]');
			var newUrlField = inlineEdit.querySelector('[name="new_url"]');

			if (recordField && data.record_id) {
				recordField.value = data.record_id;
			}

			if (oldUrlField) {
				oldUrlField.value = url;
			}

			if (newUrlField) {
				newUrlField.value = url;
			}
		}
	}

	function setButtonBusy(el, busy) {
		if (!el) {
			return;
		}

		if (busy) {
			el.dataset.originalText = el.textContent;
			el.textContent = nlh_ajax.i18n.working;
			if (el.tagName === 'BUTTON') {
				el.disabled = true;
			} else {
				el.style.pointerEvents = 'none';
				el.style.opacity = '0.6';
			}
		} else {
			el.textContent = el.dataset.originalText || el.textContent;
			if (el.tagName === 'BUTTON') {
				el.disabled = false;
			} else {
				el.style.pointerEvents = '';
				el.style.opacity = '';
			}
		}
	}

	function applyDashboardFilters() {
		var errorType = document.getElementById('nlh-filter-error-type');
		var postText = document.getElementById('nlh-filter-post');
		var search = document.getElementById('nlh-filter-search');
		var activeRegression = document.querySelector('.nlh-regression-filter-btn.current');
		var regressionFilter = activeRegression ? activeRegression.dataset.regressionFilter : 'all';
		var errorValue = errorType ? errorType.value : 'all';
		var postValue = postText ? postText.value.toLowerCase() : '';
		var searchValue = search ? search.value.toLowerCase() : '';
		var fromValue = '';
		var toValue = '';
		var activeDatePreset = document.querySelector('.nlh-date-preset-btn.current');
		if (activeDatePreset) {
			var preset = activeDatePreset.dataset.preset;
			if (preset !== 'all') {
				var now = new Date();
				var from;
				switch (preset) {
					case '7d':
						from = new Date(now);
						from.setDate(from.getDate() - 7);
						break;
					case '30d':
						from = new Date(now);
						from.setDate(from.getDate() - 30);
						break;
					case '90d':
						from = new Date(now);
						from.setDate(from.getDate() - 90);
						break;
					case 'month':
						from = new Date(now.getFullYear(), now.getMonth(), 1);
						break;
				}
				if (from) {
					fromValue = from.getFullYear() + '-' + String(from.getMonth() + 1).padStart(2, '0') + '-' + String(from.getDate()).padStart(2, '0');
					toValue = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
				}
			}
		}

		document.querySelectorAll('.nlh-link-card').forEach(function (card) {
			var postTitle = (card.dataset.postTitle || '').toLowerCase();
			var url = (card.dataset.url || '').toLowerCase();
			var discovered = card.dataset.discovered || '';
			var visible = true;

			if (errorValue !== 'all' && card.dataset.errorType !== errorValue) {
				visible = false;
			}

			if (postValue && postTitle.indexOf(postValue) === -1) {
				visible = false;
			}

			if (searchValue && url.indexOf(searchValue) === -1 && postTitle.indexOf(searchValue) === -1) {
				visible = false;
			}

			if (fromValue && discovered < fromValue) {
				visible = false;
			}

			if (toValue && discovered > toValue) {
				visible = false;
			}

			if (regressionFilter === 'new' && card.dataset.regression === '1') {
				visible = false;
			}

			if (regressionFilter === 'regression' && card.dataset.regression !== '1') {
				visible = false;
			}

			card.hidden = !visible;
		});

		updateGroupVisibility();
	}

	function updateGroupVisibility() {
		document.querySelectorAll('.nlh-group').forEach(function (group) {
			var hasVisibleCards = Array.prototype.some.call(group.querySelectorAll('.nlh-link-card'), function (card) {
				return !card.hidden;
			});

			group.hidden = !hasVisibleCards;
		});
	}

	function setProgress(result) {
		var progress = document.querySelector('.nlh-scan-progress');
		var fill = document.querySelector('.nlh-progress-bar-fill');
		var text = document.querySelector('.nlh-progress-text');

		if (!progress || !fill || !text) {
			return;
		}

		var total = parseInt(result.total, 10) || 0;
		var scanned = parseInt(result.scanned, 10) || 0;
		var percent = total > 0 ? Math.min(100, Math.round((scanned / total) * 100)) : 100;

		progress.hidden = false;
		fill.style.width = percent + '%';
		text.textContent = nlh_ajax.i18n.progress.replace('%1$d', scanned).replace('%2$d', total);
	}

	function runScanChunk(mode, offset, button) {
		return ajaxPost({
			action: 'nlh_run_now',
			nonce: nlh_ajax.runNowNonce,
			mode: mode,
			offset: offset || 0
		}).then(function (data) {
			setProgress(data);

			if (mode === 'full' && !data.done) {
				return runScanChunk(mode, data.next, button);
			}

			showNotice(data.message || nlh_ajax.i18n.scanQueued, 'success');
			window.setTimeout(function () {
				window.location.reload();
			}, 700);
			return data;
		}).catch(function (error) {
			showNotice(error.message, 'error');
		}).finally(function () {
			if (mode !== 'full' || offset === 0) {
				setButtonBusy(button, false);
			}
		});
	}

	function renderTimeline(timeline, events) {
		if (!events.length) {
			timeline.innerHTML = '<div class="nlh-timeline-empty">' + escapeHtml(nlh_ajax.i18n.noHistory) + '</div>';
			return;
		}

		var labels = {
			broken: nlh_ajax.i18n.eventBroken,
			fixed: nlh_ajax.i18n.eventFixed,
			regression: nlh_ajax.i18n.eventRegression,
			ignored: nlh_ajax.i18n.eventIgnored
		};

		timeline.innerHTML = events.map(function (event) {
			var type = event.event_type || '';
			var label = labels[type] || type;
			var status = event.status_code ? 'HTTP ' + event.status_code : '';

			return '<div class="nlh-timeline-event nlh-event-' + escapeHtml(type) + '">' +
				'<span class="nlh-event-dot" aria-hidden="true"></span>' +
				'<span class="nlh-event-label">' + escapeHtml(label) + (status ? ' ' + escapeHtml(status) : '') + '</span>' +
				'<span class="nlh-event-date">' + escapeHtml(event.event_at || '') + '</span>' +
			'</div>';
		}).join('');
	}

	function renderSeoResults(container, results) {
		var titles = {
			orphan_pages: nlh_ajax.i18n.seoOrphanPages,
			redirect_chains: nlh_ajax.i18n.seoRedirectChains,
			mixed_content: nlh_ajax.i18n.seoMixedContent,
			invalid_canonicals: nlh_ajax.i18n.seoInvalidCanonicals,
			redundant_links: nlh_ajax.i18n.seoRedundantLinks
		};

		container.innerHTML = Object.keys(results).map(function (key) {
			var result = results[key] || {};
			var title = titles[key] || key;
			var items = Array.isArray(result.items) ? result.items : [];
			var itemHtml = items.slice(0, 10).map(function (item) {
				return '<li>' +
					(item.url ? '<a href="' + escapeHtml(item.url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(item.title || item.url) + '</a>' : escapeHtml(item.title || '')) +
					(item.detail ? '<span>' + escapeHtml(item.detail) + '</span>' : '') +
				'</li>';
			}).join('');

			return '<section class="nlh-seo-section nlh-seo-' + escapeHtml(result.status || 'info') + '">' +
				'<h3>' + escapeHtml(title) + '</h3>' +
				'<p>' + escapeHtml(result.message || '') + '</p>' +
				(itemHtml ? '<ul>' + itemHtml + '</ul>' : '') +
			'</section>';
		}).join('');
	}

	document.addEventListener('input', function (event) {
		if (event.target.closest('#nlh-filter-error-type, #nlh-filter-post, #nlh-filter-search')) {
			applyDashboardFilters();
		}
	});

	document.addEventListener('change', function (event) {
		var groupBy = event.target.closest('#nlh-group-by');

		if (event.target.closest('#nlh-filter-error-type')) {
			applyDashboardFilters();
		}

		if (groupBy) {
			var url = new URL(window.location.href);
			url.searchParams.set('nlh_group_by', groupBy.value);
			url.searchParams.delete('paged');
			window.location.href = url.toString();
		}
	});

	document.addEventListener('click', function (event) {
		var editToggle = event.target.closest('.nlh-edit-toggle');
		var cancelEdit = event.target.closest('.nlh-cancel-edit');
		var recheck = event.target.closest('.nlh-recheck-url');
		var ignore = event.target.closest('.nlh-ignore-url');
		var groupToggle = event.target.closest('.nlh-group-toggle');
		var regressionButton = event.target.closest('.nlh-regression-filter-btn');
		var approveAll = event.target.closest('.nlh-approve-all');
		var timelineButton = event.target.closest('.nlh-toggle-timeline');
		var presetButton = event.target.closest('[data-preset]');
		var seoButton = event.target.closest('#nlh-run-seo-audit');

		if (editToggle) {
			event.preventDefault();
			var row = getDataRow(editToggle);
			var inlineEdit = row ? row.querySelector('.nlh-inline-edit-row') : null;

			if (!inlineEdit && row && row.nextElementSibling && row.nextElementSibling.classList.contains('nlh-inline-edit-row')) {
				inlineEdit = row.nextElementSibling;
			}

			if (inlineEdit) {
				inlineEdit.hidden = !inlineEdit.hidden;
			}
		}

		if (cancelEdit) {
			var inlineRow = cancelEdit.closest('.nlh-inline-edit-row');

			if (inlineRow) {
				inlineRow.hidden = true;
			}
		}

		if (groupToggle) {
			var group = groupToggle.closest('.nlh-group');
			var expanded = groupToggle.getAttribute('aria-expanded') !== 'false';

			groupToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
			if (group) {
				group.setAttribute('aria-expanded', expanded ? 'false' : 'true');
			}
		}

		if (regressionButton) {
			document.querySelectorAll('.nlh-regression-filter-btn').forEach(function (button) {
				button.classList.remove('current');
			});
			regressionButton.classList.add('current');
			if (window.history && window.history.replaceState) {
				var filterUrl = new URL(window.location.href);
				filterUrl.searchParams.set('nlh_filter', regressionButton.dataset.regressionFilter);
				window.history.replaceState({}, '', filterUrl.toString());
			}
			applyDashboardFilters();
		}

		if (presetButton) {
			event.preventDefault();
			var wasActive = presetButton.classList.contains('current');
			document.querySelectorAll('.nlh-date-preset-btn').forEach(function (btn) {
				btn.classList.remove('current');
			});
			if (!wasActive) {
				presetButton.classList.add('current');
			}
			applyDashboardFilters();
		}

		if (recheck) {
			event.preventDefault();
			var recheckRow = getDataRow(recheck);

			if (!recheckRow) {
				return;
			}

			setButtonBusy(recheck, true);

			ajaxPost({
				action: 'nlh_recheck_url',
				nonce: nlh_ajax.nonce,
				url: recheckRow.dataset.url,
				post_id: recheckRow.dataset.postId,
				record_id: recheckRow.dataset.recordId
			}).then(function (data) {
				if (data.status === 'ok') {
					removeDataAndEditRows(recheckRow);
					return;
				}

				if (data.status === 'rate_limited') {
					showNotice(data.error || nlh_ajax.i18n.error, 'error');
					return;
				}

				updateRowAfterBrokenCheck(recheckRow, data);
			}).catch(function (error) {
				showNotice(error.message, 'error');
			}).finally(function () {
				setButtonBusy(recheck, false);
			});
		}

		if (ignore) {
			event.preventDefault();
			var ignoreRow = getDataRow(ignore);

			if (!ignoreRow || !window.confirm(nlh_ajax.i18n.confirmIgnore)) {
				return;
			}

			setButtonBusy(ignore, true);

			ajaxPost({
				action: 'nlh_ignore_url',
				nonce: nlh_ajax.nonce,
				url: ignoreRow.dataset.url,
				post_id: ignoreRow.dataset.postId,
				record_id: ignoreRow.dataset.recordId
			}).then(function () {
				removeDataAndEditRows(ignoreRow);
			}).catch(function (error) {
				showNotice(error.message, 'error');
			}).finally(function () {
				setButtonBusy(ignore, false);
			});
		}

		if (approveAll) {
			var suggestion = approveAll.closest('.nlh-suggestion-card');
			var replacement = suggestion ? suggestion.querySelector('.nlh-suggestion-replacement') : null;

			setButtonBusy(approveAll, true);

			ajaxPost({
				action: 'nlh_bulk_correct',
				nonce: nlh_ajax.nonce,
				pattern: suggestion ? suggestion.dataset.pattern : '',
				type: suggestion ? suggestion.dataset.type : '',
				replacement: replacement ? replacement.value : ''
			}).then(function (data) {
				if (suggestion) {
					suggestion.remove();
				}
				showNotice(data.message || nlh_ajax.i18n.scanQueued, 'success');
			}).catch(function (error) {
				showNotice(error.message, 'error');
			}).finally(function () {
				setButtonBusy(approveAll, false);
			});
		}

		if (timelineButton) {
			var card = getDataRow(timelineButton);
			var wrapper = card ? card.querySelector('.nlh-timeline-wrapper') : null;
			var timeline = wrapper ? wrapper.querySelector('.nlh-timeline') : null;

			if (!wrapper || !timeline) {
				return;
			}

			if (!wrapper.hidden) {
				wrapper.hidden = true;
				timelineButton.textContent = nlh_ajax.i18n.showHistory;
				return;
			}

			wrapper.hidden = false;
			timelineButton.textContent = nlh_ajax.i18n.hideHistory;

			if (timeline.dataset.loaded === '1') {
				return;
			}

			setButtonBusy(timelineButton, true);

			ajaxPost({
				action: 'nlh_get_timeline',
				nonce: nlh_ajax.nonce,
				url_hash: timeline.dataset.urlHash,
				post_id: timeline.dataset.postId
			}).then(function (events) {
				renderTimeline(timeline, Array.isArray(events) ? events : []);
				timeline.dataset.loaded = '1';
				timelineButton.textContent = nlh_ajax.i18n.hideHistory;
			}).catch(function (error) {
				showNotice(error.message, 'error');
			}).finally(function () {
				setButtonBusy(timelineButton, false);
			});
		}

		if (seoButton) {
			var seoContainer = document.getElementById('nlh-seo-results');

			setButtonBusy(seoButton, true);
			if (seoContainer) {
				seoContainer.innerHTML = '<p>' + escapeHtml(nlh_ajax.i18n.seoRunning) + '</p>';
			}

			ajaxPost({
				action: 'nlh_run_seo_audit',
				nonce: nlh_ajax.nonce
			}).then(function (results) {
				if (seoContainer) {
					renderSeoResults(seoContainer, results);
				}
				showNotice(nlh_ajax.i18n.auditComplete, 'success');
			}).catch(function (error) {
				showNotice(error.message, 'error');
			}).finally(function () {
				setButtonBusy(seoButton, false);
			});
		}
	});

	document.addEventListener('submit', function (event) {
		var editForm = event.target.closest('.nlh-inline-edit-form');
		var runNowForm = event.target.closest('#nlh-run-now-form');

		if (editForm) {
			event.preventDefault();

			var row = getDataRow(editForm);
			var submit = editForm.querySelector('button[type="submit"]');
			var formData = new FormData(editForm);

			setButtonBusy(submit, true);

			ajaxPost({
				action: 'nlh_correct_url',
				nonce: nlh_ajax.nonce,
				post_id: formData.get('post_id'),
				record_id: formData.get('record_id'),
				old_url: formData.get('old_url'),
				new_url: formData.get('new_url')
			}).then(function (data) {
				if (data.status === 'ok') {
					removeDataAndEditRows(row);
					return;
				}

				if (data.status === 'rate_limited') {
					showNotice(data.error || nlh_ajax.i18n.error, 'error');
					return;
				}

				updateRowAfterBrokenCheck(row, data);
				showNotice(data.message || nlh_ajax.i18n.scanQueued, 'success');
			}).catch(function (error) {
				showNotice(error.message, 'error');
			}).finally(function () {
				setButtonBusy(submit, false);
			});
		}

		if (runNowForm) {
			event.preventDefault();

			var button = runNowForm.querySelector('button[type="submit"]');
			var modeField = runNowForm.querySelector('[name="scan_mode"]');
			var mode = modeField ? modeField.value : 'quick';

			setButtonBusy(button, true);
			runScanChunk(mode, 0, button);
		}
	});

	applyDashboardFilters();
}());
