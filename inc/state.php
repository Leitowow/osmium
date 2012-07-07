<?php
/* Osmium
 * Copyright (C) 2012 Romain "Artefact2" Dalmaso <artefact2@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Osmium\State;

/** Global variable that stores all Osmium-related session state.
 *
 * FIXME: get rid of this */
$__osmium_state =& $_SESSION['__osmium_state'];

/** Global variable that stores all login-related errors.
 *
 * FIXME: get rid of this */
$__osmium_login_state = array();

/** @internal */
$__osmium_memcached = null;

/** @internal */
$__osmium_cache_stack = array();

/** @internal */
$__osmium_cache_enabled = true;

/** Default expire duration for the token cookie. 7 days. */
const COOKIE_AUTH_DURATION = 604800;

/** Access mask required for API keys. */
const REQUIRED_ACCESS_MASK = 33554440; /* CharacterSheet+AccountStatus */

/**
 * Checks whether the current user is logged in.
 */
function is_logged_in() {
	global $__osmium_state;
	return isset($__osmium_state['a']['accountid']) && $__osmium_state['a']['accountid'] > 0;
}

/**
 * Hook that gets called when the user successfully logged in (either
 * directly from the login form, or from a cookie token).
 *
 * @param $account_name the name of the account the user logged into.
 *
 * @param $use_tookie if true, a cookie must be set to mimic a
 * "remember me" feature.
 */
function do_post_login($account_name, $use_cookie = false) {
	global $__osmium_state;

	$q = \Osmium\Db\query_params('SELECT accountid, accountname, nickname,
	creationdate, lastlogindate,
	keyid, verificationcode, apiverified,
	characterid, charactername, corporationid, corporationname, allianceid, alliancename,
	ismoderator, isfittingmanager FROM osmium.accounts WHERE accountname = $1', array($account_name));
	$a = \Osmium\Db\fetch_assoc($q);
	check_api_key($a);
	$__osmium_state['a'] = $a;

	if($use_cookie) {
		$token = uniqid('Osmium_', true);
		$account_id = $__osmium_state['a']['accountid'];
		$attributes = get_client_attributes();
		$expiration_date = time() + COOKIE_AUTH_DURATION;

		\Osmium\Db\query_params('INSERT INTO osmium.cookietokens (token, accountid, clientattributes, expirationdate) VALUES ($1, $2, $3, $4)', array($token, $account_id, $attributes, $expiration_date));

		setcookie('Osmium', $token, $expiration_date, '/');
	}

	\Osmium\Db\query_params('UPDATE osmium.accounts SET lastlogindate = $1 WHERE accountid = $2', array(time(), $__osmium_state['a']['accountid']));
}

/**
 * Logs off the current user.
 *
 * @param $gloal if set to true, also delete all the cookie tokens
 * associated with the current account.
 */
function logoff($global = false) {
	global $__osmium_state;
	if($global && !is_logged_in()) return;

	if($global) {
		$account_id = $__osmium_state['a']['accountid'];
		\Osmium\Db\query_params('DELETE FROM osmium.cookietokens WHERE accountid = $1', array($account_id));
	}

	setcookie('Osmium', false, 0, '/');
	unset($__osmium_state['a']);
}

/**
 * Get a string that identifies a visitor. Cookie token-based
 * authentication will only succeed if the client attributes of the
 * visitor match the ones generated when the cookie was set.
 */
function get_client_attributes() {
	return hash('sha256', serialize(array($_SERVER['REMOTE_ADDR'],
	                                      $_SERVER['HTTP_USER_AGENT'],
	                                      $_SERVER['HTTP_ACCEPT'],
	                                      $_SERVER['HTTP_HOST']
		                                )));
}

/**
 * Check whether the client attributes of the current visitor match
 * with given attributes.
 */
function check_client_attributes($attributes) {
	return $attributes === get_client_attributes();
}

/**
 * Prints the login box if the current user is not logged in, or print
 * the logout box if the user is logged in.
 */
function print_login_or_logout_box($relative) {
	if(is_logged_in()) {
		print_logoff_box($relative);
	} else {
		print_login_box($relative);
	}
}

/** @internal */
function print_login_box($relative) {
	$value = isset($_POST['account_name']) ? "value='".htmlspecialchars($_POST['account_name'], ENT_QUOTES)."' " : '';
	$remember = (isset($_POST['account_name']) && (!isset($_POST['remember']) || $_POST['remember'] !== 'on')) ? '' : "checked='checked' ";
  
	global $__osmium_login_state;
	if(isset($__osmium_login_state['error'])) {
		$error = "<p class='error_box'>\n".$__osmium_login_state['error']."\n</p>\n";
	} else $error = '';

	echo "<div id='state_box' class='login'>\n";
	echo "<form method='post' action='".htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES)."'>\n";
	echo "$error<p>\n<input type='text' name='account_name' placeholder='Account name' $value/>\n";
	echo "<input type='password' name='password' placeholder='Password' />\n";
	echo "<input type='submit' name='__osmium_login' value='Login' /> (<small><input type='checkbox' name='remember' id='remember' $remember/> <label for='remember'>Remember me</label></small>) or <a href='$relative/register'>create an account</a><br />\n";
	echo "</p>\n</form>\n</div>\n";
}

