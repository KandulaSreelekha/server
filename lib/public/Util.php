<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
// use OCP namespace for all classes that are considered public.
// This means that they should be used by apps instead of the internal Nextcloud classes

namespace OCP;

use bantu\IniGetWrapper\IniGetWrapper;
use OC\AppScriptDependency;
use OC\AppScriptSort;
use OC\Security\CSRF\CsrfTokenManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCP\Share\IManager;
use Psr\Container\ContainerExceptionInterface;

/**
 * This class provides different helper functions to make the life of a developer easier
 *
 * @since 4.0.0
 */
class Util {
	private static ?IManager $shareManager = null;

	private static array $scriptsInit = [];
	private static array $scripts = [];
	private static array $scriptDeps = [];

	/**
	 * get the current installed version of Nextcloud
	 * @return array
	 * @since 4.0.0
	 * @deprecated 31.0.0 Use \OCP\ServerVersion::getVersion
	 */
	public static function getVersion() {
		return Server::get(ServerVersion::class)->getVersion();
	}

	/**
	 * @since 17.0.0
	 */
	public static function hasExtendedSupport(): bool {
		try {
			/** @var \OCP\Support\Subscription\IRegistry */
			$subscriptionRegistry = Server::get(\OCP\Support\Subscription\IRegistry::class);
			return $subscriptionRegistry->delegateHasExtendedSupport();
		} catch (ContainerExceptionInterface $e) {
		}
		return \OC::$server->getConfig()->getSystemValueBool('extendedSupport', false);
	}

	/**
	 * Set current update channel
	 * @param string $channel
	 * @since 8.1.0
	 */
	public static function setChannel($channel) {
		\OC::$server->getConfig()->setSystemValue('updater.release.channel', $channel);
	}

	/**
	 * Get current update channel
	 * @return string
	 * @since 8.1.0
	 * @deprecated 31.0.0 Use \OCP\ServerVersion::getChannel
	 */
	public static function getChannel() {
		return \OCP\Server::get(ServerVersion::class)->getChannel();
	}

	/**
	 * check if sharing is disabled for the current user
	 *
	 * @return boolean
	 * @since 7.0.0
	 * @deprecated 9.1.0 Use Server::get(\OCP\Share\IManager::class)->sharingDisabledForUser
	 */
	public static function isSharingDisabledForUser() {
		if (self::$shareManager === null) {
			self::$shareManager = Server::get(IManager::class);
		}

		$user = Server::get(\OCP\IUserSession::class)->getUser();

		return self::$shareManager->sharingDisabledForUser($user?->getUID());
	}

	/**
	 * get l10n object
	 * @since 6.0.0 - parameter $language was added in 8.0.0
	 */
	public static function getL10N(string $application, ?string $language = null): IL10N {
		return Server::get(\OCP\L10N\IFactory::class)->get($application, $language);
	}

	/**
	 * Add a css file
	 *
	 * @param string $application application id
	 * @param ?string $file filename
	 * @param bool $prepend prepend the style to the beginning of the list
	 * @since 4.0.0
	 */
	public static function addStyle(string $application, ?string $file = null, bool $prepend = false): void {
		\OC_Util::addStyle($application, $file, $prepend);
	}

	/**
	 * Add a standalone init js file that is loaded for initialization
	 *
	 * Be careful loading scripts using this method as they are loaded early
	 * and block the initial page rendering. They should not have dependencies
	 * on any other scripts than core-common and core-main.
	 *
	 * @since 28.0.0
	 */
	public static function addInitScript(string $application, string $file): void {
		if (!empty($application)) {
			$path = "$application/js/$file";
		} else {
			$path = "js/$file";
		}

		// We need to handle the translation BEFORE the init script
		// is loaded, as the init script might use translations
		if ($application !== 'core' && !str_contains($file, 'l10n')) {
			self::addTranslations($application, null, true);
		}

		self::$scriptsInit[] = $path;
	}

