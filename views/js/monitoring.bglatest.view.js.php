<?php declare(strict_types = 1);

namespace Modules\BGmotLD\Actions;

/*
** Zabbix
** Copyright (C) 2001-2021 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/

/**
 * @var CView $this
 */
use CTagFilterFieldHelper;
?>

<script type="text/x-jquery-tmpl" id="filter-tag-row-tmpl">
	<?= CTagFilterFieldHelper::getTemplate(); ?>
</script>

<script type="text/javascript">
	function latestPage() {
		this.refresh_url = '<?= $data['refresh_url'] ?>';
		this.refresh_interval = <?= $data['refresh_interval'] ?>;
		this.running = false;
		this.timeout = null;
	}

	function updateBGUserProfile(idx, value_int, idx2, value_str) {
		return sendAjaxData('zabbix.php?action=bgprofile.update', {
			data: {
				idx: idx,
				value_int: value_int,
				idx2: idx2,
				value_str: value_str
			}
		});
	}

	latestPage.prototype.getCurrentForm = function() {
		return $('form[name=items]');
	};

	latestPage.prototype.addMessages = function(messages) {
		$('.wrapper main').before(messages);
	};

	latestPage.prototype.removeMessages = function() {
		$('.wrapper .msg-bad').remove();
	};

	latestPage.prototype.refresh = function() {
		this.setLoading();

		var deferred = $.getJSON(this.refresh_url);

		return this.bindDataEvents(deferred);
	};

	latestPage.prototype.setLoading = function() {
		this.getCurrentForm().addClass('is-loading is-loading-fadein delayed-15s');
	};

	latestPage.prototype.clearLoading = function() {
		this.getCurrentForm().removeClass('is-loading is-loading-fadein delayed-15s');
	};

	latestPage.prototype.doRefresh = function(body) {
		this.getCurrentForm().replaceWith(body);
		this.liveData();
		chkbxRange.init();
	};

	latestPage.prototype.bindDataEvents = function(deferred) {
		var that = this;

		deferred
			.done(function(response) {
				that.onDataDone.call(that, response);
			})
			.fail(function(jqXHR) {
				that.onDataFail.call(that, jqXHR);
			})
			.always(this.onDataAlways.bind(this));

		return deferred;
	};

	latestPage.prototype.onDataDone = function(response) {
		this.clearLoading();
		this.removeMessages();
		this.doRefresh(response.body);

		if ('messages' in response) {
			this.addMessages(response.messages);
		}
	};

	latestPage.prototype.onDataFail = function(jqXHR) {
		// Ignore failures caused by page unload.
		if (jqXHR.status == 0) {
			return;
		}

		this.clearLoading();

		var messages = $(jqXHR.responseText).find('.msg-global');

		if (messages.length) {
			this.getCurrentForm().html(messages);
		}
		else {
			this.getCurrentForm().html(jqXHR.responseText);
		}
	};

	latestPage.prototype.onDataAlways = function() {
		if (this.running) {
			this.scheduleRefresh();
		}
	};

	latestPage.prototype.scheduleRefresh = function() {
		this.unscheduleRefresh();
		this.timeout = setTimeout((function() {
			this.timeout = null;
			this.refresh();
		}).bind(this), this.refresh_interval);
	};

	latestPage.prototype.unscheduleRefresh = function() {
		if (this.timeout !== null) {
			clearTimeout(this.timeout);
			this.timeout = null;
		}
	};

	latestPage.prototype.start = function() {
		if (this.refresh_interval != 0) {
			this.running = true;
			this.scheduleRefresh();
		}
	};

	latestPage.prototype.stop = function() {
		this.running = false;
		this.unscheduleRefresh();
	};

	latestPage.prototype.toggleChevronCollapsed = function($chevron, collapsed) {
		$chevron
			.removeClass(collapsed ? '<?= ZBX_STYLE_ARROW_DOWN ?>' : '<?= ZBX_STYLE_ARROW_RIGHT ?>')
			.addClass(collapsed ? '<?= ZBX_STYLE_ARROW_RIGHT ?>' : '<?= ZBX_STYLE_ARROW_DOWN ?>');
	};

	latestPage.prototype.isChevronCollapsed = function($chevron) {
		return $chevron.hasClass('<?= ZBX_STYLE_ARROW_RIGHT ?>');
	};

	latestPage.prototype.toggleTagGroup = function(group, group_id, collapsed) {
		var $chevron = $('.js-toggle[data-' + group + '="' + group_id + '"] span'),
			$rows = $('tr[data-' + group + '="' + group_id + '"]');

		this.toggleChevronCollapsed($chevron, collapsed);

		$rows.toggleClass('<?= ZBX_STYLE_DISPLAY_NONE ?>', collapsed);
	};

	latestPage.prototype.updateToggleAll = function() {
		var self = this,

			$chevron_all = $('.js-toggle-all span'),
			collapsed_all = true;

		$('.js-toggle span').each(function() {
			collapsed_all = collapsed_all && self.isChevronCollapsed($(this));
		});

		this.toggleChevronCollapsed($chevron_all, collapsed_all);
	};

	latestPage.prototype.liveFilter = function() {
		var $filter_hostids = $('#filter_hostids_'),
			$filter_show_without_data = $('#filter_show_without_data');

		$filter_hostids.on('change', function() {
			var no_hosts_selected = !$(this).multiSelect('getData').length;

			if (no_hosts_selected) {
				$filter_show_without_data.prop('checked', true);
			}

			$filter_show_without_data.prop('disabled', no_hosts_selected);
		});

		$('#filter-tags')
			.dynamicRows({template: '#filter-tag-row-tmpl'})
			.on('afteradd.dynamicRows', function() {
				var rows = this.querySelectorAll('.form_row');
				new CTagFilterItem(rows[rows.length - 1]);
			});

		// Init existing fields once loaded.
		document.querySelectorAll('#filter-tags .form_row').forEach(row => {
			new CTagFilterItem(row);
		});
	};

	latestPage.prototype.liveData = function() {
		var self = this;

		$('.js-toggle-all').on('click', function() {
			// For Opera browser with large tables, which renders table layout while showing/hiding rows.
			$(this).closest('table').fadeTo(0, 0);

			var $toggle_all = $(this),
				collapsed_all = !self.isChevronCollapsed($toggle_all.find('span')),

				updates = {
					tag_combined: [],
					hostid: []
				};

			$('.js-toggle').each(function() {
				var $toggle = $(this),
					collapsed = self.isChevronCollapsed($toggle.find('span'));

				if (collapsed == collapsed_all) {
					return;
				}

				var group = 'tag_combined',
					group_id = $toggle.data(group);

				if (group_id === undefined) {
					group = 'hostid',
					group_id = $toggle.data(group);
				}
				updates[group].push(group_id);

				self.toggleTagGroup(group, group_id, collapsed_all);
			});

			self.updateToggleAll();

			// For Opera browser with large tables, which renders table layout while showing/hiding rows.
			$(this).closest('table').fadeTo(0, 1);

			if (updates.tag_combined.length) {
				updateBGUserProfile('web.latest.toggle', collapsed_all ? 0 : 1, [0], updates.tag_combined);
			}
			if (updates.hostid.length) {
				updateBGUserProfile('web.latest.toggle_other', collapsed_all ? 0 : 1, updates.hostid);
			}
		});

		$('.js-toggle').on('click', function() {
			var $toggle = $(this),
				collapsed = !self.isChevronCollapsed($toggle.find('span')),

				group = 'tag_combined',
				group_id = $toggle.data(group);

			if (group_id === undefined) {
				group = 'hostid',
				group_id = $toggle.data(group);
			}

			self.toggleTagGroup(group, group_id, collapsed);
			self.updateToggleAll();

			if (group === 'tag_combined') {
				updateBGUserProfile('web.latest.toggle', collapsed ? 0 : 1, [0], [group_id]);
			}
			else {
				updateBGUserProfile('web.latest.toggle_other', collapsed ? 0 : 1, [group_id]);
			}
		});
	};

	$(function() {
		window.latest_page = new latestPage();
		window.latest_page.liveFilter();
		window.latest_page.liveData();
	});

	const view = {
		timeout: null,
		_popup_message_box: null,

		editHost(hostid) {
			const host_data = {hostid};
			this.openHostPopup(host_data);
		},

		openHostPopup(host_data) {
			this._removePopupMessage();

			const original_url = location.href;
			const overlay = PopUp('popup.host.edit', host_data, {
				dialogueid: 'host_edit',
				dialogue_class: 'modal-popup-large',
				prevent_navigation: true
			});

			this.unscheduleRefresh();

			overlay.$dialogue[0].addEventListener('dialogue.create', this.events.hostSuccess, {once: true});
			overlay.$dialogue[0].addEventListener('dialogue.update', this.events.hostSuccess, {once: true});
			overlay.$dialogue[0].addEventListener('dialogue.delete', this.events.hostSuccess, {once: true});
			overlay.$dialogue[0].addEventListener('overlay.close', () => {
				history.replaceState({}, '', original_url);
			}, {once: true});
		},

		_removePopupMessage() {
			if (this._popup_message_box !== null) {
				this._popup_message_box.remove();
				this._popup_message_box = null;
			}
		},

		unscheduleRefresh() {
			if (this.timeout !== null) {
				clearTimeout(this.timeout);
				this.timeout = null;
			}

			if (this.deferred) {
				this.deferred.abort();
			}
		},

		events: {
			hostSuccess(e) {
				const data = e.detail;

				if ('success' in data) {
					const title = data.success.title;
					let messages = [];

					if ('messages' in data.success) {
						messages = data.success.messages;
					}

					view._addPopupMessage(makeMessageBox('good', messages, title));
				}

				uncheckTableRows('latest');
			}
		},

		_addPopupMessage(message_box) {
			this._removePopupMessage();

			this._popup_message_box = message_box;
			addMessage(this._popup_message_box);
		}
	};
</script>