/** @internal */
function print_logoff_box($relative) {
	global $__osmium_state;
	$a = $__osmium_state['a'];
	$tok = urlencode(get_token());

	$portrait = '';
	if(isset($a['apiverified']) && $a['apiverified'] === 't' &&
	   isset($a['characterid']) && $a['characterid'] > 0) {
		$id = $a['characterid'];
		$portrait = "<img src='http://image.eveonline.com/Character/${id}_32.jpg' alt='' class='portrait' /> ";
	}

	echo "<div id='state_box' class='logout'>\n<p>\nLogged in as $portrait<strong>".\Osmium\Chrome\format_character_name($a, $relative)."</strong>. <a href='$relative/logout?tok=$tok'>Logout</a> (<a href='$relative/logout?tok=$tok'>this session</a> / <a href='$relative/logout?tok=$tok&amp;global=1'>all sessions</a>)\n</p>\n</div>\n";
}

/**
 * Returns a password hash string derived from $pw. Already does
 * salting internally.
 */
function hash_password($pw) {
	require_once \Osmium\ROOT.'/lib/PasswordHash.php';
	$pwHash = new \PasswordHash(10, true);
	return $pwHash->HashPassword($pw);
}

/**
 * Checks whether a password matches against a hash returned by
 * hash_password().
 *
 * @param $pw the password to test.
 *
 * @param $hash the hash previously returned by hash_password().
 *
 * @returns true if $pw matches against $hash.
 */
function check_password($pw, $hash) {
	require_once \Osmium\ROOT.'/lib/PasswordHash.php';
	$pwHash = new \PasswordHash(10, true);
	return $pwHash->CheckPassword($pw, $hash);
}

/**
 * Try to login the current visitor, taking credentials directly from
 * $_POST.
 */
function try_login() {
	if(is_logged_in()) return;

	$account_name = $_POST['account_name'];
	$pw = $_POST['password'];
	$remember = isset($_POST['remember']) && $_POST['remember'] === 'on';

	list($hash) = \Osmium\Db\fetch_row(\Osmium\Db\query_params('SELECT passwordhash FROM osmium.accounts WHERE accountname = $1', array($account_name)));

	if(check_password($pw, $hash)) {
		do_post_login($account_name, $remember);
	} else {
		global $__osmium_login_state;
		$__osmium_login_state['error'] = 'Invalid credentials. Please check your account name and password.';
	}
}

/**
 * Try to re-log a user from a cookie token. If the token happens to
 * be invalid, the cookie is deleted.
 */