	/**
	 * add a javascript file
	 *
	 * @param string $application
	 * @param string|null $file
	 * @param string $afterAppId
	 * @param bool $prepend
	 * @since 4.0.0
	 */
	public static function addScript(string $application, ?string $file = null, string $afterAppId = 'core', bool $prepend = false): void {
		if (!empty($application)) {
			$path = "$application/js/$file";
		} else {
			$path = "js/$file";
		}

		// Inject js translations if we load a script for
		// a specific app that is not core, as those js files
		// need separate handling
		if ($application !== 'core'
			&& $file !== null
			&& !str_contains($file, 'l10n')) {
			self::addTranslations($application);
		}

		// store app in dependency list
		if (!array_key_exists($application, self::$scriptDeps)) {
			self::$scriptDeps[$application] = new AppScriptDependency($application, [$afterAppId]);
		} else {
			self::$scriptDeps[$application]->addDep($afterAppId);
		}

		if ($prepend) {
			array_unshift(self::$scripts[$application], $path);
		} else {
			self::$scripts[$application][] = $path;
		}
	}

	/**
	 * Return the list of scripts injected to the page
	 *
	 * @return array
	 * @since 24.0.0
	 */
	public static function getScripts(): array {
		// Sort scriptDeps into sortedScriptDeps
		$scriptSort = \OC::$server->get(AppScriptSort::class);
		$sortedScripts = $scriptSort->sort(self::$scripts, self::$scriptDeps);

		// Flatten array and remove duplicates
		$sortedScripts = array_merge([self::$scriptsInit], $sortedScripts);
		$sortedScripts = array_merge(...array_values($sortedScripts));

		// Override core-common and core-main order
		if (in_array('core/js/main', $sortedScripts)) {
			array_unshift($sortedScripts, 'core/js/main');
		}
		if (in_array('core/js/common', $sortedScripts)) {
			array_unshift($sortedScripts, 'core/js/common');
		}

		return array_unique($sortedScripts);
	}

	/**
	 * Add a translation JS file
	 * @param string $application application id
	 * @param string $languageCode language code, defaults to the current locale
	 * @param bool $init whether the translations should be loaded early or not
	 * @since 8.0.0
	 */
	public static function addTranslations($application, $languageCode = null, $init = false) {
		if (is_null($languageCode)) {
			$languageCode = \OC::$server->get(IFactory::class)->findLanguage($application);
		}
		if (!empty($application)) {
			$path = "$application/l10n/$languageCode";
		} else {
			$path = "l10n/$languageCode";
		}

		if ($init) {
			self::$scriptsInit[] = $path;
		} else {
			self::$scripts[$application][] = $path;
		}
	}

	/**
	 * Add a custom element to the header
	 * If $text is null then the element will be written as empty element.
	 * So use "" to get a closing tag.
	 * @param string $tag tag name of the element
	 * @param array $attributes array of attributes for the element
	 * @param string $text the text content for the element
	 * @since 4.0.0
	 */
	public static function addHeader($tag, $attributes, $text = null) {
		\OC_Util::addHeader($tag, $attributes, $text);
	}

	/**
	 * Creates an absolute url to the given app and file.
	 * @param string $app app
	 * @param string $file file
	 * @param array $args array with param=>value, will be appended to the returned url
	 *                    The value of $args will be urlencoded
	 * @return string the url
	 * @since 4.0.0 - parameter $args was added in 4.5.0
	 */
	public static function linkToAbsolute($app, $file, $args = []) {
		$urlGenerator = \OC::$server->getURLGenerator();
		return $urlGenerator->getAbsoluteURL(
			$urlGenerator->linkTo($app, $file, $args)
		);
	}

	/**
	 * Creates an absolute url for remote use.
	 * @param string $service id
	 * @return string the url
	 * @since 4.0.0
	 */
	public static function linkToRemote($service) {
		$urlGenerator = \OC::$server->getURLGenerator();
		$remoteBase = $urlGenerator->linkTo('', 'remote.php') . '/' . $service;
		return $urlGenerator->getAbsoluteURL(
			$remoteBase . (($service[strlen($service) - 1] != '/') ? '/' : '')
		);
	}

