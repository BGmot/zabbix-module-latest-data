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

use CControllerProfileUpdate;
use CControllerResponseData;
use Modules\BGmotLD\Classes\CBGProfile;

class CControllerBGProfileUpdate extends CControllerProfileUpdate {

	protected function checkInput() {
		$fields = [
			'idx' =>		'required|string',
			'value_int' =>		'required|int32',
			'idx2' =>		'array_id',
			'value_str' =>		'array'
		];

		$ret = $this->validateInput($fields);

		if ($ret) {
			switch ($this->getInput('idx')) {
				case 'web.actionconf.filter.active':
				case 'web.auditacts.filter.active':
				case 'web.auditlog.filter.active':
				case 'web.avail_report.filter.active':
				case 'web.charts.filter.active':
				case 'web.correlation.filter.active':
				case 'web.dashboard.filter.active':
				case 'web.dashboard.hostid':
				case 'web.discovery.filter.active':
				case 'web.discoveryconf.filter.active':
				case 'web.groups.filter.active':
				case 'web.hostinventories.filter.active':
				case 'web.hostinventoriesoverview.filter.active':
				case 'web.hosts.filter.active':
				case 'web.hosts.graphs.filter.active':
				case 'web.hosts.host_discovery.filter.active':
				case 'web.hosts.httpconf.filter.active':
				case 'web.hosts.items.filter.active':
				case 'web.hosts.triggers.filter.active':
				case 'web.hostsmon.filter.active':
				case 'web.httpdetails.filter.active':
				case 'web.item.graph.filter.active':
				case 'web.latest.filter.active':
				case 'web.layout.mode':
				case 'web.maintenance.filter.active':
				case 'web.media_types.filter.active':
				case 'web.modules.filter.active':
				case 'web.overview.filter.active':
				case 'web.problem.filter.active':
				case 'web.proxies.filter.active':
				case 'web.scheduledreport.filter.active':
				case 'web.scripts.filter.active':
				case 'web.search.hats.'.WIDGET_SEARCH_HOSTS.'.state':
				case 'web.search.hats.'.WIDGET_SEARCH_TEMPLATES.'.state':
				case 'web.search.hats.'.WIDGET_SEARCH_HOSTGROUP.'.state':
				case 'web.sidebar.mode':
				case 'web.sysmapconf.filter.active':
				case 'web.templates.filter.active':
				case 'web.templates.graphs.filter.active':
				case 'web.templates.host_discovery.filter.active':
				case 'web.templates.httpconf.filter.active':
				case 'web.templates.items.filter.active':
				case 'web.templates.triggers.filter.active':
				case 'web.token.filter.active':
				case 'web.toptriggers.filter.active':
				case 'web.tr_events.hats.'.WIDGET_HAT_EVENTACTIONS.'.state':
				case 'web.tr_events.hats.'.WIDGET_HAT_EVENTLIST.'.state':
				case 'web.user.filter.active':
				case 'web.user.token.filter.active':
				case 'web.usergroup.filter.active':
				case 'web.web.filter.active':
					$ret = true;
					break;

				case !!preg_match('/web.dashboard.widget.navtree.item-\d+.toggle/', $this->getInput('idx')):
				case 'web.dashboard.widget.navtree.item.selected':
					$ret = $this->hasInput('idx2');
					break;

				case 'web.latest.toggle':
				case 'web.latest.toggle_other':
					$ret = $this->hasInput('value_str');
					break;

				default:
					$ret = false;
			}
		}

		if (!$ret) {
			$this->setResponse(new CControllerResponseData(['main_block' => '']));
		}

		return $ret;
	}

	protected function doAction() {
		$idx = $this->getInput('idx');
		$value_int = $this->getInput('value_int');
		$value_str = $this->getInput('value_str');

		DBstart();
		switch ($idx) {
			case !!preg_match('/web.dashboard.widget.navtree.item-\d+.toggle/', $this->getInput('idx')):
				if ($value_int == 1) { // default value
					CBGProfile::delete($idx, $this->getInput('idx2'));
				}
				else {
					foreach ($this->getInput('idx2') as $idx2) {
						CBGProfile::update($idx, $value_int, PROFILE_TYPE_INT, $idx2);
					}
				}
				break;

			case 'web.latest.toggle':
				if ($value_int == 1) { // default value
					CBGProfile::delete_str($idx, $value_str);
				}
				else {
					foreach ($value_str as $str) {
						CBGProfile::update($idx, $str, PROFILE_TYPE_STR);
					}
				}
				break;

			case 'web.dashboard.widget.navtree.item.selected':
				foreach ($this->getInput('idx2') as $idx2) {
					CBGProfile::update($idx, $value_int, PROFILE_TYPE_INT, $idx2);
				}
				break;

			case 'web.layout.mode':
				CViewHelper::saveLayoutMode($value_int);
				break;

			case 'web.sidebar.mode':
				CViewHelper::saveSidebarMode($value_int);
				break;

			default:
				if ($value_int == 1) { // default value
					CBGProfile::delete($idx);
				}
				else {
					CBGProfile::update($idx, $value_int, PROFILE_TYPE_INT);
				}
		}
		DBend();

		$this->setResponse(new CControllerResponseData(['main_block' => '']));
	}
}
