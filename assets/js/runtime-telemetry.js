(function () {
	if (typeof pcdRuntime === 'undefined' || !pcdRuntime.ajaxUrl || !pcdRuntime.nonce) {
		return;
	}

	const sent = new Set();

	function sameOrigin(url) {
		try {
			const parsed = new URL(url, window.location.href);
			return parsed.origin === window.location.origin;
		} catch (error) {
			return false;
		}
	}

	function requestContext() {
		return pcdRuntime.requestContext || 'frontend';
	}

	function requestUri() {
		return pcdRuntime.requestUri || window.location.pathname + window.location.search;
	}

	function sessionId() {
		return pcdRuntime.activeSession && pcdRuntime.activeSession.id ? pcdRuntime.activeSession.id : '';
	}

	function fingerprint(payload) {
		return [
			payload.type || '',
			payload.message || '',
			payload.request_context || '',
			payload.resource || '',
			payload.status_code || 0,
		].join('|');
	}

	function shouldTrackNetwork(url) {
		if (!url || !sameOrigin(url)) {
			return false;
		}

		return url.indexOf('/' + pcdRuntime.restPrefix) !== -1 || url.indexOf('admin-ajax.php') !== -1;
	}

	function send(payload) {
		const key = fingerprint(payload);
		if (sent.has(key)) {
			return;
		}

		sent.add(key);

		const body = new URLSearchParams();
		body.append('action', 'pcd_report_runtime_event');
		body.append('nonce', pcdRuntime.nonce);
		body.append('type', payload.type || 'client');
		body.append('level', payload.level || 'error');
		body.append('message', payload.message || '');
		body.append('request_context', payload.request_context || requestContext());
		body.append('request_uri', payload.request_uri || requestUri());
		body.append('source', payload.source || '');
		body.append('resource', payload.resource || '');
		body.append('status_code', String(payload.status_code || 0));
		body.append('session_id', payload.session_id || sessionId());
		body.append('resource_hints', JSON.stringify(Array.isArray(pcdRuntime.resourceHints) ? pcdRuntime.resourceHints : []));

		if (navigator.sendBeacon) {
			navigator.sendBeacon(pcdRuntime.ajaxUrl, body);
			return;
		}

		fetch(pcdRuntime.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
			},
			body: body.toString(),
			keepalive: true,
		}).catch(function () {
			// Telemetry failures should never affect the page.
		});
	}

	window.addEventListener('error', function (event) {
		const target = event.target;
		if (target && target !== window && (target.tagName === 'SCRIPT' || target.tagName === 'LINK' || target.tagName === 'IMG')) {
			send({
				type: 'resource_error',
				level: 'error',
				message: 'Observed missing or failed asset load.',
				source: target.src || target.href || '',
				resource: target.getAttribute('src') || target.getAttribute('href') || target.tagName.toLowerCase(),
			});
			return;
		}

		send({
			type: 'js_error',
			level: 'error',
			message: event.message || 'Observed JavaScript error.',
			source: event.filename || '',
			resource: event.filename || '',
		});
	}, true);

	window.addEventListener('unhandledrejection', function (event) {
		let reason = 'Observed unhandled promise rejection.';
		if (event.reason) {
			reason = typeof event.reason === 'string' ? event.reason : (event.reason.message || reason);
		}

		send({
			type: 'js_promise',
			level: 'error',
			message: reason,
		});
	});

	if (window.fetch) {
		const nativeFetch = window.fetch.bind(window);
		window.fetch = function () {
			const url = arguments[0];
			return nativeFetch.apply(window, arguments).then(function (response) {
				if (response && !response.ok && shouldTrackNetwork(response.url || '')) {
					send({
						type: 'network_failure',
						level: response.status >= 500 ? 'server-error' : 'client-error',
						message: 'Observed failed same-origin API or AJAX response.',
						source: response.url || '',
						resource: response.url || '',
						status_code: response.status || 0,
					});
				}

				return response;
			}).catch(function (error) {
				if (typeof url === 'string' && shouldTrackNetwork(url)) {
					send({
						type: 'network_failure',
						level: 'network-error',
						message: error && error.message ? error.message : 'Observed failed same-origin API or AJAX request.',
						source: url,
						resource: url,
					});
				}

				throw error;
			});
		};
	}

	if (window.XMLHttpRequest) {
		const open = XMLHttpRequest.prototype.open;
		const sendXhr = XMLHttpRequest.prototype.send;

		XMLHttpRequest.prototype.open = function (method, url) {
			this.__pcdUrl = url;
			return open.apply(this, arguments);
		};

		XMLHttpRequest.prototype.send = function () {
			this.addEventListener('loadend', function () {
				const url = this.__pcdUrl || '';
				if (this.status >= 400 && shouldTrackNetwork(url)) {
					send({
						type: 'network_failure',
						level: this.status >= 500 ? 'server-error' : 'client-error',
						message: 'Observed failed same-origin API or AJAX response.',
						source: url,
						resource: url,
						status_code: this.status || 0,
					});
				}
			});

			return sendXhr.apply(this, arguments);
		};
	}
}());