	/**
	 * Returns the server host name without an eventual port number
	 * @return string the server hostname
	 * @since 5.0.0
	 */
	public static function getServerHostName() {
		$host_name = \OC::$server->getRequest()->getServerHost();
		// strip away port number (if existing)
		$colon_pos = strpos($host_name, ':');
		if ($colon_pos != false) {
			$host_name = substr($host_name, 0, $colon_pos);
		}
		return $host_name;
	}

	/**
	 * Returns the default email address
	 * @param string $user_part the user part of the address
	 * @return string the default email address
	 *
	 * Assembles a default email address (using the server hostname
	 * and the given user part, and returns it
	 * Example: when given lostpassword-noreply as $user_part param,
	 *     and is currently accessed via http(s)://example.com/,
	 *     it would return 'lostpassword-noreply@example.com'
	 *
	 * If the configuration value 'mail_from_address' is set in
	 * config.php, this value will override the $user_part that
	 * is passed to this function
	 * @since 5.0.0
	 */
	public static function getDefaultEmailAddress(string $user_part): string {
		$config = \OC::$server->getConfig();
		$user_part = $config->getSystemValueString('mail_from_address', $user_part);
		$host_name = self::getServerHostName();
		$host_name = $config->getSystemValueString('mail_domain', $host_name);
		$defaultEmailAddress = $user_part . '@' . $host_name;

		$mailer = \OC::$server->get(IMailer::class);
		if ($mailer->validateMailAddress($defaultEmailAddress)) {
			return $defaultEmailAddress;
		}

		// in case we cannot build a valid email address from the hostname let's fallback to 'localhost.localdomain'
		return $user_part . '@localhost.localdomain';
	}

	/**
	 * Converts string to int of float depending if it fits an int
	 * @param numeric-string|float|int $number numeric string
	 * @return int|float int if it fits, float if it is too big
	 * @since 26.0.0
	 */
	public static function numericToNumber(string|float|int $number): int|float {
		/* This is a hack to cast to (int|float) */
		return 0 + (string)$number;
	}

	/**
	 * Make a human file size (2048 to 2 kB)
	 * @param int|float $bytes file size in bytes
	 * @return string a human readable file size
	 * @since 4.0.0
	 */
	public static function humanFileSize(int|float $bytes): string {
		if ($bytes < 0) {
			return '?';
		}
		if ($bytes < 1024) {
			return "$bytes B";
		}
		$bytes = round($bytes / 1024, 0);
		if ($bytes < 1024) {
			return "$bytes KB";
		}
		$bytes = round($bytes / 1024, 1);
		if ($bytes < 1024) {
			return "$bytes MB";
		}
		$bytes = round($bytes / 1024, 1);
		if ($bytes < 1024) {
			return "$bytes GB";
		}
		$bytes = round($bytes / 1024, 1);
		if ($bytes < 1024) {
			return "$bytes TB";
		}

		$bytes = round($bytes / 1024, 1);
		return "$bytes PB";
	}

	/**
	 * Make a computer file size (2 kB to 2048)
	 * Inspired by: https://www.php.net/manual/en/function.filesize.php#92418
	 *
	 * @param string $str file size in a fancy format
	 * @return false|int|float a file size in bytes
	 * @since 4.0.0
	 */
	public static function computerFileSize(string $str): false|int|float {
		$str = strtolower($str);
		if (is_numeric($str)) {
			return Util::numericToNumber($str);
		}

		$bytes_array = [
			'b' => 1,
			'k' => 1024,
			'kb' => 1024,
			'mb' => 1024 * 1024,
			'm' => 1024 * 1024,
			'gb' => 1024 * 1024 * 1024,
			'g' => 1024 * 1024 * 1024,
			'tb' => 1024 * 1024 * 1024 * 1024,
			't' => 1024 * 1024 * 1024 * 1024,
			'pb' => 1024 * 1024 * 1024 * 1024 * 1024,
			'p' => 1024 * 1024 * 1024 * 1024 * 1024,
		];

		$bytes = (float)$str;

		if (preg_match('#([kmgtp]?b?)$#si', $str, $matches) && isset($bytes_array[$matches[1]])) {
			$bytes *= $bytes_array[$matches[1]];
		} else {
			return false;
		}

		return Util::numericToNumber(round($bytes));
	}