function try_recover() {
	if(is_logged_in()) return;

	if(!isset($_COOKIE['Osmium']) || empty($_COOKIE['Osmium'])) return;
	$token = $_COOKIE['Osmium'];
	$now = time();
	$login = false;

	list($has_token) = pg_fetch_row(\Osmium\Db\query_params('SELECT COUNT(token) FROM osmium.cookietokens WHERE token = $1 AND expirationdate >= $2', array($token, $now)));

	if($has_token == 1) {
		list($account_id, $client_attributes) = pg_fetch_row(\Osmium\Db\query_params('SELECT accountid, clientattributes FROM osmium.cookietokens WHERE token = $1', array($token)));

		if(check_client_attributes($client_attributes)) {
			$k = pg_fetch_row(\Osmium\Db\query_params('SELECT accountname FROM osmium.accounts WHERE accountid = $1', array($account_id)));
			if($k !== false) {
				list($name) = $k;
				do_post_login($name, true);
				$login = true;
			}
		}

		\Osmium\Db\query_params('DELETE FROM osmium.cookietokens WHERE token = $1', array($token));
	}

	if(!$login) {
		logoff(false); /* Delete that erroneous cookie */
	}
}

/**
 * Check that a given API key can be used to API-verify an account.
 *
 * @returns true, or a string containing an error message.
 */
function check_api_key_sanity($accountid, $keyid, $vcode) {
	$api = \Osmium\EveApi\fetch('/account/APIKeyInfo.xml.aspx', array('keyID' => $keyid, 'vCode' => $vcode));

	if(!($api instanceof \SimpleXMLElement)) {
		/* Aouch */
		return "API sever did not return well-formed XML. Network issue, or internal CCP screwage, sorry!";
	}

	if(isset($api->error) && !empty($api->error)) {
		return '('.((int)$api->error['code']).') '.(string)$api->error;
	}

	if((string)$api->result->key["type"] !== 'Character') {
	    return 'Invalid key type. Make sure you only select one character.';
	}

	if((int)$api->result->key["accessMask"] !== REQUIRED_ACCESS_MASK) {
		return 'Incorrect access mask. Please set it to '.\Osmium\State\REQUIRED_ACCESS_MASK.' (or use the link above).';
	}

	$cid = (int)$api->result->key->rowset->row['characterID'];
	$cname = (string)$api->result->key->rowset->row['characterName'];
	list($c) = \Osmium\Db\fetch_row(\Osmium\Db\query_params('SELECT COUNT(accountid) FROM osmium.accounts WHERE accountid <> $1 AND (characterid = $2 OR charactername = $3)', array($accountid, $cid, $cname)));
	if($c > 0) {
		return "Character <strong>".htmlspecialchars($cname)."</strong> is already used by another account.";
	}

	return true;
}

/**
 * Check the API key associated with the current account, and update
 * character/corp/alliance values in the database.
 *
 * @returns null on serious error, or a boolean indicating if the user
 * must revalidate his API key.
 */
