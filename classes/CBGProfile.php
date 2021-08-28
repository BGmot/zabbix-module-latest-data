<?php declare(strict_types = 1);

namespace Modules\BGmotLD\Classes;

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

use CProfile;
use CWebUser;
use DB;

class CBGProfile extends CProfile {

	private static $userDetails = [];
	private static $profiles = null;
	private static $update = [];
	private static $insert = [];
	private static $stringProfileMaxLength;
	private static $is_initialized = false;

	public static function init() {
		self::$userDetails = CWebUser::$data;
		self::$profiles = [];

		self::$stringProfileMaxLength = DB::getFieldLength('profiles', 'value_str');
		DBselect('SELECT NULL FROM users u WHERE '.dbConditionId('u.userid', (array) self::$userDetails['userid']).
			' FOR UPDATE'
		);

		if (!self::$is_initialized) {
			register_shutdown_function(function() {
				DBstart();
				$result = self::flush();
				DBend($result);
			});
		}

		self::$is_initialized = true;
	}

	/**
	* Check if data needs to be inserted or updated.
	*
	* @return bool
	*/
	public static function isModified() {
		return (self::$insert || self::$update);
	}

	/**
	* Writes all the changes into DB
	*
	* @return bool
	*/
	public static function flush() {
		$result = false;

		if (self::$profiles !== null && self::$userDetails['userid'] > 0 && self::isModified()) {
			$result = true;

			foreach (self::$insert as $idx => $profile) {
				foreach ($profile as $idx2 => $data) {
					if ($idx == 'web.latest.toggle') {
						foreach($data as $t_data) {
							$result &= self::insertDB($idx, $t_data['value'], $t_data['type'], $idx2);
						}
					}
					else {
						$result &= self::insertDB($idx, $data['value'], $data['type'], $idx2);
					}
				}
			}

			ksort(self::$update);
			foreach (self::$update as $idx => $profile) {
				ksort($profile);
				foreach ($profile as $idx2 => $data) {
					$result &= self::updateDB($idx, $data['value'], $data['type'], $idx2);
				}
			}

			self::clear();
		}

		return $result;
	}

	/**
	 * Return matched idx value for current user.
	 *
	 * @param string    $idx           Search pattern.
	 * @param string    $value_str     Search for this pattern in value_str field.
	 * @param mixed     $default_value Default value if no rows was found.
	 * @param int|null  $idx2          Numerical index will be matched against idx2 index.
	 *
	 * @return mixed
	 */
	public static function get_str($idx, $value_str, $default_value = null, $idx2 = 0) {
		// no user data available, just return the default value
		if (!CWebUser::$data || $value_str === null) {
			return $default_value;
		}

		if (self::$profiles === null) {
			self::init();
		}

		if (array_key_exists($idx, self::$profiles)) {
			if (array_key_exists($idx2, self::$profiles[$idx])) {
				if (array_key_exists($value_str, self::$profiles[$idx][$idx2])) {
					return self::$profiles[$idx][$idx2][$value_str];
				}
			}
			else {
				self::$profiles[$idx][$idx2] = [];
			}
		}
		else {
			self::$profiles[$idx] = [$idx2 => []];
		}

		// Aggressive caching, cache all items matched $idx key.
		$query = DBselect(
			'SELECT type,value_id,value_int,value_str,idx2'.
			' FROM profiles'.
			' WHERE userid='.self::$userDetails['userid'].
			' AND idx='.zbx_dbstr($idx).
			' AND idx2='.zbx_dbstr($idx2).
			' AND value_str='.zbx_dbstr($value_str)
		);

		while ($row = DBfetch($query)) {
			self::$profiles[$idx][$idx2][$value_str] = $row['value_str'];
		}

		return array_key_exists($value_str, self::$profiles[$idx][$idx2]) ? self::$profiles[$idx][$idx2][$value_str] : $default_value;
	}

	/**
	 * Removes profile STR values from DB and profiles cache.
	 *
	 * @param string 		$idx		first identifier
	 * @param string|array  	$value_str	sting or list of strings
	 */
	public static function delete_str($idx, $value_str = '') {
		if (self::$profiles === null) {
			self::init();
		}

		$value_str = (array) $value_str;
		self::deleteValuesStr($idx, $value_str);

		if (array_key_exists($idx, self::$profiles)) {
			foreach ($value_str as $str) {
				unset(self::$profiles[$idx][0][$str]);
			}
		}
	}

	/**
	 * Deletes the given STR values from the DB.
	 *
	 * @param string 	$idx
	 * @param array 	$value_str
	 */
	protected static function deleteValuesStr($idx, array $value_str) {
		// remove from DB
		DB::delete('profiles', ['idx' => $idx, 'idx2' => 0, 'userid' => self::$userDetails['userid'], 'value_str' => $value_str]);
	}

