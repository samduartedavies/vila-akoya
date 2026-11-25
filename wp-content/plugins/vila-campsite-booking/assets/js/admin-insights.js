(function () {
	'use strict';

	var currency = '€';
	var palette = ['#2271b1', '#46b450', '#dba617', '#d63638', '#8c5bd1', '#00a0a5', '#e26f56', '#72777c', '#1d2327', '#9ec2e6', '#b8e6bf', '#f5e6ab'];

	function money(value) {
		return currency + Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
	}

	function canvas(id) {
		return document.getElementById('vila-cb-chart-' + id);
	}

	function emptyMessage(el) {
		if (!el || el.dataset.vilaEmpty) {
			return;
		}
		el.dataset.vilaEmpty = '1';
		el.style.display = 'none';
		var p = document.createElement('p');
		p.className = 'description vila-cb-insights-empty';
		p.textContent = 'No data for this range yet.';
		el.parentNode.appendChild(p);
	}

	function hasValues(datasets) {
		return datasets.some(function (ds) {
			return (ds.data || []).some(function (v) { return Number(v) > 0; });
		});
	}

	function chart(id, type, labels, datasets, options) {
		var el = canvas(id);
		if (!el || typeof window.Chart === 'undefined') {
			return;
		}
		if (!labels || !labels.length || !hasValues(datasets)) {
			emptyMessage(el);
			return;
		}
		datasets.forEach(function (ds, i) {
			if (!ds.backgroundColor) {
				ds.backgroundColor = type === 'doughnut' ? palette.slice(0, labels.length) : palette[i % palette.length];
			}
			if (type === 'line' && !ds.borderColor) {
				ds.borderColor = palette[i % palette.length];
			}
		});
		var isMoney = datasets.some(function (ds) { return ds.vilaMoney; });
		var base = {
			responsive: true,
			maintainAspectRatio: false,
			plugins: {
				legend: { position: 'bottom', display: type === 'doughnut' || datasets.length > 1 },
				tooltip: {
					callbacks: {
						label: function (ctx) {
							var raw = ctx.raw;
							var label = ctx.dataset.label ? ctx.dataset.label + ': ' : '';
							if (type === 'doughnut') {
								label = ctx.label + ': ';
							}
							return label + (ctx.dataset.vilaMoney ? money(raw) : raw);
						}
					}
				}
			}
		};
		if (type !== 'doughnut') {
			var valueAxis = { beginAtZero: true, ticks: {} };
			if (isMoney) {
				valueAxis.ticks.callback = function (v) { return money(v); };
			}
			base.scales = options && options.indexAxis === 'y' ? { x: valueAxis } : { y: valueAxis };
		}
		var merged = Object.assign(base, options || {});
		if (options && options.scales && base.scales) {
			Object.keys(options.scales).forEach(function (axis) {
				merged.scales[axis] = Object.assign({}, base.scales[axis] || {}, options.scales[axis]);
			});
		}
		new window.Chart(el, { type: type, data: { labels: labels, datasets: datasets }, options: merged });
	}

	function amountList(rows) {
		rows = rows || [];
		return {
			labels: rows.map(function (row) { return row.label; }),
			values: rows.map(function (row) { return Number(row.amount || 0); })
		};
	}

	function countList(rows) {
		rows = rows || [];
		return {
			labels: rows.map(function (row) { return row.label; }),
			values: rows.map(function (row) { return Number(row.count || 0); })
		};
	}

	function groupSeries(series) {
		if (series.length <= 62) {
			return series;
		}
		var byMonth = {};
		var order = [];
		series.forEach(function (row) {
			var key = String(row.label).slice(0, 7);
			if (!byMonth[key]) {
				byMonth[key] = { label: key, paid_online: 0, paid_on_site: 0, unpaid: 0 };
				order.push(key);
			}
			byMonth[key].paid_online += Number(row.paid_online || 0);
			byMonth[key].paid_on_site += Number(row.paid_on_site || 0);
			byMonth[key].unpaid += Number(row.unpaid || 0);
		});
		return order.map(function (k) { return byMonth[k]; });
	}

	function renderCharts(data) {
		currency = data.currency || '€';
		var series = groupSeries(data.series || []);
		var stacked = { x: { stacked: true }, y: { stacked: true } };

		chart('incomeOverTime', 'bar', series.map(function (r) { return r.label; }), [
			{ label: 'Paid online', data: series.map(function (r) { return r.paid_online || 0; }), vilaMoney: true, backgroundColor: '#2271b1' },
			{ label: 'Paid on site', data: series.map(function (r) { return r.paid_on_site || 0; }), vilaMoney: true, backgroundColor: '#46b450' },
			{ label: 'Not paid yet', data: series.map(function (r) { return r.unpaid || 0; }), vilaMoney: true, backgroundColor: '#dba617' }
		], { scales: stacked });

		var payment = amountList(data.paymentMix);
		chart('paymentMix', 'doughnut', payment.labels, [{ label: 'Revenue', data: payment.values, vilaMoney: true }]);

		var onsite = amountList(data.onsiteMethods);
		chart('onsiteMethods', 'doughnut', onsite.labels, [{ label: 'Revenue', data: onsite.values, vilaMoney: true }]);

		var countries = amountList(data.byCountry);
		chart('countryRevenue', 'bar', countries.labels, [{ label: 'Revenue', data: countries.values, vilaMoney: true }], { indexAxis: 'y' });

		var countryPay = data.countryPayment || [];
		chart('countryPayment', 'bar', countryPay.map(function (r) { return r.label; }), [
			{ label: 'Paid', data: countryPay.map(function (r) { return r.paid || 0; }), vilaMoney: true, backgroundColor: '#46b450' },
			{ label: 'Not paid yet', data: countryPay.map(function (r) { return r.unpaid || 0; }), vilaMoney: true, backgroundColor: '#dba617' }
		], { indexAxis: 'y', scales: { x: { stacked: true }, y: { stacked: true } } });

		var types = amountList(data.typeRevenue);
		chart('typeRevenue', 'doughnut', types.labels, [{ label: 'Revenue', data: types.values, vilaMoney: true }]);

		var units = amountList(data.unitTypes);
		chart('unitTypes', 'bar', units.labels, [{ label: 'Revenue', data: units.values, vilaMoney: true }], { indexAxis: 'y' });

		var lead = countList(data.leadTime);
		chart('leadTime', 'bar', lead.labels, [{ label: 'Bookings', data: lead.values }]);

		var stays = countList(data.stayLength);
		chart('stayLength', 'bar', stays.labels, [{ label: 'Bookings', data: stays.values }]);

		var weekday = countList(data.weekday);
		chart('weekday', 'bar', weekday.labels, [{ label: 'Bookings', data: weekday.values }]);

		var hour = countList(data.hour);
		chart('hour', 'bar', hour.labels, [{ label: 'Bookings', data: hour.values }]);

		var health = data.checkoutHealth || [];
		chart('checkoutHealth', 'bar', health.map(function (r) { return r.label; }), [
			{ label: 'Completed', data: health.map(function (r) { return r.completed || 0; }), backgroundColor: '#46b450' },
			{ label: 'Abandoned', data: health.map(function (r) { return r.abandoned || 0; }), backgroundColor: '#dba617' },
			{ label: 'Failed / cancelled', data: health.map(function (r) { return r.failed_cancelled || 0; }), backgroundColor: '#d63638' }
		], { scales: stacked });

		var returning = data.returning || [];
		chart('returningGuests', 'doughnut', returning.map(function (r) { return r.label; }), [{ label: 'Guests', data: returning.map(function (r) { return r.count || 0; }) }]);

		var due = data.upcomingDue || [];
		chart('upcomingDue', 'bar', due.map(function (r) { return r.label; }), [{ label: 'Due on arrival', data: due.map(function (r) { return r.due || 0; }), vilaMoney: true, backgroundColor: '#dba617' }]);
	}

	function setStatus(statusEl, state, text) {
		statusEl.className = 'vila-cb-ui-panel vila-cb-insights-status is-' + state;
		statusEl.querySelector('.vila-cb-insights-status__text').textContent = text;
		var spinner = statusEl.querySelector('.spinner');
		if (spinner) {
			spinner.classList.toggle('is-active', state === 'loading');
		}
	}

	function load() {
		var cfg = window.vilaCbInsightsConfig;
		var statusEl = document.getElementById('vila-cb-insights-status');
		var bodyEl = document.getElementById('vila-cb-insights-body');
		if (!cfg || !statusEl || !bodyEl) {
			return;
		}
		var timerEl = statusEl.querySelector('.vila-cb-insights-status__timer');
		var started = Date.now();
		var timer = window.setInterval(function () {
			timerEl.textContent = Math.round((Date.now() - started) / 1000) + 's';
		}, 500);

		var refresh = new URLSearchParams(window.location.search).get('refresh');
		var batch = 10;
		var retryable = [0, 429, 500, 502, 503, 504, 508];
		var maxRetries = 6;
		var progressText = 'Contacting server…';

		function wait(ms) {
			return new Promise(function (resolve) { window.setTimeout(resolve, ms); });
		}

		function request(job, ping, render) {
			var params = new URLSearchParams();
			params.append('action', cfg.action);
			params.append('nonce', cfg.nonce);
			Object.keys(cfg.filters || {}).forEach(function (key) {
				params.append(key, cfg.filters[key]);
			});
			if (refresh) {
				params.append('refresh', '1');
			}
			if (ping) {
				params.append('ping', '1');
				if (ping === 'diag') {
					params.append('diag_only', '1');
				}
			}
			if (render) {
				params.append('render', '1');
			}
			if (job) {
				params.append('job', job);
				params.append('batch', String(batch));
			}
			return fetch(cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: params.toString()
			}).then(function (res) {
				return res.text().then(function (text) {
					var json = null;
					var err;
					try {
						json = JSON.parse(text);
					} catch (e) {
						var snippet = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 400);
						err = new Error('Server returned HTTP ' + res.status + (snippet ? ': ' + snippet : ' with an empty response (likely a PHP timeout or memory limit).'));
						err.status = res.status;
						err.retryable = retryable.indexOf(res.status) !== -1;
						throw err;
					}
					if (!json || !json.success) {
						throw new Error((json && json.data && json.data.message) || ('Server returned HTTP ' + res.status));
					}
					return json.data;
				});
			}, function (networkErr) {
				var err = new Error('Network error: ' + (networkErr && networkErr.message ? networkErr.message : 'request failed'));
				err.retryable = true;
				throw err;
			});
		}

		function requestWithRetry(job, attempt, ping, render) {
			var t0 = Date.now();
			return request(job, ping, render).then(function (data) {
				var took = Date.now() - t0;
				if (took > 15000) {
					batch = Math.max(5, Math.floor(batch / 2));
				} else if (took < 4000) {
					batch = Math.min(100, batch + 10);
				}
				return data;
			}, function (err) {
				attempt = attempt || 0;
				var after = Math.round((Date.now() - t0) / 1000) + 's';
				var what = err.status ? 'HTTP ' + err.status : 'connection dropped';
				if (!err.retryable || attempt >= maxRetries) {
					err.message = err.message + ' (after ' + after + ', attempt ' + (attempt + 1) + ')';
					throw err;
				}
				batch = Math.max(1, Math.floor(batch / 2));
				var delay = Math.min(30000, 3000 * Math.pow(2, attempt));
				// Ask the server where the failed request got stuck before retrying.
				return request('', 'diag').then(function (d) { return d && d.last_stuck ? d.last_stuck : ''; }, function () { return ''; })
					.then(function (stuck) {
						if (stuck && attempt >= 1) {
							var diagErr = new Error('Insights keeps timing out. ' + stuck + ' (' + what + ' after ' + after + ')');
							throw diagErr;
						}
						setStatus(statusEl, 'loading', 'Server problem (' + what + ' after ' + after + '). ' + (stuck ? stuck + ' ' : '') + 'Retry ' + (attempt + 1) + ' of ' + maxRetries + ' in ' + Math.round(delay / 1000) + 's…');
						return wait(delay);
					})
					.then(function () {
						return requestWithRetry(job, attempt + 1, ping, render);
					});
			});
		}

		function step(data) {
			if (data.done) {
				return data;
			}
			if (data.render) {
				progressText = data.message || 'Building charts…';
				setStatus(statusEl, 'loading', progressText);
				return wait(300).then(function () {
					return requestWithRetry('', 0, false, true);
				}).then(step);
			}
			if (data.pong) {
				var wrong = cfg.build && data.build && data.build !== cfg.build;
				if (wrong) {
					progressText = 'Server is still running insights ' + data.build + '. Re-upload the plugin PHP files.';
					setStatus(statusEl, 'error', progressText);
					throw new Error(progressText);
				}
				if (data.done) {
					return data;
				}
				if (data.job) {
					progressText = (data.message || 'Loading bookings…') + (data.last_stuck ? ' (' + data.last_stuck + ')' : '');
					setStatus(statusEl, 'loading', progressText);
					return requestWithRetry(data.job).then(step);
				}
				progressText = 'Server answered in ' + Math.round((Date.now() - started) / 1000) + 's. Starting…';
				setStatus(statusEl, 'loading', progressText);
				return requestWithRetry('').then(step);
			}
			progressText = data.message
				|| (data.total > 0 && data.processed
					? 'Processing bookings ' + data.processed + ' of ' + data.total + '…'
					: (data.processed ? 'Loaded ' + data.processed + ' bookings…' : 'Loading bookings…'));
			setStatus(statusEl, 'loading', progressText);
			return wait(300).then(function () {
				return requestWithRetry(data.job);
			}).then(step);
		}

		setStatus(statusEl, 'loading', progressText);
		requestWithRetry('', 0, true)
			.then(step)
			.then(function (data) {
				window.clearInterval(timer);
				document.getElementById('vila-cb-insights-kpis').innerHTML = data.kpis || '';
				document.getElementById('vila-cb-insights-tables').innerHTML = data.tables || '';
				bodyEl.hidden = false;
				renderCharts(data.charts || {});
				var s = data.stats || {};
				var detail = '';
				if (s.found !== undefined || s.source) {
					var skipped = [];
					if (s.not_booked) { skipped.push(s.not_booked + ' cancelled/failed/draft'); }
					if (s.out_of_range) { skipped.push(s.out_of_range + ' in the comparison period'); }
					if (s.wrong_type) { skipped.push(s.wrong_type + ' other booking types'); }
					if (s.not_loaded) { skipped.push(s.not_loaded + ' could not be loaded'); }
					detail = ' Checked ' + (s.found || 0) + ' orders for ' + (s.range || 'this range') + (s.source ? ' (from ' + s.source + ')' : '') + (skipped.length ? '; skipped ' + skipped.join(', ') : '') + '.'
						+ (s.failed && s.failed.length ? ' Examples: ' + s.failed.join(' | ') + '.' : '')
						+ ' [build ' + (s.build || 'old cache') + ']';
				}
				setStatus(statusEl, 'done', 'Loaded ' + data.orders + ' bookings in ' + Math.round((Date.now() - started) / 1000) + 's.' + detail);
				timerEl.textContent = '';
			})
			.catch(function (err) {
				window.clearInterval(timer);
				setStatus(statusEl, 'error', err.message || String(err));
				timerEl.textContent = '';
			});
	}

	// Editing a date means a custom range; picking a preset drops stale dates.
	function syncFilterForm() {
		var form = document.getElementById('vila-cb-insights-form');
		if (!form) {
			return;
		}
		var preset = form.querySelector('select[name="preset"]');
		var dates = form.querySelectorAll('input[name="from"], input[name="to"]');
		if (!preset) {
			return;
		}
		Array.prototype.forEach.call(dates, function (input) {
			input.addEventListener('change', function () { preset.value = 'custom'; });
		});
		preset.addEventListener('change', function () {
			if (preset.value !== 'custom') {
				Array.prototype.forEach.call(dates, function (input) { input.value = ''; });
			}
		});
	}

	function boot() {
		syncFilterForm();
		load();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
}());
