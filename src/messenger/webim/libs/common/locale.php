<?php
/*
 * Copyright 2005-2013 the original author or authors.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

require_once(dirname(__FILE__) . '/converter.php');
require_once(dirname(__FILE__) . '/verification.php');

function myiconv($in_enc, $out_enc, $string)
{
	global $_utf8win1251, $_win1251utf8;
	if ($in_enc == $out_enc) {
		return $string;
	}
	if (function_exists('iconv')) {
		$converted = @iconv($in_enc, $out_enc, $string);
		if ($converted !== FALSE) {
			return $converted;
		}
	}
	if ($in_enc == "cp1251" && $out_enc == "utf-8")
		return strtr($string, $_win1251utf8);
	if ($in_enc == "utf-8" && $out_enc == "cp1251")
		return strtr($string, $_utf8win1251);

	return $string; // do not know how to convert
}

function locale_exists($locale)
{
	return file_exists(dirname(__FILE__) . "/../../locales/$locale/properties");
}

function get_available_locales()
{
	global $locale_pattern;
	$list = array();
	$folder = dirname(__FILE__) . "/../../locales";
	if ($handle = opendir($folder)) {
		while (false !== ($file = readdir($handle))) {
			if (preg_match($locale_pattern, $file) && $file != 'names' && is_dir("$folder/$file")) {
				$list[] = $file;
			}
		}
		closedir($handle);
	}
	sort($list);
	return $list;
}

function get_user_locale()
{
	global $default_locale;

	if (isset($_COOKIE['webim_locale'])) {
		$requested_lang = $_COOKIE['webim_locale'];
		if (locale_exists($requested_lang))
			return $requested_lang;
	}

	if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
		$requested_langs = explode(",", $_SERVER['HTTP_ACCEPT_LANGUAGE']);
		foreach ($requested_langs as $requested_lang) {
			if (strlen($requested_lang) > 2)
				$requested_lang = substr($requested_lang, 0, 2);

			if (locale_exists($requested_lang))
				return $requested_lang;
		}
	}

	if (locale_exists($default_locale))
		return $default_locale;

	return 'en';
}

function get_locale()
{
	global $webimroot, $locale_pattern;

	$locale = verifyparam("locale", $locale_pattern, "");

	if ($locale && locale_exists($locale)) {
		$_SESSION['locale'] = $locale;
		setcookie('webim_locale', $locale, time() + 60 * 60 * 24 * 1000, "$webimroot/");
	} else if (isset($_SESSION['locale'])) {
		$locale = $_SESSION['locale'];
	}

	if (!$locale || !locale_exists($locale))
		$locale = get_user_locale();
	return $locale;
}

function get_locale_links($href)
{
	global $current_locale;
	$localeLinks = array();
	$allLocales = get_available_locales();
	if (count($allLocales) < 2) {
		return null;
	}
	foreach ($allLocales as $k) {
		$localeLinks[$k] = getlocal_($k, "names");
	}
	return $localeLinks;
}

/**
 * Load localized messages id some service locale info.
 *
 * @global array $messages Localized messages array
 * @global array $output_encoding Array of mapping locales to output encodings
 *
 * @param string $locale Name of a locale whose messages should be loaded.
 */
function load_messages($locale) {
	global $messages, $output_encoding;

	// Load core localization
	$locale_file = dirname(__FILE__) . "/../../locales/{$locale}/properties";
	$locale_data = read_locale_file($locale_file);

	if (! is_null($locale_data['output_encoding'])) {
		$output_encoding[$locale] = $locale_data['output_encoding'];
	}

	$messages[$locale] = $locale_data['messages'];

	// Load active plugins localization
	$plugins_list = array_keys(PluginManager::getAllPlugins());

	foreach($plugins_list as $plugin_name) {
		$locale_file = dirname(__FILE__) .
			"/../../plugins/{$plugin_name}/locales/{$locale}/properties";
		if (is_readable($locale_file)) {
			$locale_data = read_locale_file($locale_file);
			// array_merge used to provide an ability for plugins to override
			// localized strings
			$messages[$locale] = array_merge(
				$messages[$locale],
				$locale_data['messages']
			);
		}
	}
}