function check_api_key($a, $initial = false) {
	if(!isset($a['keyid']) || !isset($a['verificationcode'])
	   || $a['keyid'] === null || $a['verificationcode'] === null) return null;

	$key_id = $a['keyid'];
	$v_code = $a['verificationcode'];
	$must_renew = false;

	$info = \Osmium\EveApi\fetch('/account/APIKeyInfo.xml.aspx',
	                             array('keyID' => $key_id, 'vCode' => $v_code));

	if(!($info instanceof \SimpleXMLElement)) {
		/* Could be anything major, ignore */
		return null;
	}

	if(isset($info->error) && !empty($info->error)) {
		$err_code = (int)$info->error['code'];
		/* Error code details: http://wiki.eve-id.net/APIv2_Eve_ErrorList_XML */
		if(200 <= $err_code && $err_code < 300) {
			$must_renew = true;
		} else {
			/* Most likely internal error */
			return;
		}
	}

	if(!$must_renew
	   && (string)$info->result->key["type"] !== 'Character'
	   || (int)$info->result->key['accessMask'] !== REQUIRED_ACCESS_MASK
	   || (!$initial && (int)$info->result->key->rowset->row['characterID'] != $a['characterid'])) {
		$must_renew = true;
	}

	if($must_renew) {
		\Osmium\Db\query_params('UPDATE osmium.accounts SET
		characterid = null, charactername = null,
		corporationid = null, corporationname = null,
		allianceid = null, alliancename = null,
		isfittingmanager = false, apiverified = false
		WHERE accountid = $1', array($a['accountid']));

		$a['characterid'] = null;
		$a['charactername'] = null;
		$a['corporationid'] = null;
		$a['corporationname'] = null;
		$a['allianceid'] = null;
		$a['alliancename'] = null;
		$a['apiverified'] = 'f';
	} else if(isset($a['apiverified']) && $a['apiverified'] === 't') {
		$character_id = (int)$info->result->key->rowset->row['characterID'];

		list($character_name,
		     $corporation_id, $corporation_name,
		     $alliance_id, $alliance_name,
		     $is_fitting_manager) = \Osmium\State\get_character_info($character_id);

		if($character_id != $a['characterid']
		   || $character_name != $a['charactername']
		   || $corporation_id != $a['corporationid']
		   || $corporation_name != $a['corporationname']
		   || $alliance_id != $a['allianceid']
		   || $alliance_name != $a['alliancename']
		   || $is_fitting_manager != ($a['isfittingmanager'] === 't')) {

			\Osmium\Db\query_params('UPDATE osmium.accounts SET
			characterid = $1, charactername = $2,
			corporationid = $3, corporationname = $4,
		    allianceid = $5, alliancename = $6,
			isfittingmanager = $7
			WHERE accountid = $8', array($character_id, $character_name,
			                             $corporation_id, $corporation_name,
			                             $alliance_id, $alliance_name,
			                             $is_fitting_manager ? 't' : 'f',
			                             $a['accountid']));

			$a['characterid'] = $character_id;
			$a['charactername'] = $character_name;
			$a['corporationid'] = $corporation_id;
			$a['corporationname'] = $corporation_name;
			$a['allianceid'] = $alliance_id;
			$a['alliancename'] = $alliance_name;
			$a['isfittingmanager'] = $is_fitting_manager ? 't' : 'f';
		}
	}

	\Osmium\State\put_state('a', $a);
	return $must_renew;
}

function get_character_info($character_id) {
	$char_info = \Osmium\EveApi\fetch('/eve/CharacterInfo.xml.aspx', array('characterID' => $character_id));
  
	$character_name = (string)$char_info->result->characterName;
	$corporation_id = (int)$char_info->result->corporationID;
	$corporation_name = (string)$char_info->result->corporation;
	$alliance_id = (int)$char_info->result->allianceID;
	$alliance_name = (string)$char_info->result->alliance;
  
	if($alliance_id == 0) $alliance_id = null;
	if($alliance_name == '') $alliance_name = null;

	$a = \Osmium\State\get_state('a');
	$char_sheet = \Osmium\EveApi\fetch('/char/CharacterSheet.xml.aspx', 
	                                   array(
		                                   'characterID' => $character_id, 
		                                   'keyID' => $a['keyid'],
		                                   'vCode' => $a['verificationcode'],
		                                   ));

	$is_fitting_manager = false;
	foreach(($char_sheet->result->rowset ?: array()) as $rowset) {
		if((string)$rowset['name'] != 'corporationRoles') continue;

		foreach($rowset->children() as $row) {
			$name = (string)$row['roleName'];
			if($name == 'roleFittingManager' || $name == 'roleDirector') {
				/* FIXME: roleFittingManager may be implicitly granted by other roles. */
				$is_fitting_manager = true;
				break;
			}
		}

		break;
	}
  
	return array($character_name, $corporation_id, $corporation_name, $alliance_id, $alliance_name, (int)$is_fitting_manager);
}

/**
 * Get a setting previously stored with put_setting().
 *
 * @param $default the value to return if $key is not found.
 */
function get_setting($key, $default = null) {
	if(!is_logged_in()) return $default;

	global $__osmium_state;
	$accountid = $__osmium_state['a']['accountid'];

	$k = \Osmium\Db\query_params('SELECT value FROM osmium.accountsettings WHERE accountid = $1 AND key = $2', array($accountid, $key));
	while($r = \Osmium\Db\fetch_row($k)) {
		$ret = $r[0];
	}

	return isset($ret) ? unserialize($ret) : $default;
}