	/**
	 * Update favorite values in DB profiles table.
	 *
	 * @param string	$idx		max length is 96
	 * @param mixed		$value		max length 255 for string
	 * @param int		$type
	 * @param int		$idx2
	 */
	public static function update($idx, $value, $type, $idx2 = 0) {
		if (self::$profiles === null) {
			self::init();
		}

		if (!self::checkValueType($value, $type)) {
			return;
		}

		$profile = [
			'idx' => $idx,
			'value' => $value,
			'type' => $type,
			'idx2' => $idx2
		];

		if ($idx == 'web.latest.toggle') {
			$current = self::get_str($idx, $value, null, $idx2);
		}
		else {
			$current = self::get($idx, null, $idx2);
		}

		if (is_null($current)) {
			if (!isset(self::$insert[$idx])) {
				if ($idx == 'web.latest.toggle') {
					self::$insert[$idx] = [ $idx2 => [] ];
				}
				else {
					self::$insert[$idx] = [];
				}
			}
			if ($idx == 'web.latest.toggle') {
				self::$insert[$idx][$idx2][$profile['value']] = $profile;
			}
			else {
				self::$insert[$idx][$idx2] = $profile;
			}
		}
		else {
			if ($current != $value) {
				if (!isset(self::$update[$idx])) {
					self::$update[$idx] = [];
				}
				self::$update[$idx][$idx2] = $profile;
			}
		}

		if (!isset(self::$profiles[$idx])) {
			self::$profiles[$idx] = [];
		}

		if ($idx == 'web.latest.toggle') {
			self::$profiles[$idx][$idx2][$value] = $value;
		}
		else {
			self::$profiles[$idx][$idx2] = $value;
		}
	}

	private static function checkValueType($value, $type) {
		switch ($type) {
			case PROFILE_TYPE_ID:
				return zbx_ctype_digit($value);
			case PROFILE_TYPE_INT:
				return zbx_is_int($value);
			case PROFILE_TYPE_STR:
				return mb_strlen($value) <= self::$stringProfileMaxLength;
			default:
				return true;
		}
	}

	private static function insertDB($idx, $value, $type, $idx2) {
		$value_type = self::getFieldByType($type);

		$values = [
			'profileid' => get_dbid('profiles', 'profileid'),
			'userid' => self::$userDetails['userid'],
			'idx' => zbx_dbstr($idx),
			$value_type => zbx_dbstr($value),
			'type' => $type,
			'idx2' => zbx_dbstr($idx2)
			] + [
				'value_str' => zbx_dbstr('')
			];

		return DBexecute('INSERT INTO profiles ('.implode(', ', array_keys($values)).') VALUES ('.implode(', ', $values).')');
	}

	private static function updateDB($idx, $value, $type, $idx2) {
		$valueType = self::getFieldByType($type);

		return DBexecute(
			'UPDATE profiles SET '.
			$valueType.'='.zbx_dbstr($value).','.
			' type='.$type.
			' WHERE userid='.self::$userDetails['userid'].
				' AND idx='.zbx_dbstr($idx).
				' AND idx2='.zbx_dbstr($idx2)
		);
	}

	private static function getFieldByType($type) {
		switch ($type) {
			case PROFILE_TYPE_INT:
				$field = 'value_int';
				break;
			case PROFILE_TYPE_STR:
				$field = 'value_str';
				break;
			case PROFILE_TYPE_ID:
			default:
				$field = 'value_id';
		}

		return $field;
	}

	/**
	 * Return matched idx value for current user.
	 *
	 * @param string    $idx           Search pattern.
	 * @param mixed     $default_value Default value if no rows was found.
	 * @param int|null  $idx2          Numerical index will be matched against idx2 index.
	 *
	 * @return mixed
	 */
	public static function get($idx, $default_value = null, $idx2 = 0) {
		// no user data available, just return the default value
		if (!CWebUser::$data || $idx2 === null) {
			return $default_value;
		}

		if (self::$profiles === null) {
			self::init();
		}

		if (array_key_exists($idx, self::$profiles)) {
			// When there is cached data for $idx but $idx2 was not found we should return default value.
			return array_key_exists($idx2, self::$profiles[$idx]) ? self::$profiles[$idx][$idx2] : $default_value;
		}

		self::$profiles[$idx] = [];
		// Aggressive caching, cache all items matched $idx key.
		$query = DBselect(
			'SELECT type,value_id,value_int,value_str,idx2'.
			' FROM profiles'.
			' WHERE userid='.self::$userDetails['userid'].
				' AND idx='.zbx_dbstr($idx)
		);

		while ($row = DBfetch($query)) {
			$value_type = self::getFieldByType($row['type']);

			self::$profiles[$idx][$row['idx2']] = $row[$value_type];
		}

		return array_key_exists($idx2, self::$profiles[$idx]) ? self::$profiles[$idx][$idx2] : $default_value;
	}
}
?>