	/**
	 * connects a function to a hook
	 *
	 * @param string $signalClass class name of emitter
	 * @param string $signalName name of signal
	 * @param string|object $slotClass class name of slot
	 * @param string $slotName name of slot
	 * @return bool
	 *
	 * This function makes it very easy to connect to use hooks.
	 *
	 * TODO: write example
	 * @since 4.0.0
	 * @deprecated 21.0.0 use \OCP\EventDispatcher\IEventDispatcher::addListener
	 */
	public static function connectHook($signalClass, $signalName, $slotClass, $slotName) {
		return \OC_Hook::connect($signalClass, $signalName, $slotClass, $slotName);
	}

	/**
	 * Emits a signal. To get data from the slot use references!
	 * @param string $signalclass class name of emitter
	 * @param string $signalname name of signal
	 * @param array $params default: array() array with additional data
	 * @return bool true if slots exists or false if not
	 *
	 * TODO: write example
	 * @since 4.0.0
	 * @deprecated 21.0.0 use \OCP\EventDispatcher\IEventDispatcher::dispatchTypedEvent
	 */
	public static function emitHook($signalclass, $signalname, $params = []) {
		return \OC_Hook::emit($signalclass, $signalname, $params);
	}

	/**
	 * Cached encrypted CSRF token. Some static unit-tests of ownCloud compare
	 * multiple Template elements which invoke `callRegister`. If the value
	 * would not be cached these unit-tests would fail.
	 * @var string
	 */
	private static $token = '';

	/**
	 * Register an get/post call. This is important to prevent CSRF attacks
	 * @since 4.5.0
	 * @deprecated 32.0.0 directly use CsrfTokenManager instead
	 */
	public static function callRegister() {
		if (self::$token === '') {
			self::$token = \OC::$server->get(CsrfTokenManager::class)->getToken()->getEncryptedValue();
		}
		return self::$token;
	}

	/**
	 * Used to sanitize HTML
	 *
	 * This function is used to sanitize HTML and should be applied on any
	 * string or array of strings before displaying it on a web page.
	 *
	 * @param string|string[] $value
	 * @return ($value is array ? string[] : string) an array of sanitized strings or a single sanitized string, depends on the input parameter.
	 * @since 4.5.0
	 */
	public static function sanitizeHTML($value) {
		return \OC_Util::sanitizeHTML($value);
	}

	/**
	 * Public function to encode url parameters
	 *
	 * This function is used to encode path to file before output.
	 * Encoding is done according to RFC 3986 with one exception:
	 * Character '/' is preserved as is.
	 *
	 * @param string $component part of URI to encode
	 * @return string
	 * @since 6.0.0
	 */
	public static function encodePath($component) {
		return \OC_Util::encodePath($component);
	}

	/**
	 * Returns an array with all keys from input lowercased or uppercased. Numbered indices are left as is.
	 *
	 * @param array $input The array to work on
	 * @param int $case Either MB_CASE_UPPER or MB_CASE_LOWER (default)
	 * @param string $encoding The encoding parameter is the character encoding. Defaults to UTF-8
	 * @return array
	 * @since 4.5.0
	 */
	public static function mb_array_change_key_case($input, $case = MB_CASE_LOWER, $encoding = 'UTF-8') {
		return \OC_Helper::mb_array_change_key_case($input, $case, $encoding);
	}

	/**
	 * performs a search in a nested array
	 *
	 * @param array $haystack the array to be searched
	 * @param string $needle the search string
	 * @param mixed $index optional, only search this key name
	 * @return mixed the key of the matching field, otherwise false
	 * @since 4.5.0
	 * @deprecated 15.0.0
	 */
	public static function recursiveArraySearch($haystack, $needle, $index = null) {
		return \OC_Helper::recursiveArraySearch($haystack, $needle, $index);
	}