/**
 * Store a setting. Settings are account-bound, database-stored and
 * persistent between sessions.
 */
function put_setting($key, $value) {
	if(!is_logged_in()) return;

	global $__osmium_state;
	$accountid = $__osmium_state['a']['accountid'];
	\Osmium\Db\query_params('DELETE FROM osmium.accountsettings WHERE accountid = $1 AND key = $2', array($accountid, $key));
	\Osmium\Db\query_params('INSERT INTO osmium.accountsettings (accountid, key, value) VALUES ($1, $2, $3)', array($accountid, $key, serialize($value)));

	return $value;
}

/**
 * Get the session token of the current session. This is randomly
 * generated data, different than $PHPSESSID. (Mainly used for CSRF
 * tokens.)
 */
function get_token() {
	global $__osmium_state;

	if(!isset($__osmium_state['logouttoken'])) {
		$__osmium_state['logouttoken'] = uniqid('OsmiumTok_', true);
	}

	return $__osmium_state['logouttoken'];
}

/**
 * Get a state variable previously set via put_state().
 *
 * @param $default the value to return if $key is not found.
 */
function get_state($key, $default = null) {
	if(isset($_SESSION['__osmium_state'][$key])) {
		return $_SESSION['__osmium_state'][$key];
	} else return $default;
}

/**
 * Store a state variable. State variables are account-bound but do
 * not persist between sessions.
 */
function put_state($key, $value) {
	if(!isset($_SESSION['__osmium_state']) || !is_array($_SESSION['__osmium_state'])) {
		$_SESSION['__osmium_state'] = array();
	}

	return $_SESSION['__osmium_state'][$key] = $value;
}

/**
 * Override current cache setting. Nested calls behave correctly as
 * expected.
 *
 * @param $enable whether to enable or disable cache.
 */
function set_cache_enabled($enable = true) {
	global $__osmium_cache_stack;
	global $__osmium_cache_enabled;

	$__osmium_cache_stack[] = $__osmium_cache_enabled;
	$__osmium_cache_enabled = $enable;
}

/**
 * Undo the last override made by set_cache_enabled() and restore the
 * old setting. Supports nested calls.
 */
function pop_cache_enabled() {
	global $__osmium_cache_stack;
	global $__osmium_cache_enabled;

	$__osmium_cache_enabled = array_pop($__osmium_cache_stack);
}

/** @internal */
function init_memcached() {
	global $__osmium_memcached;

	if($__osmium_memcached === null) {
		$__osmium_memcached = new \Memcached();
		$servers = \Osmium\get_ini_setting('servers');
		foreach($servers as &$s) $s = explode(':', $s);
		$__osmium_memcached->addServers($servers);
	}
}

/**
 * Get a cache variable previously set by put_cache().
 *
 * @param $default the value to return if $key is not found in the
 * cache.
 */
function get_cache($key, $default = null) {
	global $__osmium_cache_enabled;
	if(!$__osmium_cache_enabled) return $default;

	global $__osmium_memcached;

	if(\Osmium\USE_MEMCACHED) {
		init_memcached();
		if(($v = $__osmium_memcached->get($key)) === false) {
			if($__osmium_memcached->getResultCode() === \Memcached::RES_NOTFOUND) {
				$v = $default;
			}
		}
		
		return $v;
	} else {
		$f = \Osmium\CACHE_DIRECTORY.'/OsmiumCache_'.hash('sha512', $key);
		if(file_exists($f)) return unserialize(file_get_contents($f));
		return $default;
	}
}

/**
 * Store a cache variable. Cache variables are not account-bound.
 *
 * @param $expires if set to zero, the cache value must never expire
 * unless explicitely invalidated. If not, *try* to keep the value in
 * cache for $expires seconds.
 */
function put_cache($key, $value, $expires = 0) {
	global $__osmium_cache_enabled;
	if(!$__osmium_cache_enabled) return;

	global $__osmium_memcached;

	if(\Osmium\USE_MEMCACHED) {
		init_memcached();
		return $__osmium_memcached->set($key, $value, $expires);
	} else {
		$f = \Osmium\CACHE_DIRECTORY.'/OsmiumCache_'.hash('sha512', $key);
		return file_put_contents($f, serialize($value));
	}
}

