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

use CUrl;
use CPagerHelper;
use CWebUser;
use CHousekeepingHelper;
use CControllerResponseData;
use CControllerResponseFatal;
use CRoleHelper;
use Modules\BGmotLD\Classes\CBGProfile;

class CControllerBGLatestView extends CControllerBGLatest {

	protected function init() {
		$this->disableSIDValidation();
	}

	protected function checkInput() {
		$fields = [
			'page' =>                                               'ge 1',
			// filter fields
			'groupids' =>				'array_db hosts_groups.groupid',
			'hostids' =>				'array_db hosts.hostid',
			'name' =>					'string',
			'show_details' =>			'in 1,0',
			'evaltype' =>				'in '.TAG_EVAL_TYPE_AND_OR.','.TAG_EVAL_TYPE_OR,
			'tags' =>					'array',
			'show_tags' =>				'in '.SHOW_TAGS_NONE.','.SHOW_TAGS_1.','.SHOW_TAGS_2.','.SHOW_TAGS_3,
			'tag_name_format' =>		'in '.TAG_NAME_FULL.','.TAG_NAME_SHORTENED.','.TAG_NAME_NONE,
			'tag_priority' =>			'string',
			// filter inputs
			'filter_groupids' =>                    'array_id',
			'filter_hostids' =>                             'array_id',
			'filter_select' =>                              'string',
			'filter_show_without_data' =>   'in 0,1',
			'filter_show_details' =>                'in 1',
			'filter_set' =>                                 'in 1',
			'filter_rst' =>                                 'in 1',
			'filter_evaltype' =>                    'in '.TAG_EVAL_TYPE_AND_OR.','.TAG_EVAL_TYPE_OR,
			'filter_tags' =>                                'array',

			// table sorting inputs
			'sort' =>                                               'in host,name,lastclock',
			'sortorder' =>                                  'in '.ZBX_SORT_DOWN.','.ZBX_SORT_UP
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions() {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA);
	}

	protected function doAction() {
		// filter
		if (count($this->getInput('groupids', [])) > 0) {
			// Since 6.0
			CBGProfile::updateArray('web.latest.filter.groupids', $this->getInput('groupids', []),
				PROFILE_TYPE_ID
			);
                }
		if (count($this->getInput('hostids', [])) > 0) {
			// Pre 6.0
			CBGProfile::updateArray('web.latest.filter.hostids', $this->getInput('hostids', []), PROFILE_TYPE_ID);
		}
		if ($this->hasInput('filter_set')) {
			CBGProfile::updateArray('web.latest.filter.groupids', $this->getInput('filter_groupids', []),
				PROFILE_TYPE_ID
			);
			CBGProfile::updateArray('web.latest.filter.hostids', $this->getInput('filter_hostids', []), PROFILE_TYPE_ID);
			CBGProfile::update('web.latest.filter.select', trim($this->getInput('filter_select', '')), PROFILE_TYPE_STR);
			CBGProfile::update('web.latest.filter.show_without_data', $this->getInput('filter_show_without_data', 0),
				PROFILE_TYPE_INT
			);
			CBGProfile::update('web.latest.filter.show_details', $this->getInput('filter_show_details', 0),
				PROFILE_TYPE_INT
			);

			// tags
			$evaltype = $this->getInput('filter_evaltype', TAG_EVAL_TYPE_AND_OR);
			CBGProfile::update('web.latest.filter.evaltype', $evaltype, PROFILE_TYPE_INT);

			$filter_tags = ['tags' => [], 'values' => [], 'operators' => []];
			foreach ($this->getInput('filter_tags', []) as $tag) {
				if ($tag['tag'] === '' && $tag['value'] === '') {
					continue;
				}
				$filter_tags['tags'][] = $tag['tag'];
				$filter_tags['values'][] = $tag['value'];
				$filter_tags['operators'][] = $tag['operator'];
			}
			CBGProfile::updateArray('web.latest.filter.tags.tag', $filter_tags['tags'], PROFILE_TYPE_STR);
			CBGProfile::updateArray('web.latest.filter.tags.value', $filter_tags['values'], PROFILE_TYPE_STR);
			CBGProfile::updateArray('web.latest.filter.tags.operator', $filter_tags['operators'], PROFILE_TYPE_INT);
		}
		elseif ($this->hasInput('filter_rst')) {
			CBGProfile::deleteIdx('web.latest.filter.groupids');
			CBGProfile::deleteIdx('web.latest.filter.hostids');
			CBGProfile::delete('web.latest.filter.select');
			CBGProfile::delete('web.latest.filter.show_without_data');
			CBGProfile::delete('web.latest.filter.show_details');
			CBGProfile::deleteIdx('web.latest.filter.evaltype');
			CBGProfile::deleteIdx('web.latest.filter.tags.tag');
			CBGProfile::deleteIdx('web.latest.filter.tags.value');
			CBGProfile::deleteIdx('web.latest.filter.tags.operator');
		}

		// Force-check "Show items without data" if there are no hosts selected.
		$filter_hostids = CBGProfile::getArray('web.latest.filter.hostids');
		$filter_show_without_data = $filter_hostids ? CBGProfile::get('web.latest.filter.show_without_data', 1) : 1;

		$filter = [
			'groupids' => CBGProfile::getArray('web.latest.filter.groupids'),
			'hostids' => $filter_hostids,
			'select' => CBGProfile::get('web.latest.filter.select', ''),
			'show_without_data' => $filter_show_without_data,
			'show_details' => CBGProfile::get('web.latest.filter.show_details', 0),
			'evaltype' => CBGProfile::get('web.latest.filter.evaltype', TAG_EVAL_TYPE_AND_OR),
			'tags' => []
		];

		// Tags filters.
		foreach (CBGProfile::getArray('web.latest.filter.tags.tag', []) as $i => $tag) {
			$filter['tags'][] = [
				'tag' => $tag,
				'value' => CBGProfile::get('web.latest.filter.tags.value', null, $i),
				'operator' => CBGProfile::get('web.latest.filter.tags.operator', null, $i)
			];
		}

		$sort_field = $this->getInput('sort', CBGProfile::get('web.latest.sort', 'name'));
		$sort_order = $this->getInput('sortorder', CBGProfile::get('web.latest.sortorder', ZBX_SORT_UP));

		CBGProfile::update('web.latest.sort', $sort_field, PROFILE_TYPE_STR);
		CBGProfile::update('web.latest.sortorder', $sort_order, PROFILE_TYPE_STR);

		$view_curl = (new CUrl('zabbix.php'))->setArgument('action', 'latest.view');

		$refresh_curl = (new CUrl('zabbix.php'))
			->setArgument('action', 'latest.view.refresh')
			->setArgument('filter_groupids', $filter['groupids'])
			->setArgument('filter_hostids', $filter['hostids'])
			->setArgument('filter_select', $filter['select'])
			->setArgument('filter_show_without_data', $filter['show_without_data'] ? 1 : null)
			->setArgument('filter_show_details', $filter['show_details'] ? 1 : null)
			->setArgument('filter_evaltype', $filter['evaltype'])
			->setArgument('filter_tags', $filter['tags'])
			->setArgument('sort', $sort_field)
			->setArgument('sortorder', $sort_order)
			->setArgument('page', $this->hasInput('page') ? $this->getInput('page') : null);

		// data sort and pager
		$prepared_data = $this->prepareData($filter, $sort_field, $sort_order);

		$paging = CPagerHelper::paginate($this->getInput('page', 1), $prepared_data['items'], ZBX_SORT_UP, $view_curl);

		$this->extendData($prepared_data);
		$this->addCollapsedDataFromProfile($prepared_data);

		// display
		$data = [
			'filter' => $filter,
			'sort_field' => $sort_field,
			'sort_order' => $sort_order,
			'view_curl' => $view_curl,
			'refresh_url' => $refresh_curl->getUrl(),
			'refresh_interval' => CWebUser::getRefresh() * 1000,
			'active_tab' => CBGProfile::get('web.latest.filter.active', 1),
			'paging' => $paging,
			'config' => [
				'hk_trends' => CHousekeepingHelper::get(CHousekeepingHelper::HK_TRENDS),
				'hk_trends_global' => CHousekeepingHelper::get(CHousekeepingHelper::HK_TRENDS_GLOBAL),
				'hk_history' => CHousekeepingHelper::get(CHousekeepingHelper::HK_HISTORY),
				'hk_history_global' => CHousekeepingHelper::get(CHousekeepingHelper::HK_HISTORY_GLOBAL)
			],
			'tags' => makeTags($prepared_data['items'], true, 'itemid', ZBX_TAG_COUNT_DEFAULT, $filter['tags'])
		] + $prepared_data;

		if (!$data['filter']['tags']) {
			$data['filter']['tags'] = [[
				'tag' => '',
				'operator' => TAG_OPERATOR_LIKE,
				'value' => ''
			]];
		}

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Latest data'));
		$this->setResponse($response);
	}
}
?>
