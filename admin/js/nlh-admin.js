(function () {
	'use strict';

	// Wall-clock start of the active full scan, used to estimate time remaining.
	var scanStartedAt = 0;

	function formatDuration(seconds) {
		seconds = Math.max(0, Math.round(seconds));

		if (seconds < 60) {
			return seconds + 's';
		}

		var minutes = Math.floor(seconds / 60);
		var rem = seconds % 60;

		if (minutes < 60) {
			return rem ? minutes + 'm ' + rem + 's' : minutes + 'm';
		}

		var hours = Math.floor(minutes / 60);
		return hours + 'h ' + (minutes % 60) + 'm';
	}

	function ajaxPost(data, timeoutMs) {
		timeoutMs = timeoutMs || 120000; // default 2 minutes per chunk.

		var controller = new AbortController();
		var timer = window.setTimeout(function () {
			controller.abort();
		}, timeoutMs);

		return fetch(nlh_ajax.url, {
			method: 'POST',
			credentials: 'same-origin',
			signal: controller.signal,
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: new URLSearchParams(data).toString()
		}).then(function (response) {
			window.clearTimeout(timer);
			return response.json().then(function (json) {
				if (!response.ok || !json.success) {
					var message = json && json.data && json.data.message ? json.data.message : nlh_ajax.i18n.error;
					throw new Error(message);
				}

				return json.data || {};
			});
		}).catch(function (error) {
			window.clearTimeout(timer);
			if (error.name === 'AbortError') {
				throw new Error(nlh_ajax.i18n.timeout);
			}
			throw error;
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

	function renderStatusBadge(statusCode, errorType) {
		var code = parseInt(statusCode, 10) || 0;
		var className = 'nlh-status-unknown';
		var label = nlh_ajax.i18n.unknown;
		var title = '';

		if (code >= 500) {
			className = 'nlh-status-5xx';
			label = String(code);
		} else if (code >= 400) {
			className = 'nlh-status-4xx';
			label = String(code);
		} else if (code > 0) {
			label = String(code);
		} else {
			// No HTTP status: show the classified transport error type and
			// explain via tooltip why there is no status code.
			var badges = nlh_ajax.i18n.transportBadges || {};
			var tips = nlh_ajax.i18n.transportTooltips || {};

			if (errorType && badges[errorType]) {
				className = 'nlh-status-conn';
				label = badges[errorType];
			}

			title = tips[errorType] || tips.unknown || '';
		}

		var titleAttr = title ? ' title="' + escapeHtml(title) + '"' : '';

		return '<span class="nlh-status-badge ' + className + '"' + titleAttr + '>' + escapeHtml(label) + '</span>';
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

		if (typeof data.error_type !== 'undefined') {
			row.dataset.errorType = data.error_type;
		}

		if (urlLink) {
			urlLink.href = url;
			urlLink.title = url;
			urlLink.textContent = shortenUrl(url);
		}

		if (statusBadge) {
			statusBadge.outerHTML = renderStatusBadge(data.status_code, data.error_type);
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
		var search = document.getElementById('nlh-filter-search');
		var activeRegression = document.querySelector('.nlh-regression-filter-btn.current');
		var regressionFilter = activeRegression ? activeRegression.dataset.regressionFilter : 'all';
		var errorValue = errorType ? errorType.value : 'all';
		var searchValue = search ? search.value.toLowerCase() : '';

		document.querySelectorAll('.nlh-link-card').forEach(function (card) {
			var postTitle = (card.dataset.postTitle || '').toLowerCase();
			var url = (card.dataset.url || '').toLowerCase();
			var visible = true;

			if (errorValue !== 'all' && card.dataset.errorType !== errorValue) {
				visible = false;
			}

			if (searchValue && url.indexOf(searchValue) === -1 && postTitle.indexOf(searchValue) === -1) {
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
			var visibleCards = Array.prototype.filter.call(group.querySelectorAll('.nlh-link-card'), function (card) {
				return !card.hidden;
			});
			var hasVisibleCards = visibleCards.length > 0;

			group.hidden = !hasVisibleCards;

			// Update group header count for chronological groups.
			var header = group.querySelector('.nlh-group-header');
			if (header) {
				var countEl = header.querySelector('strong');
				if (countEl) {
					var label = visibleCards.length === 1 ? '1 ' + nlh_ajax.i18n.chronoLink : visibleCards.length + ' ' + nlh_ajax.i18n.chronoLinks;
					countEl.textContent = label;
				}
			}
		});
	}

	function getTimeBucketLabel(bucket) {
		var labels = {
			'Today': nlh_ajax.i18n.chronoToday || 'Today',
			'Yesterday': nlh_ajax.i18n.chronoYesterday || 'Yesterday',
			'This Week': nlh_ajax.i18n.chronoThisWeek || 'This Week',
			'Last Week': nlh_ajax.i18n.chronoLastWeek || 'Last Week',
			'This Month': nlh_ajax.i18n.chronoThisMonth || 'This Month',
			'Older': nlh_ajax.i18n.chronoOlder || 'Older'
		};
		return labels[bucket] || bucket;
	}

	function computeTimeBucket(discoveredYmd) {
		if (!discoveredYmd) return 'Older';

		var parts = discoveredYmd.split('-');
		if (parts.length !== 3) return 'Older';

		var discovered = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));

		// Use server-provided today date to avoid timezone mismatches.
		var serverToday = nlh_ajax.serverToday || '';
		var todayParts = serverToday ? serverToday.split('-') : [];
		var today;
		if (todayParts.length === 3) {
			today = new Date(parseInt(todayParts[0], 10), parseInt(todayParts[1], 10) - 1, parseInt(todayParts[2], 10));
		} else {
			var now = new Date();
			today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
		}

		var diffTime = today.getTime() - discovered.getTime();
		var diffDays = Math.round(diffTime / 86400000);

		if (diffDays < 0) return 'Today';
		if (diffDays === 0) return 'Today';
		if (diffDays === 1) return 'Yesterday';

		// Get this week's Monday.
		var dayOfWeek = today.getDay();
		var mondayOffset = dayOfWeek === 0 ? -6 : 1 - dayOfWeek;
		var thisMonday = new Date(today);
		thisMonday.setDate(today.getDate() + mondayOffset);

		// Get last week's Monday.
		var lastMonday = new Date(thisMonday);
		lastMonday.setDate(thisMonday.getDate() - 7);

		// Get this month's first day.
		var monthStart = new Date(today.getFullYear(), today.getMonth(), 1);

		if (discovered >= thisMonday) return 'This Week';
		if (discovered >= lastMonday) return 'Last Week';
		if (discovered >= monthStart) return 'This Month';
		return 'Older';
	}

	function applyChronologicalGrouping() {
		var container = document.querySelector('.nlh-groups-container');
		if (!container) {
			container = document.querySelector('.nlh-cards-container');
			if (!container) return;
		}

		// Get all link cards (visible and hidden) from the container.
		var allCards = Array.from(container.querySelectorAll('.nlh-link-card'));
		if (!allCards.length) return;

		// Compute buckets.
		var buckets = {};
		var bucketOrder = ['Today', 'Yesterday', 'This Week', 'Last Week', 'This Month', 'Older'];

		allCards.forEach(function (card) {
			var bucket = computeTimeBucket(card.dataset.discovered);
			if (!buckets[bucket]) {
				buckets[bucket] = [];
			}
			buckets[bucket].push(card);
		});

		// Sort within each bucket by discovered_at descending.
		bucketOrder.forEach(function (bucket) {
			if (buckets[bucket]) {
				buckets[bucket].sort(function (a, b) {
					return (b.dataset.discovered || '').localeCompare(a.dataset.discovered || '');
				});
			}
		});

		// Build group HTML.
		var html = '';
		bucketOrder.forEach(function (bucket) {
			var items = buckets[bucket];
			if (!items || !items.length) return;

			var itemsHtml = items.map(function (card) {
				return card.outerHTML;
			}).join('');

			html += '<div class="nlh-group" aria-expanded="true">';
			html += '<button type="button" class="nlh-group-header nlh-group-toggle" aria-expanded="true">';
			html += '<span>' + escapeHtml(getTimeBucketLabel(bucket)) + '</span>';
			var countLabel = items.length === 1 ? '1 ' + nlh_ajax.i18n.chronoLink : items.length + ' ' + nlh_ajax.i18n.chronoLinks;
			html += '<strong>' + escapeHtml(countLabel) + '</strong>';
			html += '</button>';
			html += '<div class="nlh-group-items">' + itemsHtml + '</div>';
			html += '</div>';
		});

		// If no cards fell into any bucket, don't touch the container.
		if (!html) return;

		container.innerHTML = html;

		// Hide empty state if present.
		var emptyState = document.querySelector('.nlh-empty-state');
		if (emptyState) {
			emptyState.hidden = true;
		}

		// Ensure container uses groups-container styling.
		container.classList.remove('nlh-cards-container');
		container.classList.add('nlh-groups-container');

		// Update group visibility based on filtered state.
		updateGroupVisibility();

		// Hide bottom pagination — chronological grouping is client-side.
		var pagination = document.querySelector('.tablenav.bottom');
		if (pagination) {
			pagination.style.display = 'none';
		}
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

		var label = nlh_ajax.i18n.progress.replace('%1$d', scanned).replace('%2$d', total);

		// Estimate time remaining from the average per-post rate so far. Only shown
		// once there is a real sample and while work remains.
		if (scanStartedAt && scanned > 0 && total > 0 && !result.done && nlh_ajax.i18n.eta) {
			var elapsed = (Date.now() - scanStartedAt) / 1000;
			var perPost = elapsed / scanned;
			var remaining = perPost * (total - scanned);

			if (isFinite(remaining) && remaining > 0) {
				label += ' ' + nlh_ajax.i18n.eta.replace('%s', formatDuration(remaining));
			}
		}

		text.textContent = label;
	}

	function runScanChunk(mode, offset, button) {
		return ajaxPost({
			action: 'nlh_run_now',
			nonce: nlh_ajax.runNowNonce,
			mode: mode,
			offset: offset || 0
		}).then(function (data) {
			setProgress(data);

			// Persist offset for potential resume on failure.
			if (mode === 'full') {
				try {
					sessionStorage.setItem('nlh_scan_offset', data.next);
				} catch (e) {}
			}

			if (mode === 'full' && !data.done) {
				return runScanChunk(mode, data.next, button);
			}

			// Scan complete — clear stored offset.
			try {
				sessionStorage.removeItem('nlh_scan_offset');
			} catch (e) {}

			showNotice(data.message || nlh_ajax.i18n.scanQueued, 'success');
			window.setTimeout(function () {
				window.location.reload();
			}, 700);
			return data;
		}).catch(function (error) {
			var msg;
			if (mode === 'full' && offset > 0) {
				msg = nlh_ajax.i18n.scanError.replace('%1$d', offset).replace('%2$s', error.message);
			} else {
				msg = error.message;
			}
			showNotice(msg, 'error');
			throw error; // Let caller know the scan failed.
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
		if (event.target.closest('#nlh-filter-search')) {
			applyDashboardFilters();
		}
	});

	document.addEventListener('change', function (event) {
		var groupBy = event.target.closest('#nlh-group-by');

		if (event.target.closest('#nlh-filter-error-type')) {
			applyDashboardFilters();
		}

		if (groupBy) {
			if (groupBy.value === 'chronological') {
				// Client-side chronological grouping — no page reload needed.
				var url = new URL(window.location.href);
				url.searchParams.set('nlh_group_by', 'chronological');
				url.searchParams.delete('paged');
				window.history.replaceState({}, '', url.toString());
				applyChronologicalGrouping();
				return;
			}

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
				button.setAttribute('aria-pressed', 'false');
			});
			regressionButton.classList.add('current');
			regressionButton.setAttribute('aria-pressed', 'true');
			if (window.history && window.history.replaceState) {
				var filterUrl = new URL(window.location.href);
				filterUrl.searchParams.set('nlh_filter', regressionButton.dataset.regressionFilter);
				window.history.replaceState({}, '', filterUrl.toString());
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
				timelineButton.setAttribute('aria-expanded', 'false');
				return;
			}

			wrapper.hidden = false;
			timelineButton.textContent = nlh_ajax.i18n.hideHistory;
			timelineButton.setAttribute('aria-expanded', 'true');

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
				timelineButton.setAttribute('aria-expanded', 'true');
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

			// Clear any stale resume offset on a fresh scan.
			try { sessionStorage.removeItem('nlh_scan_offset'); } catch (e) {}

			scanStartedAt = Date.now();
			setButtonBusy(button, true);
			runScanChunk(mode, 0, button).finally(function () {
				setButtonBusy(button, false);
			});
		}
	});

	applyDashboardFilters();

	// Apply chronological grouping on load if that mode is active.
	var urlParams = new URLSearchParams(window.location.search);
	if (urlParams.get('nlh_group_by') === 'chronological') {
		applyChronologicalGrouping();
	}

	// Resume a previously interrupted scan if sessionStorage has an offset.
	(function checkResumeScan() {
		var storedOffset;
		try { storedOffset = sessionStorage.getItem('nlh_scan_offset'); } catch (e) {}
		if (!storedOffset) return;

		var offset = parseInt(storedOffset, 10);
		if (isNaN(offset) || offset <= 0) return;

		var runNowForm = document.getElementById('nlh-run-now-form');
		if (!runNowForm) return;

		var button = runNowForm.querySelector('button[type="submit"]');
		if (!button) return;

		// Ask user before resuming.
		if (!window.confirm(
			nlh_ajax.i18n.resumeScan
				.replace('%d', String(offset))
		)) {
			try { sessionStorage.removeItem('nlh_scan_offset'); } catch (e) {}
			return;
		}

		scanStartedAt = Date.now();
		setButtonBusy(button, true);
		var modeField = runNowForm.querySelector('[name="scan_mode"]');
		var mode = modeField ? modeField.value : 'full';
		runScanChunk(mode, offset, button).finally(function () {
			setButtonBusy(button, false);
		});
	})();
}());