/**
 * Delete a cached variable.
 */
function invalidate_cache($key) {
	global $__osmium_cache_enabled;
	if(!$__osmium_cache_enabled) return;

	global $__osmium_memcached;

	if(\Osmium\USE_MEMCACHED) {
		init_memcached();
		$__osmium_memcached->delete($key);
	} else {
		$f = \Osmium\CACHE_DIRECTORY.'/OsmiumCache_'.hash('sha512', $key);
		if(file_exists($f)) unlink($f);
	}
}

/**
 * Get a state variable previously stored by put_state_trypersist().
 *
 * This is just a wrapper that uses settings for logged-in users, and
 * state vars for anonymous users.
 */
function get_state_trypersist($key, $default = null) {
	if(is_logged_in()) {
		return get_setting($key, $default);
	} else {
		return get_state('__setting_'.$key, $default);
	}
}

/**
 * Store a state variable, and try to persist changes as much as
 * possible.
 *
 * This is just a wrapper that uses settings for logged-in users, and
 * state vars for anonymous users.
 */
function put_state_trypersist($key, $value) {
	if(is_logged_in()) {
		/* For regular users, use database-stored persistent storage */
		return put_setting($key, $value);
	} else {
		/* For anonymous users, use session-based storage (not really persistent, but better than nothing) */
		return put_state('__setting_'.$key, $value);
	}
}

/**
 * Checks whether a loadout can be viewed (but maybe still needing a
 * password) by the current user.
 */
function can_view_fit($loadoutid) {
	if(is_logged_in()) {
		$a = get_state('a');
		list($count) = \Osmium\Db\fetch_row(\Osmium\Db\query_params('SELECT COUNT(loadoutid) FROM osmium.allowedloadoutsbyaccount WHERE loadoutid = $1 AND accountid = $2', array($loadoutid, $a['accountid'])));
	} else {
		list($count) = \Osmium\Db\fetch_row(\Osmium\Db\query_params('SELECT COUNT(loadoutid) FROM osmium.allowedloadoutsanonymous WHERE loadoutid = $1', array($loadoutid)));
	}

	return (boolean)$count;
}

/**
 * Checks whether a loadout can be viewed by the current user, and
 * that the user has been granted access if the fit is password
 * protected.
 */
function can_access_fit($fit) {
	if($fit['metadata']['view_permission'] == \Osmium\Fit\VIEW_PASSWORD_PROTECTED) {
		$pw = get_state('pw_fits', array());
		$a = get_state('a', array());

		/* Author of the loadout can always access his loadout */
		if(isset($a['accountid']) && $a['accountid'] == $fit['metadata']['accountid']) {
			return true;
		}

		/* Require password authorization */
		if(isset($pw[$fit['metadata']['loadoutid']]) && $pw[$fit['metadata']['loadoutid']] > time()) {
			return true;
		}

		return false;
	}

	return true;
}

/**
 * Checks whether a loadout can be edited by the current user.
 */
function can_edit_fit($loadoutid) {
	$can_edit = false;
	if(is_logged_in()) {
		$a = get_state('a');
		list($c) = \Osmium\Db\fetch_row(\Osmium\Db\query_params('SELECT COUNT(loadoutid) FROM osmium.editableloadoutsbyaccount WHERE loadoutid = $1 AND accountid = $2', array($loadoutid, $a['accountid'])));
		$can_edit = ($c == 1);
	}

	return $can_edit;
}

/**
 * Grant permission for the current user to see a password-protected
 * fit for a given duration. This does not circumvent can_view_fit(),
 * only makes can_access_fit() return true if the fit is password
 * protected.
 */
function grant_fit_access($fit, $duration) {
	$pw = get_state('pw_fits', array());
	$pw[$fit['metadata']['loadoutid']] = time() + $duration;
	put_state('pw_fits', $pw);
}
