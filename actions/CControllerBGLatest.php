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

use CController;
use API;
use CSettingsHelper;
use CArrayHelper;
use CMacrosResolverHelper;
use Manager;
use Modules\BGmotLD\Classes\CBGProfile;

/**
 * Base controller for the "Latest data" page and the "Latest data" asynchronous refresh page.
 */
abstract class CControllerBGLatest extends CController {

	/**
	 * Prepare the latest data based on the given filter and sorting options.
	 *
	 * @param array  $filter                       Item filter options.
	 * @param array  $filter['groupids']           Filter items by host groups.
	 * @param array  $filter['hostids']            Filter items by hosts.
	 * @param string $filter['select']             Filter items by name.
	 * @param int    $filter['evaltype']           Filter evaltype.
	 * @param array  $filter['tags']               Filter tags.
	 * @param string $filter['tags'][]['tag']
	 * @param string $filter['tags'][]['value']
	 * @param int    $filter['tags'][]['operator']
	 * @param int    $filter['show_without_data']  Include items with empty history.
	 * @param string $sort_field                   Sorting field.
	 * @param string $sort_order                   Sorting order.
	 *
	 * @return array
	 */
	protected function prepareData(array $filter, $sort_field, $sort_order) {
		// Select groups for subsequent selection of hosts and items.
		$multiselect_hostgroup_data = [];
		$groupids = $filter['groupids'] ? getSubGroups($filter['groupids'], $multiselect_hostgroup_data) : null;

		// Select hosts for subsequent selection of items.
		$hosts = API::Host()->get([
			'output' => ['hostid', 'name', 'status'],
			'groupids' => $groupids,
			'hostids' => $filter['hostids'] ? $filter['hostids'] : null,
			'monitored_hosts' => true,
			'preservekeys' => true
		]);
		$hostids = array_keys($hosts);
		$hostids_index = array_flip($hostids);

		$search_limit = CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);
		$history_period = timeUnitToSeconds(CSettingsHelper::get(CSettingsHelper::HISTORY_PERIOD));
		$select_items_cnt = 0;
		$select_items = [];

		foreach ($hosts as $hostid => $host) {
			if ($select_items_cnt > $search_limit) {
				unset($hosts[$hostid]);
				continue;
			}

			$host_items = API::Item()->get([
				'output' => ['itemid', 'hostid', 'value_type'],
				'hostids' => [$hostid],
				'webitems' => true,
				'evaltype' => $filter['evaltype'],
				'tags' => $filter['tags'] ? $filter['tags'] : null,
				'filter' => [
					'status' => [ITEM_STATUS_ACTIVE]
				],
				'search' => ($filter['select'] === '') ? null : [
					'name' => $filter['select']
				],
				'preservekeys' => true
			]);

			$select_items += $filter['show_without_data']
				? $host_items
				: Manager::History()->getItemsHavingValues($host_items, $history_period);

			$select_items_cnt = count($select_items);
		}

		if ($select_items) {
			$items = API::Item()->get([
				'output' => ['itemid', 'type', 'hostid', 'name', 'key_', 'delay', 'history', 'trends', 'status',
					'value_type', 'units', 'description', 'state', 'error'
				],
				'selectTags' => ['tag', 'value'],
				'selectValueMap' => ['mappings'],
				'itemids' => array_keys($select_items),
				'webitems' => true,
				'preservekeys' => true
			]);

			if ($sort_field === 'host') {
				$items = array_map(function ($item) use ($hosts) {
					return $item + [
						'host_name' => $hosts[$item['hostid']]['name']
					];
				}, $items);

				CArrayHelper::sort($items, [[
					'field' => 'host_name',
					'order' => $sort_order
				]]);
			}
			else {
				CArrayHelper::sort($items, [[
					'field' => 'name',
					'order' => $sort_order
				]]);
			}
		}
		else {
			$hosts = [];
			$items = [];
		}

		$tags_combined = [];
		foreach ($items as $itemid => $item) {
			$tag_combined = $item['hostid'];
			for($i=0; $i < count($item['tags']); $i++) {
				if ($i == 0) {
					$tag_combined .= '-';
				}
				else {
					$tag_combined .= ', ';
				}
				if ($item['tags'][$i]['value'] != '') {
					$tag_combined .= $item['tags'][$i]['tag'] . ':' . $item['tags'][$i]['value'];
				}
				else {
					$tag_combined .= $item['tags'][$i]['tag'];
				}
			}
			$tags_combined += [$tag_combined];
		}
		sort($tags_combined);