	/**
	 * calculates the maximum upload size respecting system settings, free space and user quota
	 *
	 * @param string $dir the current folder where the user currently operates
	 * @param int|float|null $free the number of bytes free on the storage holding $dir, if not set this will be received from the storage directly
	 * @return int|float number of bytes representing
	 * @since 5.0.0
	 */
	public static function maxUploadFilesize(string $dir, int|float|null $free = null): int|float {
		return \OC_Helper::maxUploadFilesize($dir, $free);
	}

	/**
	 * Calculate free space left within user quota
	 * @param string $dir the current folder where the user currently operates
	 * @return int|float number of bytes representing
	 * @since 7.0.0
	 */
	public static function freeSpace(string $dir): int|float {
		return \OC_Helper::freeSpace($dir);
	}

	/**
	 * Calculate PHP upload limit
	 *
	 * @return int|float number of bytes representing
	 * @since 7.0.0
	 */
	public static function uploadLimit(): int|float {
		return \OC_Helper::uploadLimit();
	}

	/**
	 * Compare two strings to provide a natural sort
	 * @param string $a first string to compare
	 * @param string $b second string to compare
	 * @return int -1 if $b comes before $a, 1 if $a comes before $b
	 *             or 0 if the strings are identical
	 * @since 7.0.0
	 */
	public static function naturalSortCompare($a, $b) {
		return \OC\NaturalSort::getInstance()->compare($a, $b);
	}

	/**
	 * Check if a password is required for each public link
	 *
	 * @param bool $checkGroupMembership Check group membership exclusion
	 * @return boolean
	 * @since 7.0.0
	 */
	public static function isPublicLinkPasswordRequired(bool $checkGroupMembership = true) {
		return \OC_Util::isPublicLinkPasswordRequired($checkGroupMembership);
	}

	/**
	 * check if share API enforces a default expire date
	 * @return boolean
	 * @since 8.0.0
	 */
	public static function isDefaultExpireDateEnforced() {
		return \OC_Util::isDefaultExpireDateEnforced();
	}

	protected static $needUpgradeCache = null;

	/**
	 * Checks whether the current version needs upgrade.
	 *
	 * @return bool true if upgrade is needed, false otherwise
	 * @since 7.0.0
	 */
	public static function needUpgrade() {
		if (!isset(self::$needUpgradeCache)) {
			self::$needUpgradeCache = \OC_Util::needUpgrade(\OC::$server->getSystemConfig());
		}
		return self::$needUpgradeCache;
	}

	/**
	 * Sometimes a string has to be shortened to fit within a certain maximum
	 * data length in bytes. substr() you may break multibyte characters,
	 * because it operates on single byte level. mb_substr() operates on
	 * characters, so does not ensure that the shortened string satisfies the
	 * max length in bytes.
	 *
	 * For example, json_encode is messing with multibyte characters a lot,
	 * replacing them with something along "\u1234".
	 *
	 * This function shortens the string with by $accuracy (-5) from
	 * $dataLength characters, until it fits within $dataLength bytes.
	 *
	 * @since 23.0.0
	 */
	public static function shortenMultibyteString(string $subject, int $dataLength, int $accuracy = 5): string {
		$temp = mb_substr($subject, 0, $dataLength);
		// json encodes encapsulates the string in double quotes, they need to be substracted
		while ((strlen(json_encode($temp)) - 2) > $dataLength) {
			$temp = mb_substr($temp, 0, -$accuracy);
		}
		return $temp;
	}

	/**
	 * Check if a function is enabled in the php configuration
	 *
	 * @since 25.0.0
	 */
	public static function isFunctionEnabled(string $functionName): bool {
		if (!function_exists($functionName)) {
			return false;
		}
		$ini = Server::get(IniGetWrapper::class);
		$disabled = explode(',', $ini->get('disable_functions') ?: '');
		$disabled = array_map('trim', $disabled);
		if (in_array($functionName, $disabled)) {
			return false;
		}
		return true;
	}
}
