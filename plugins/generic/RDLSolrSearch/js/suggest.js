/**
 * @file plugins/generic/RDLSolrSearch/js/suggest.js
 *
 * Copyright (c) 2026 Royal Danish Library
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Type-ahead for OMP search boxes (input[name="query"]): shows matching book
 * titles below the box; choosing one opens the book. Without a choice the
 * form submits a normal search. Accessible combobox (ARIA 1.2 pattern):
 * arrow keys move, Enter opens, Escape closes.
 *
 * Configuration (set by the plugin): window.rdlSolrSuggest = {url, label, minChars}
 */
(function () {
	'use strict';

	var config = window.rdlSolrSuggest;
	if (!config || !config.url || !window.fetch) {
		return;
	}
	var minChars = config.minChars || 2;
	var counter = 0;

	function attach(input) {
		if (input.dataset.rdlSolrSuggest) {
			return;
		}
		input.dataset.rdlSolrSuggest = '1';
		counter++;
		var listId = 'rdl-solr-suggest-' + counter;
		var list = document.createElement('ul');
		list.id = listId;
		list.className = 'rdl-solr-suggest';
		list.setAttribute('role', 'listbox');
		list.setAttribute('aria-label', config.label || '');
		list.hidden = true;
		input.parentNode.classList.add('rdl-solr-suggest-wrapper');
		input.parentNode.appendChild(list);

		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-autocomplete', 'list');
		input.setAttribute('aria-expanded', 'false');
		input.setAttribute('aria-controls', listId);
		input.setAttribute('autocomplete', 'off');

		var items = [];
		var active = -1;
		var timer = null;
		var controller = null;

		function close() {
			list.hidden = true;
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
			active = -1;
		}

		function setActive(index) {
			if (active >= 0 && list.children[active]) {
				list.children[active].setAttribute('aria-selected', 'false');
			}
			active = index;
			if (active >= 0 && list.children[active]) {
				var option = list.children[active];
				option.setAttribute('aria-selected', 'true');
				input.setAttribute('aria-activedescendant', option.id);
				option.scrollIntoView({block: 'nearest'});
			} else {
				input.removeAttribute('aria-activedescendant');
			}
		}

		function render(suggestions) {
			items = suggestions;
			list.textContent = '';
			active = -1;
			if (!suggestions.length) {
				close();
				return;
			}
			suggestions.forEach(function (suggestion, index) {
				var option = document.createElement('li');
				option.id = listId + '-' + index;
				option.setAttribute('role', 'option');
				option.setAttribute('aria-selected', 'false');
				var title = document.createElement('span');
				title.className = 'rdl-solr-suggest-title';
				title.textContent = suggestion.title;
				option.appendChild(title);
				if (suggestion.authors && suggestion.authors.length) {
					var authors = document.createElement('span');
					authors.className = 'rdl-solr-suggest-authors';
					authors.textContent = suggestion.authors.join(', ');
					option.appendChild(authors);
				}
				// mousedown fires before the input loses focus.
				option.addEventListener('mousedown', function (event) {
					event.preventDefault();
					window.location.href = suggestion.url;
				});
				list.appendChild(option);
			});
			list.hidden = false;
			input.setAttribute('aria-expanded', 'true');
		}

		function load() {
			var value = input.value.trim();
			if (value.length < minChars) {
				render([]);
				return;
			}
			if (controller) {
				controller.abort();
			}
			controller = window.AbortController ? new AbortController() : null;
			var url = config.url + (config.url.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(value);
			fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}, signal: controller ? controller.signal : undefined})
				.then(function (response) {
					return response.ok ? response.json() : null;
				})
				.then(function (data) {
					// Ignore answers to older input.
					if (input.value.trim() !== value) {
						return;
					}
					render(data && data.content && data.content.suggestions ? data.content.suggestions : []);
				})
				.catch(function () {
					/* aborted or offline: no suggestions */
				});
		}

		input.addEventListener('input', function () {
			clearTimeout(timer);
			timer = setTimeout(load, 200);
		});
		input.addEventListener('keydown', function (event) {
			if (list.hidden) {
				return;
			}
			if (event.key === 'ArrowDown') {
				event.preventDefault();
				setActive((active + 1) % items.length);
			} else if (event.key === 'ArrowUp') {
				event.preventDefault();
				setActive(active <= 0 ? items.length - 1 : active - 1);
			} else if (event.key === 'Enter' && active >= 0) {
				event.preventDefault();
				window.location.href = items[active].url;
			} else if (event.key === 'Escape') {
				close();
			}
		});
		input.addEventListener('blur', function () {
			setTimeout(close, 150);
		});
	}

	function init() {
		var inputs = document.querySelectorAll('form input[name="query"]');
		for (var i = 0; i < inputs.length; i++) {
			attach(inputs[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