/**
 * Read and parse locale file.
 *
 * @global string $webim_encoding Internal Mibew encoding. Defined in
 * libs/config.php.
 *
 * @param string $path Locale file path
 * @return array Associative array with following keys:
 *  - 'encoding': string, one of service field from locale file, determines
 *    encoding of strings in the locale file. If there is no 'encoding' field in
 *    the locale file, this variable will be equal to $webim_encoding.
 *
 *  - 'output_encoding': string, one of service field from locale file,
 *    determines in what encoding document should be output for this locale.
 *    If there is no 'output_encoding' field in the locale file, this variable
 *    will bew equal to NULL.
 *
 *  - 'messages': associative array of localized strings. The keys of the array
 *    are localization keys and the values of the array are localized strings.
 *    All localized strings have internal Mibew encoding(see $webim_encoding
 *    value in libs/config.php).
 */
function read_locale_file($path) {
	global $webim_encoding;

	// Set default values
	$current_encoding = $webim_encoding;
	$output_encoding = null;
	$messages = array();

	$fp = fopen($path, "r");
	while (!feof($fp)) {
		$line = fgets($fp, 4096);
		// Try to get key and value from locale file line
		$line_parts = preg_split("/=/", $line, 2);
		if (count($line_parts) == 2) {
			$key = $line_parts[0];
			$value = $line_parts[1];
			// Check if key is service field and treat it as
			// localized string otherwise
			if ($key == 'encoding') {
				$current_encoding = trim($value);
			} else if ($key == 'output_encoding') {
				$output_encoding = trim($value);
			} else if ($current_encoding == $webim_encoding) {
				$messages[$key] = str_replace("\\n", "\n", trim($value));
			} else {
				$messages[$key] = myiconv(
					$current_encoding,
					$webim_encoding,
					str_replace("\\n", "\n", trim($value))
				);
			}
		}
	}
	fclose($fp);

	return array(
		'encoding' => $current_encoding,
		'output_encoding' => $output_encoding,
		'messages' => $messages
	);
}

function getoutputenc()
{
	global $current_locale, $output_encoding, $webim_encoding, $messages;
	if (!isset($messages[$current_locale]))
		load_messages($current_locale);
	return isset($output_encoding[$current_locale]) ? $output_encoding[$current_locale] : $webim_encoding;
}

function getstring_($text, $locale)
{
	global $messages;
	if (!isset($messages[$locale]))
		load_messages($locale);

	$localized = $messages[$locale];
	if (isset($localized[$text]))
		return $localized[$text];
	if ($locale != 'en') {
		return getstring_($text, 'en');
	}

	return "!" . $text;
}

function getstring($text)
{
	global $current_locale;
	return getstring_($text, $current_locale);
}

function getlocal($text)
{
	global $current_locale, $webim_encoding;
	return myiconv($webim_encoding, getoutputenc(), getstring_($text, $current_locale));
}

function getlocal_($text, $locale)
{
	global $webim_encoding;
	return myiconv($webim_encoding, getoutputenc(), getstring_($text, $locale));
}

function getstring2_($text, $params, $locale)
{
	$string = getstring_($text, $locale);
	for ($i = 0; $i < count($params); $i++) {
		$string = str_replace("{" . $i . "}", $params[$i], $string);
	}
	return $string;
}

function getstring2($text, $params)
{
	global $current_locale;
	return getstring2_($text, $params, $current_locale);
}

function getlocal2($text, $params)
{
	global $current_locale, $webim_encoding;
	$string = myiconv($webim_encoding, getoutputenc(), getstring_($text, $current_locale));
	for ($i = 0; $i < count($params); $i++) {
		$string = str_replace("{" . $i . "}", $params[$i], $string);
	}
	return $string;
}

/* prepares for Javascript string */
function getlocalforJS($text, $params)
{
	global $current_locale, $webim_encoding;
	$string = myiconv($webim_encoding, getoutputenc(), getstring_($text, $current_locale));
	$string = str_replace("\"", "\\\"", str_replace("\n", "\\n", $string));
	for ($i = 0; $i < count($params); $i++) {
		$string = str_replace("{" . $i . "}", $params[$i], $string);
	}
	return $string;
}

$locale_pattern = "/^[\w-]{2,5}$/";
$current_locale = get_locale();
$messages = array();
$output_encoding = array();

?>