		$tags_combined_size = [];
		$items_grouped = [];
		foreach ($items as $itemid => $item) {
			if (!array_key_exists($item['hostid'], $tags_combined_size)) {
				$tags_combined_size[$item['hostid']] = [];
			}
			$item_tags_combined = $item['hostid'];
			for($i=0; $i < count($item['tags']); $i++) {
				if ($i == 0) {
					$item_tags_combined .= '-';
				}
				else {
					$item_tags_combined .= ', ';
				}
				if ($item['tags'][$i]['value'] != '') {
					$item_tags_combined .= $item['tags'][$i]['tag'] . ':' . $item['tags'][$i]['value'];
				}
				else {
				$item_tags_combined .= $item['tags'][$i]['tag'];
				}
			}
			$items_grouped[$item['hostid']][$item_tags_combined][$itemid] = $item;

			if (array_key_exists($item_tags_combined, $tags_combined_size[$item['hostid']])) {
				$tags_combined_size[$item['hostid']][$item_tags_combined]++;
			}
			else {
				$tags_combined_size[$item['hostid']][$item_tags_combined] = 1;
			}
		}

		uksort($items_grouped, function($hostid_1, $hostid_2) use ($hostids_index) {
			return ($hostids_index[$hostid_1] <=> $hostids_index[$hostid_2]);
		});

		$items = [];
		foreach ($items_grouped as $hostid => $item_tags_combined) {
			ksort($item_tags_combined);
			foreach($item_tags_combined as $item_tag_combined => $item) {
				$items += $item;
			}
		}

		$multiselect_host_data = $filter['hostids']
			? API::Host()->get([
				'output' => ['hostid', 'name'],
				'hostids' => $filter['hostids']
			])
			: [];

		return [
			'hosts' => $hosts,
			'items' => $items,
			'multiselect_hostgroup_data' => $multiselect_hostgroup_data,
			'multiselect_host_data' => CArrayHelper::renameObjectsKeys($multiselect_host_data, ['hostid' => 'id'])
		];
	}

	/**
	 * Extend previously prepared data.
	 *
	 * @param array $prepared_data      Data returned by prepareData method.
	 */
	protected function extendData(array &$prepared_data) {
		$items = CMacrosResolverHelper::resolveItemKeys($prepared_data['items']);
		$items = CMacrosResolverHelper::resolveItemNames($items);
		$items = CMacrosResolverHelper::resolveItemDescriptions($items);
		$items = CMacrosResolverHelper::resolveTimeUnitMacros($items, ['delay', 'history', 'trends']);

		$history = Manager::History()->getLastValues($items, 2,
			timeUnitToSeconds(CSettingsHelper::get(CSettingsHelper::HISTORY_PERIOD))
		);

		$prepared_data['items'] = $items;
		$prepared_data['history'] = $history;
	}

        /**
	 * Add collapsed data from user profile.
	 *
	 * @param array $prepared_data  Data returned by prepareData method.
	 */
	protected function addCollapsedDataFromProfile(array &$prepared_data) {
		$collapsed_index = [];
		$collapsed_all = true;

		foreach ($prepared_data['items'] as $itemid => $item) {
			$hostid = $item['hostid'];
			$tag_combined = $item['hostid'];
			for($i=0; $i < count($item['tags']); $i++) {
				if ($i == 0) {
					$tag_combined .= '-';
				}
				else {
					$tag_combined .= ', ';
				}
				if ($item['tags'][$i]['value'] != '') {
					$tag_combined .= $item['tags'][$i]['tag'] . ':' . $item['tags'][$i]['value'];
				}
				else {
					$tag_combined .= $item['tags'][$i]['tag'];
				}
			}

			if (array_key_exists($hostid, $collapsed_index)
				&& array_key_exists($tag_combined, $collapsed_index[$hostid])) {
				continue;
			}

			$collapsed = CBGProfile::get_str('web.latest.toggle', $tag_combined, null) !== null;

			$collapsed_index[$hostid][$tag_combined] = $collapsed;
			$collapsed_all = $collapsed_all && $collapsed;
		}
		$prepared_data['collapsed_index'] = $collapsed_index;
		$prepared_data['collapsed_all'] = $collapsed_all;
	}
}
