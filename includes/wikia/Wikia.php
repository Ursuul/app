<?php

/**
 * @package MediaWiki
 * @author Krzysztof Krzyżaniak <eloy@wikia.com> for Wikia.com
 * @copyright (C) 2007, Wikia Inc.
 * @licence GNU General Public Licence 2.0 or later
 * @version: $Id: Classes.php 6127 2007-10-11 11:10:32Z eloy $
 */

$wgAjaxExportList[] = 'WikiaAssets::combined';

/*
 * hooks
 */
$wgHooks['SpecialRecentChangesFilters'][] = "Wikia::addRecentChangesFilters";
$wgHooks['SpecialRecentChangesQuery'][] = "Wikia::makeRecentChangesQuery";
$wgHooks['SpecialPage_initList']     []	= "Wikia::disableSpecialPage";
$wgHooks['UserRights']               [] = "Wikia::notifyUserOnRightsChange";
$wgHooks['SetupAfterCache']          [] = "Wikia::setupAfterCache";
$wgHooks['ComposeMail']              [] = "Wikia::ComposeMail";
$wgHooks['SoftwareInfo']             [] = "Wikia::softwareInfo";
$wgHooks['ComposeMail']              [] = "Wikia::isUnsubscribed";
$wgHooks['AllowNotifyOnPageChange']  [] = "Wikia::allowNotifyOnPageChange";
$wgHooks['AfterInitialize']          [] = "Wikia::onAfterInitialize";
$wgHooks['UserMailerSend']           [] = "Wikia::onUserMailerSend";
$wgHooks['ArticleDeleteComplete']    [] = "Wikia::onArticleDeleteComplete";
$wgHooks['ContributionsToolLinks']   [] = 'Wikia::onContributionsToolLinks';
$wgHooks['AjaxAddScript']            [] = 'Wikia::onAjaxAddScript';
$wgHooks['TitleGetSquidURLs']        [] = 'Wikia::onTitleGetSquidURLs';
$wgHooks['userCan']                  [] = 'Wikia::canEditInterfaceWhitelist';
$wgHooks['getUserPermissionsErrors'] [] = 'Wikia::canEditInterfaceWhitelistErrors';

# changes in recentchanges (MultiLookup)
$wgHooks['RecentChange_save']        [] = "Wikia::recentChangesSave";
$wgHooks['BeforeInitialize']         [] = "Wikia::onBeforeInitializeMemcachePurge";
$wgHooks['SkinTemplateOutputPageBeforeExec'][] = "Wikia::onSkinTemplateOutputPageBeforeExec";
$wgHooks['UploadVerifyFile']         [] = 'Wikia::onUploadVerifyFile';

# User hooks
$wgHooks['UserNameLoadFromId']       [] = "Wikia::onUserNameLoadFromId";
$wgHooks['UserLoadFromDatabase']     [] = "Wikia::onUserLoadFromDatabase";

# Swift file backend
$wgHooks['AfterSetupLocalFileRepo']  [] = "Wikia::onAfterSetupLocalFileRepo";
$wgHooks['BeforeRenderTimeline']     [] = "Wikia::onBeforeRenderTimeline";

# remove "Temp_file_" from purger queue - BAC-1221
$wgHooks['LocalFileExecuteUrls']     []  = 'Wikia::onLocalFileExecuteUrls';

# send ETag response header - BAC-1227
$wgHooks['ParserCacheGetETag']       [] = 'Wikia::onParserCacheGetETag';

# Add X-Served-By and X-Backend-Response-Time response headers - BAC-550
$wgHooks['BeforeSendCacheControl']    [] = 'Wikia::onBeforeSendCacheControl';
$wgHooks['ResourceLoaderAfterRespond'][] = 'Wikia::onResourceLoaderAfterRespond';
$wgHooks['NirvanaAfterRespond']       [] = 'Wikia::onNirvanaAfterRespond';
$wgHooks['ApiMainBeforeSendCacheHeaders'][] = 'Wikia::onApiMainBeforeSendCacheHeaders';
$wgHooks['AjaxResponseSendHeadersAfter'][] = 'Wikia::onAjaxResponseSendHeadersAfter';

# don't purge all variants of articles in Chinese - BAC-1278
$wgHooks['TitleGetLangVariants'][] = 'Wikia::onTitleGetLangVariants';

# don't purge all thumbs - PLATFORM-161
$wgHooks['LocalFilePurgeThumbnailsUrls'][] = 'Wikia::onLocalFilePurgeThumbnailsUrls';

$wgHooks['BeforePageDisplay'][] = 'Wikia::onBeforePageDisplay';
$wgHooks['GetPreferences'][] = 'Wikia::onGetPreferences';
$wgHooks['WikiaSkinTopScripts'][] = 'Wikia::onWikiaSkinTopScripts';

# handle internal requests - PLATFORM-1473
$wgHooks['WebRequestInitialized'][] = 'Wikia::onWebRequestInitialized';

# Log user email changes
$wgHooks['BeforeUserSetEmail'][] = 'Wikia::logEmailChanges';

use Wikia\Tracer\WikiaTracer;

/**
 * This class has only static methods so they can be used anywhere
 */
class Wikia {

	const REQUIRED_CHARS = '0123456789abcdefG';

	const COMMUNITY_WIKI_ID = 177; // community.wikia.com
	const NEWSLETTER_WIKI_ID = 223496; // wikianewsletter.wikia.com

	const USER = 'FANDOM';
	const BOT_USER = 'FANDOMbot';

	const FAVICON_URL_CACHE_KEY = 'favicon-v1';

	const CUSTOM_INTERFACE_PREFIX = 'custom-';
	const EDITNOTICE_INTERFACE_PREFIX = 'editnotice-';
	const TAG_INTERFACE_PREFIX = 'tag-';
	// one for the extension messages, one for the user messages
	const GADGETS_INTERFACE_PREFIX = 'gadgets-';
	const GADGET_INTERFACE_PREFIX = 'gadget-';

	const DEFAULT_FAVICON_FILE = '/skins/common/images/favicon.ico';
	const DEFAULT_WIKI_LOGO_FILE = '/skins/common/images/wiki.png';

	private static $vars = [];
	private static $cachedLinker;

	public static function setVar($key, $value) {
		Wikia::$vars[$key] = $value;
	}

	public static function getVar($key, $default = null) {
		return isset(Wikia::$vars[$key]) ? Wikia::$vars[$key] : $default;
	}

	public static function isVarSet($key) {
		return isset(Wikia::$vars[$key]);
	}

	public static function unsetVar($key) {
		unset(Wikia::$vars[$key]);
	}

	/**
	 * Set some basic wiki variables.  For use in cron jobs and tasks where some wiki
	 * context is required to construct URLs
	 *
	 * @param int $wikiId ID to use as the current wiki
	 * @param User|int|null $user User object or ID to use as the current user
	 */
	public static function initAsyncRequest( $wikiId, $user = null ) {
		$wg = F::app()->wg;
		$wg->CityID = $wikiId;

		// Do NOT set $wgDbname here.  The wfGetDB method will no longer do
		// what you think it should since it pulls from a LoadBalance cache
		// that likely already has cached DB handles for the previous value
		$dbName = WikiFactory::IDtoDB( $wikiId );
		$wg->Server = trim( WikiFactory::DBtoUrl( $dbName ), '/' );

		if ( !empty( $wg->DevelEnvironment ) ) {
			$wg->Server = WikiFactory::getLocalEnvURL( $wg->Server );
		}

		// Update wgUser if its been set to a reasonable value
		if ( is_object( $user ) ) {
			$wg->User = $user;
		} elseif ( is_numeric( $user ) ) {
			$wg->User = User::newFromId( $user );
		}
	}

	public static function getFaviconFullUrl() {
		return WikiaDataAccess::cache(
			wfMemcKey( self::FAVICON_URL_CACHE_KEY ),
			WikiaResponse::CACHE_STANDARD,
			function () {
				$faviconFilename = ThemeSettings::FaviconImageName;
				$localFavicon = wfFindFile( $faviconFilename );

				if ( $localFavicon ) {
					return $localFavicon->getUrl();
				}

				// SUS-214: fallback to image in repo instead of Community Central
				return F::app()->wg->ResourceBasePath . static::DEFAULT_FAVICON_FILE;
			}
		);
	}

	public static function invalidateFavicon() {
		WikiaDataAccess::cachePurge( wfMemcKey( self::FAVICON_URL_CACHE_KEY ) );
	}

	/**
	 * Return either a path to locally-uploaded Wiki.png file or a shared file taken from the code repo
	 * (if there's no Wiki.png file uploaded on a wiki)
	 *
	 * @see SUS-1165
	 *
	 * @return mixed an array containing "url" and "size" keys
	 */
	public static function getWikiLogoMetadata() {
		$localWikiLogo = wfLocalFile( 'Wiki.png' );

		if ( $localWikiLogo->exists() ) {
			return [
				'url' => $localWikiLogo->getUrl(),
				'size' => sprintf( '%dx%d', $localWikiLogo->getWidth(), $localWikiLogo->getHeight() )
			];
		}
		else {
			return [
				'url' => F::app()->wg->ResourceBasePath . self::DEFAULT_WIKI_LOGO_FILE,
				'size' => '155x155'
			];
		}
	}

    /**
     * successbox
     *
     * @access public
     * @static
     * @author eloy@wikia
     *
     * @param string $what message for user
     *
     * @return string composed HTML/XML code
     */
    static public function successbox($what) {
        return Xml::element( "div", array(
				"class"=> "successbox", "style" => "margin: 0;margin-bottom: 1em;"
			), $what)
			. Xml::element("br", array( "style" => "clear: both;"));
    }

    /**
     * errorbox
     *
     * return div with error message
     *
     * @access public
     * @static
     * @author eloy@wikia
     *
     * @param string $what message for user
     *
     * @return string composed HTML/XML code
     */
    static public function errorbox($what) {
        return Xml::element( "div", array(
				"class"=> "errorbox", "style" => "margin: 0;margin-bottom: 1em;"
			), $what )
			. Xml::element("br", array( "style" => "clear: both;"));
    }

    /**
     * errormsg
     *
     * return span for error message
     *
     * @access public
     * @static
     * @author eloy@wikia
     *
     * @param string $what message for user
     *
     * @return string composed HTML/XML code
     */
    static public function errormsg($what) {
        return Xml::element("span", array( "style"=> "color: #fe0000; font-weight: bold;"), $what);
    }

    /**
     * link
     *
     * return XML/HTML code with link
     *
     * @access public
     * @static
     * @author eloy@wikia
     *
     * @param string $url: message for user
     * @param string $title: link body
     * @param mixed $attribs default null: attribbs for tag
     *
     * @todo safety checking
     *
     * @return string composed HTML/XML code
     */
    static public function linkTag($url, $title, $attribs = null ) {
        return Xml::element("a", array( "href"=> $url), $title);
    }

    /**
     * successmsg
     *
     * return span for success message
     *
     * @access public
     * @static
     * @author eloy@wikia
     *
     * @param string $what message for user
     *
     * @return string composed HTML/XML code
     */
    static public function successmsg($what) {
        return Xml::element("span", array( "style"=> "color: darkgreen; font-weight: bold;"), $what);
    }

    /**
     * fixDomainName
     *
     * It takes domain name as param, then checks if it contains more than one
     * dot, then depending on that information adds .wikia.com domain or not.
     * Additionally it lowercase name
     *
     * @access public
     * @static
     * @author eloy@wikia-inc.com
     *
     * @param string $name Domain Name
     * @param string $language default false - choosen language
     * @param mixed  $type type of domain, default false = wikia.com
     *
     * @return string fixed domain name
     */
	static public function fixDomainName( $name, $language = false, $type = false ) {
		global $wgWikiaBaseDomain;

		if (empty( $name )) {
			return $name;
		}

		$name = strtolower( $name );

		$parts = explode(".", trim($name));
		if( is_array( $parts ) && count( $parts ) <= 2 ) {
			$allowLang = true;

			if ( $type === 'answers' ) {
				$domains = self::getAnswersDomains();
				if ( $language && isset( $domains[$language] ) && !empty( $domains[$language] ) ) {
					$name = sprintf( "%s.%s.%s", $name, $domains[$language], $wgWikiaBaseDomain );
					$allowLang = false;
				} else {
					$name = sprintf( "%s.%s.%s", $name, $domains["default"], $wgWikiaBaseDomain );
				}
			} else {
				$name = sprintf("%s.%s", $name, $wgWikiaBaseDomain);
			}

			if ( $language && $language != "en" && $allowLang ) {
				$name = $language.".".$name;
			}
		}
		return $name;
	}

	/**
	 * simple logger which log message to STDERR if devel environment is set
	 *
	 * @example Wikia::log( __METHOD__, "1", "checking" );
	 * @author Krzysztof Krzyżaniak <eloy@wikia-inc.com>
	 *
	 * @param String $method     -- use __METHOD__
	 * @param String|bool $sub   -- sub-section name (if more than one in same method); default false
	 * @param String $message    -- additional message; default false
	 * @param Boolean $always    -- skip checking of $wgErrorLog and write log (or not); default false
	 * @param Boolean $timestamp -- write timestamp before line; default false
	 *
	 * @deprecated use WikiaLogger instead
	 *
	 */
	static public function log( $method, $sub = false, $message = '', $always = false, $timestamp = false ) {
	  global $wgDevelEnvironment, $wgErrorLog, $wgDBname, $wgCityId, $wgCommandLineMode, $wgCommandLineSilentMode;

		$method = $sub ? $method . "-" . $sub : $method;
		if( $wgDevelEnvironment || $wgErrorLog || $always ) {
			$method = preg_match('/-WIKIA$/', $method) ? str_replace('-WIKIA', '', $method) : $method;
			\Wikia\Logger\WikiaLogger::instance()->debug( $message, [
				'exception' => new Exception(),
				'method' => $method
			] );
		}

		/**
		 * commandline = echo
		 */
		if( $wgCommandLineMode && empty( $wgCommandLineSilentMode ) ) {
			$line = sprintf( "%s:%s/%d: %s\n", $method, $wgDBname, $wgCityId, $message );
			if( $timestamp ) {
				$line = wfTimestamp( TS_DB, time() ) . " " . $line;
			}
			echo $line;
		}
		/**
		 * and use wfDebug as well
		 */
		if (function_exists("wfDebug")) {
			if ( $message instanceof Status ) {
				\Wikia\Logger\WikiaLogger::instance()->debug( "Wikia::log \$message is a Status object", [
					'exception' => new Exception(),
				]);
			}
			wfDebug( $method . ": " . $message . "\n" );
		} else {
			error_log( $method . ":{$wgDBname}/{$wgCityId}:" . "wfDebug is not defined");
		}
	}

	/**
	 * Simple one line backtrace logger
	 *
	 * @example Wikia::logBacktrace(__METHOD__);
	 * @author Maciej Brencz <macbre@wikia-inc.com>
	 *
	 * @param String $method - use __METHOD__
	 *
	 * @deprecated use WikiaLogger instead
	 */
	static public function logBacktrace($method) {
		$backtrace = trim(strip_tags(wfBacktrace()));
		$message = str_replace("\n", '/', $backtrace);

		// add URL when logging from AJAX requests
		if (isset($_SERVER['REQUEST_METHOD']) && ($_SERVER['REQUEST_METHOD'] === 'GET') && ($_SERVER['SCRIPT_URL'] === '/wikia.php')) {
			$message .= " URL: {$_SERVER['REQUEST_URI']}";
		}

		Wikia::log($method, false, $message, true /* $force */);
	}

	/**
	 * Wikia denug backtrace logger
	 *
	 * @example Wikia::debugBacktrace(__METHOD__);
	 * @author Piotr Molski <moli@wikia-inc.com>
	 *
	 * @param String $method - use __METHOD__ as default
	 *
	 * @deprecated use WikiaLogger instead
	 */
	static public function debugBacktrace($method) {
		$backtrace = wfDebugBacktrace();
		$msg = "***** BEGIN *****";
		Wikia::log($method, false, $msg, true /* $force */);
		foreach( $backtrace as $call ) {
			$msg = "";
			if( isset( $call['file'] ) ) {
				$f = explode( DIRECTORY_SEPARATOR, $call['file'] );
				$file = $f[count($f)-1];
			} else {
				$file = '-';
			}
			if( isset( $call['line'] ) ) {
				$line = $call['line'];
			} else {
				$line = '-';
			}
			$msg .= "$file line $line calls ";

			if( !empty( $call['class'] ) ) $msg .= $call['class'] . '::';
			$msg .= $call['function'] . '()';

			Wikia::log($method, false, $msg, true /* $force */);
		}
		$msg = "***** END *****";
		Wikia::log($method, false, $msg, true /* $force */);
	}

	/**
	 * get staff person responsible for language
	 *
	 * @author Krzysztof Krzyżaniak <eloy@wikia-inc.com>
	 * @author Chris Stafford <uberfuzzy@wikia-inc.com>
	 * @access public
	 * @static
	 *
	 * @param String $langCode  -- language code
	 *
	 * @return User -- instance of user object
	 */
	static public function staffForLang( $langCode ) {
		$staffMap = WikiFactory::getVarValueByName( 'wgFounderWelcomeAuthor', Wikia::COMMUNITY_WIKI_ID );

		if ( !empty( $staffMap[$langCode] ) && is_array( $staffMap[$langCode] ) ) {
			$key = array_rand( $staffMap[$langCode] );
			$staffUser = User::newFromName( $staffMap[$langCode][$key] );
		} else {
			// Fallback to robot when there is no explicit welcoming user set (unsupported language)
			$staffUser = User::newFromName( 'Fandom' );
		}

		$staffUser->load();

		return $staffUser;
	}

	/**
	 * A function for making time periods readable
	 *
	 * @author      Aidan Lister <aidan@php.net>
	 * @version     2.0.0
	 * @link        http://aidanlister.com/2004/04/making-time-periods-readable/
	 * @param       int     number of seconds elapsed
	 * @param       string  which time periods to display
	 * @param       bool    whether to show zero time periods
	 */
	static public function timeDuration( $seconds, $use = null, $zeros = false ) {
		$seconds = ceil( $seconds );
		if( $seconds == 0 || $seconds == 1 ) {
			$str = "{$seconds} sec";
		}
		else {

			// Define time periods
			$periods = array (
				'years'     => 31556926,
				'Months'    => 2629743,
				'weeks'     => 604800,
				'days'      => 86400,
				'hr'        => 3600,
				'min'       => 60,
				'sec'       => 1
				);

			// Break into periods
			$seconds = (float) $seconds;
			foreach ($periods as $period => $value) {
				if ($use && strpos($use, $period[0]) === false) {
					continue;
				}
				$count = floor($seconds / $value);
				if ($count == 0 && !$zeros) {
					continue;
				}
				$segments[strtolower($period)] = $count;
				$seconds = $seconds % $value;
			}

			// Build the string
			foreach ($segments as $key => $value) {
				$segment = $value . ' ' . $key;
				$array[] = $segment;
			}

			$str = implode(', ', $array);
		}
		return $str;
	}

	/**
	 * parse additional option links in RC
	 *
	 * @author      Piotr Molski <moli@wikia-inc.com>
	 * @version     1.0.0
	 * @param       RC		 RC - RC object
	 * @param       Array    filters
	 */
	static public function addRecentChangesFilters( $RC, &$filters ) {
		$filters['hidelogs'] = array( 'default' => false, 'msg' => 'rcshowhidelogs' );

		return true;
	}

	/**
	 * make query with additional options
	 *
	 * @author      Piotr Molski <moli@wikia-inc.com>
	 * @version     1.0.0
	 * @param       Array    $conds - where conditions in SQL query
	 * @param       Array    $tables - tables used in SQL query
	 * @param       Array    $join_conds - joins in SQL query
	 * @param       FormOptions    $opts - selected options
	 */
	static public function makeRecentChangesQuery ( &$conds, &$tables, &$join_conds, $opts ) {
		global $wgRequest;

		if ( $wgRequest->getVal( 'hidelogs', 0 ) > 0 ) {
			$conds[] = 'rc_logid = 0';
		}
		return true;
	}

	/**
	 * disable special pages from the list of specialpages
	 *
	 * @author      Piotr Molski <moli@wikia-inc.com>
	 * @version     1.0.0
	 * @param       Array    $list - list of specialpages
	 */
	static public function disableSpecialPage ( &$list ) {
		global $wgDisableSpecialStatistics;

		if ( isset($wgDisableSpecialStatistics) && ($wgDisableSpecialStatistics === true) ) {
			unset($list['Statistics']);
		}
		return true;
	}

	/**
	 * notify user on user right change
	 *
	 * @author      Piotr Molski <moli@wikia-inc.com>
	 * @version     1.0.0
	 * @param       User    $user object
	 * @param       Array   $addgroup - selected groups for user
	 * @param       Array   $removegroup - disabled groups for user
	 */
	static public function notifyUserOnRightsChange ( &$user, $addgroup, $removegroup ) {
		global $wgUsersNotifiedOnAllChanges, $wgUsersNotifiedOfRightsChanges, $wgUser;

		# rt#66961: rights change email sent to !emailconfirmed users
		if( !$user->isEmailConfirmed() ) {
			#if your not confirmed, no email for you, so dont bother adding to On* lists
			return true; #i said no, so stop here
		}

		# FB: 1085 Don't send notif to myself on user rights change
		if ($user->getID() == $wgUser->getID()) {
			return true;
		}

		// Using wgUsersNotifiedOnAllChanges is a hack to get the UserMailer to notify these users.  The use
		// of wgUsersNotifiedOfRightsChanges is to prevent the same user from being notified multiple times if
		// multiple actions occur on the same page.
		if(!isset($wgUsersNotifiedOfRightsChanges)){
			$wgUsersNotifiedOfRightsChanges = array();
		}
		$wgUsersNotifiedOnAllChanges = array_diff($wgUsersNotifiedOnAllChanges, $wgUsersNotifiedOfRightsChanges);

		$userName = $user->getName();
		if ( !in_array( $userName, $wgUsersNotifiedOnAllChanges) ) {
			$wgUsersNotifiedOnAllChanges[] = $userName;

			// We only add them to this if THIS is the reason they're in wgUsersNotifiedOnAllChanges so that we don't accidentally over-remove.
			$wgUsersNotifiedOfRightsChanges[] = $userName;
		}

		return true;
	}

	/**
	 * find array val for lang key - with variant fallback, eg. zh-tw -> zh
	 *
	 * @author      Nef
	 * @param       Array   $map - lang=>value map
	 * @param       String  $lang - lang code, eg. zh or zh-tw
	 * @param       Mixed   $default - if no value found
	 */
	static public function langToSomethingMap($map, $lang, $default = null) {

		if (!empty($map[$lang])) {
			$val = $map[$lang];
		} elseif (!empty($map[preg_replace("/-.*$/", "", $lang)])) {
			$val = $map[preg_replace("/-.*$/", "", $lang)];
		} else {
			$val = $default;
		}

		return $val;
	}

	/**
	 * Wikia Setup.php
	 *
	 * @author      MoLi
	 */
	static public function setupAfterCache() {
		global $wgTTCache;
		$wgTTCache = wfGetSolidCacheStorage();
		return true;
	}

	/**
	 * fixed answers domains
	 */
	public static function getAnswersDomains() {
		global $wgAvailableAnswersLang, $wgContLang;
		wfProfileIn(__METHOD__);
		$msg = "autocreatewiki-subname-answers";
		$default = wfMsgExt( $msg, array( "language" => "en" ) );
		#--
		$domains = array( 'default' => $wgContLang->lcfirst( $default ) );
		if ( !empty($wgAvailableAnswersLang) ) {
			foreach ( $wgAvailableAnswersLang as $lang ) {
				$domain = wfMsgExt( $msg, array( "language" => $lang ) );
				if ( !empty($domain) ) {
					$domain = $wgContLang->lcfirst( $domain );
					if ( !wfEmptyMsg( $msg, $domain ) &&  $domain != $domains['default'] ) {
						$domains[$lang] = $domain;
					}
				}
			}
		}
		wfProfileOut(__METHOD__);
		return $domains;
	}

	/* TODO remove when cat_hidden is fixed */
	public static function categoryCloudGetHiddenCategories() {
		$data = array();

		wfProfileIn( __METHOD__ );

		global $wgMemc;
		$key = wfMemcKey("WidgetCategoryCloud", "hidcats");
		$data = $wgMemc->get($key);
		if (is_null($data)) {
			$dbr = wfGetDB( DB_SLAVE );
			$res = $dbr->select(
					array("page", "page_props"),
					array("page_title"),
					array("page_id = pp_page", "page_namespace" => NS_CATEGORY, "pp_propname" => "hiddencat"),
					__METHOD__
					);
			while ($row = $res->fetchObject()) {
				$data[] = $row->page_title;
			}
			$wgMemc->set($key, $data, 300);
		}

		wfProfileOut(__METHOD__);
		return $data;
	}

	// todo check if it isn't reduntant
	public static function categoryCloudMsgToArray( $key ) {
		$data = array();

		$msg = wfMsg($key);
		if (!wfEmptyMsg($msg, $key)) {
			$data = preg_split("/[*\s,]+/", $msg, null, PREG_SPLIT_NO_EMPTY);
		}
		return $data;
	}

	/**
	 * Check if currently shown page is mainpage
	 */
	public static function isMainPage() {
		wfProfileIn(__METHOD__);

		global $wgTitle, $wgArticle;
		static $result = null;

		if (is_null($result)) {
			$result = $wgTitle->getArticleId() == Title::newMainPage()->getArticleId() && $wgTitle->getArticleId() != 0;

			// handle redirects
			if (!$result) {
				if(!empty($wgArticle->mRedirectedFrom)) {
					$result = wfMsgForContent('mainpage') == $wgArticle->mRedirectedFrom->getPrefixedText();
				}
			}
		}

		wfProfileOut(__METHOD__);
		return $result;
	}

	public static function isContentNamespace() {
		wfProfileIn(__METHOD__);

		global $wgTitle, $wgContentNamespaces;

		static $result = null;

		if (is_null($result)) {
			if (in_array($wgTitle->getNamespace(), $wgContentNamespaces)) {
				$result = true;
			} else {
				$result = false;
			}
		}

		wfProfileOut(__METHOD__);
		return $result;
	}

	/**
	 * Returns true. Replace UNSUBSCRIBEURL with message and link to Special::Unsubscribe page
	 */
	static public function ComposeMail( /*MailAddress*/ $to, /*String*/&$body, /*String*/&$subject ) {
		global $wgCityId;

		if ( !$to instanceof MailAddress ) {
			return true;
		}

		# to test MW 1.16
		$cityId = ( $wgCityId == 1927 ) ? $wgCityId : 177;
		$name = $to->name;

		$oTitle = GlobalTitle::newFromText('Unsubscribe', NS_SPECIAL, $cityId);
		if ( !is_object( $oTitle ) ) {
			return true;
		}

		$email = $to->address;
		$ts = time();
		# unsubscribe params
		$hash_url = Wikia::buildUserSecretKey( $name, 'sha256' );

		$url = ( $hash_url ) ? $oTitle->getFullURL( array( 'key' => $hash_url ) ) : '';
		$body = str_replace( '$UNSUBSCRIBEURL', $url, $body );

		return true;
	}

	/**
	 * @static
	 * @access public
	 * @author Krzysztof Krzyżaniak (eloy)
	 *
	 * add entries to software info
	 */
	static public function softwareInfo( &$software ) {
		global $wgCityId, $wgDBcluster, $wgWikiaDatacenter, $wgLocalFileRepo, $smwgDefaultStore, $wgEnableSemanticMediaWikiExt;

		$info = [];

		if( !empty( $wgCityId ) ) {
			$info[] = "city_id: {$wgCityId}";
		}
		if( empty( $wgDBcluster ) ) {
			$info[] = "cluster: c1";
		}
		else {
			$info[] = "cluster: $wgDBcluster";
		}
		if( !empty( $wgWikiaDatacenter ) ) {
			$info[] = "dc: $wgWikiaDatacenter";
		}
		if( !empty( $wgEnableSemanticMediaWikiExt ) ) {
			$info[] = "smw_store: $smwgDefaultStore";
		}

		$software[ "Internals" ] = join( ', ', $info );

		/**
		 * obligatory hook return value
		 */
		return true;
	}

	/**
	 * get properties for page
	 * FIXME: maybe it should be cached?
	 * @param $page_id int
	 * @param $oneProp string if you just want one property, this will return the value only, not an array
	 * @return mixed
	 */
	static public function getProps( $page_id, $oneProp = null ) {
		wfProfileIn( __METHOD__ );
		$return = array();
		if (empty($page_id)) {
			wfProfileOut( __METHOD__ );
			return null;
		}

		$where = array( "pp_page" => $page_id );
		if ($oneProp != null) {
			$where['pp_propname'] = $oneProp;
			$return[$oneProp] = '';   // empty default placeholder in case value is not set
		}
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select(
			array( "page_props" ),
			array( "*" ),
			$where,
			__METHOD__
		);
		while( $row = $dbr->fetchObject( $res ) ) {
			$return[ $row->pp_propname ] = $row->pp_value;
			wfDebug( __METHOD__ . " id: {$page_id}, key: {$row->pp_propname}, value: {$row->pp_value}\n" );
		}
		$dbr->freeResult( $res );
		wfProfileOut( __METHOD__ );

		if ($oneProp != null) return $return[$oneProp];
		return $return;
	}


	/**
	 * save article extra properties to page_props table
	 *
	 * Warning: Fails silently if the write can not be made (for instance, if in read-only mode or if there is a db error).
	 *
	 * @static
	 * @access public
	 * @param array $props array of properties to save (prop name => prop value)
	 */

	static public function setProps( $page_id, Array $props ) {
		wfProfileIn( __METHOD__ );

		if( !wfReadOnly() ){ // Change to wgReadOnlyDbMode if we implement that
			$dbw = wfGetDB( DB_MASTER );
			foreach( $props as $sPropName => $sPropValue) {
				$dbw->replace(
					"page_props",
					array(
						"pp_page",
						"pp_propname"
					),
					array(
						"pp_page" => $page_id,
						"pp_propname" => $sPropName,
						"pp_value" => $sPropValue
					),
					__METHOD__
				);
				Wikia::log( __METHOD__, "save", "id: {$page_id}, key: {$sPropName}, value: {$sPropValue}" );
			}
			$dbw->commit(); #--- for ajax
		}

		wfProfileOut( __METHOD__ );
	}

	/**
	 * build user authentication key
	 * @static
	 * @access public
	 * @param array $params
	 */
	static public function buildUserSecretKey( $username, $hash_algorithm = 'sha256' ) {
		global $wgWikiaAuthTokenKeys;
		wfProfileIn( __METHOD__ );

		$oUser = User::newFromName( $username );
		if ( !is_object($oUser) ) {
			wfProfileOut( __METHOD__ );
			return false;
		}

		if ( 0 == $oUser->getId() ) {
			wfProfileOut( __METHOD__ );
			return false;
		}

		$email = $oUser->getEmail();
		if ( empty($email) ) {
			wfProfileOut( __METHOD__ );
			return false;
		}

		$params = array(
			'user' 			=> (string) $username,
			'token'			=> (string) wfGenerateUnsubToken( $email ),
		);

		// Generate content verification signature
		$data = serialize( $params );

		// signature to compare
		$signature = hash_hmac( $hash_algorithm, $data, $wgWikiaAuthTokenKeys[ 'private' ] );

		// encode public information
		$public_information = array( $username, $signature, $wgWikiaAuthTokenKeys[ 'public' ] );
		$result = strtr( base64_encode( implode( "|", $public_information ) ), '+/=', '-_,' );

		wfProfileOut( __METHOD__ );

		return $result;
	}

	static public function isUnsubscribed( $to, $body, $subject ) {
		# Hook moved from SpecialUnsubscribe extension
		#if this opt is set, fake their conf status to OFF, and stop here.
		$user = User::newFromName( $to->name );

		if( $user instanceof User && (bool)$user->getGlobalPreference('unsubscribed') ) {
			return false;
		}

		return true;
	}

	/**
	 * Do not send watchlist emails for edits made by Wikia bot accounts
	 *
	 * @param User $editor
	 * @param Title $title
	 * @return bool return false if you want to block an email
	 */
	static public function allowNotifyOnPageChange ( User $editor, /* Title */ $title ) {
		global $wgWikiaBotLikeUsers;

		if ( in_array( $editor->getName(), $wgWikiaBotLikeUsers)  ) {
			return false;
		}
		else {
			return true;
		}
	}

	/**
	 * Create / get cached instance of Linker class
	 *
	 * @return Linker
	 */
	private static function getLinker() {
		if (!is_object(self::$cachedLinker)) {
			self::$cachedLinker = new Linker();
		}

		return self::$cachedLinker;
	}

	/**
	 * Create a link to any page with Oasis style buttons
	 *
	 * Sample Usages:
	 * View::normalPageLink('Somewhere', 'button-createpage', 'wikia-button');
	 * View::normalPageLink('Somewhere', 'oasis-button-random-page', 'wikia-button secondary', 'icon_button_random.png') ?>
	 *
 	 * @param title Title - the Title of the page to link to
	 * @param message String - the name of a message to use as the link text
	 * @param class String - [optional] the name of a css class for button styling or array of HTML attributes for button
	 * @param img String - [optional] the name of an image to pre-pend to the text (for secondary buttons)
	 * @param alt String - [optional] the name of a message to be used as link tooltip
	 * @param imgclass String - [optional] the name of a css class for the image (for secondary buttons)
	 * @param query array [optional] query parameters
	 */
	static function normalPageLink($title, $message = '', $class = null, $img = null, $alt = null, $imgclass = null, $query = null, $rel = null) {
		global $wgStylePath, $wgBlankImgUrl;

		$classes = array();
		if (is_string($class)) {
			$classes['class'] = $class;
		}
		else if (is_array($class)) {
			$classes = $class;
		}

		if ($alt != '') {
			$classes['title'] = wfMsg($alt);
		}

		if ($alt != '') {
			$classes['rel'] = $rel;
		}

		if ($message != '') {
			$message = wfMsg($message);
		}
		// Image precedes message text
		if ($img != null) {
			$src = (($img == 'blank.gif') ? $wgBlankImgUrl : "{$wgStylePath}/common/{$img}");
			$attr = array('src' => $src);
			if ($img == 'blank.gif') {
				$attr['height'] = '0';
				$attr['width'] = '0';
			}
			if ($imgclass != '') {
				$attr['class'] = $imgclass;
			}
			$message = Xml::element('img', $attr) . ' ' . $message;
		}

		$linker = self::getLinker();
		return $linker->link(
				$title,
				$message,  // link text
				$classes,
				$query,  // query
				array ("known", "noclasses")
			);
	}

	/**
	 * create a link to a SpecialPage.
	 *
	 * Depending on params, this will create a text link, a css button, or a "secondary" button with an embedded image
	 * avoiding this hardcoded stuff
	 * <a href=" Title::makeTitle(NS_SPECIAL, 'CreatePage')->getLocalURL()" class="wikia-button"> <?= wfMsg('button-createpage') </a>
	 *
	 * You can also use the link function directly if you want to, but it's a bit messy and doesn't handle the image case
	 * View::link(SpecialPage::getTitleFor('Following'), wfMsg('wikiafollowedpages-special-seeall'), array("class" => "more"))
	 *
	 * Params:
	 * View::specialPageLink('PageName', 'messagename', 'css-class', 'image-name');
	 *
	 * Sample Usages:
	 * View::specialPageLink('CreatePage', 'button-createpage', 'wikia-button');
	 * View::specialPageLink('Random', 'oasis-button-random-page', 'wikia-button secondary', 'icon_button_random.png') ?>
	 *
	 * @param pageName String - the name of the special page to link to
	 * @param msg String - the name of a message to use as the link text
	 * @param class String - [optional] the name of a css class for button styling or array of HTML attributes for button
	 * @param img String - [optional] the name of an image to pre-pend to the text (for secondary buttons)
	 * @param alt String - [optional] the name of a message to be used as link tooltip
	 * @param imgclass String - [optional] the name of a css class for the image (for secondary buttons)
	 * @param rel String - [optional] the link's rel attribute
	 */
	static function specialPageLink($pageName, $message = '', $class = null, $img = null, $alt = null, $imgclass = null, $query = null, $rel = null)
	{
		$title = SpecialPage::getTitleFor( $pageName );
		return self::normalPageLink($title, $message, $class, $img, $alt, $imgclass, $query, $rel);
	}

	/**
	 * Call Linker::link method to generate HTML links from Title object
	 */
	static function link($target, $text = null, $customAttribs = array(), $query = array(), $options = array()) {
		$linker = self::getLinker();
		return $linker->link($target, $text, $customAttribs, $query, $options);
	}

	/**
	 * recentChangesSave -- hook
	 * Send information to the backend script, when new record was added to the recentchanges table
	 *
	 * @static
	 * @access public
	 *
	 * @param RecentChange $oRC
	 *
	 * @author Piotr Molski (MoLi)
	 * @return bool true
	 */
	static public function recentChangesSave( $oRC ) {
		global $wgCityId, $wgDBname, $wgEnableScribeReport, $wgRequest;

		if ( empty( $wgEnableScribeReport ) ) {
			return true;
		}

		if ( !is_object( $oRC ) ) {
			return true;
		}

		$rc_ip = $oRC->getAttribute( 'rc_ip' );
		if ( is_null( $rc_ip ) ) {
			return true;
		}

		if ( !User::isIP( $rc_ip ) ) {
			return true;
		}

		$params = array(
			'dbname'	=> $wgDBname,
			'wiki_id'	=> $wgCityId,
			'ip'		=> $rc_ip
		);

		try {
			$message = array(
				'method' => 'ipActivity',
				'params' => $params
			);
			$data = json_encode( $message );
			WScribeClient::singleton('trigger')->send($data);
		}
		catch( TException $e ) {
			Wikia::log( __METHOD__, 'scribeClient exception', $e->getMessage() );
		}

		return true;
	}

	/**
	 * Purge Common and Wikia and User css/js when those files are edited
	 * Uses $wgOut->makeResourceLoaderLink which was protected, but has lots of logic we don't want to duplicate
	 * This was rewritten from scratch as part of BAC-895
	 *
	 * @param Title $title page to be purged
	 * @param Array $urls list of URLs to be purged
	 * @return mixed true - it's a hook
	 */
	static public function onTitleGetSquidURLs(Title $title, Array &$urls) {
		global $wgUseSiteJs, $wgAllowUserJs, $wgUseSiteCss, $wgAllowUserCss;
		global $wgOut;
		wfProfileIn(__METHOD__);

		$link = null;
		if( $wgUseSiteJs && $title->getNamespace() == NS_MEDIAWIKI ) {
			if( $title->getText() == 'Common.js' || $title->getText() == 'Wikia.js') {
				$wgOut->setAllowedModules( ResourceLoaderModule::TYPE_SCRIPTS, ResourceLoaderModule::ORIGIN_ALL );
				$link = $wgOut->makeResourceLoaderLink( 'site', ResourceLoaderModule::TYPE_SCRIPTS );
			}
		}
		if ($wgUseSiteCss && $title->getNamespace() == NS_MEDIAWIKI ) {
			if( $title->getText() == 'Common.css' || $title->getText() == 'Wikia.css' ) {
				$wgOut->setAllowedModules( ResourceLoaderModule::TYPE_STYLES, ResourceLoaderModule::ORIGIN_ALL );
				$link = $wgOut->makeResourceLoaderLink( 'site', ResourceLoaderModule::TYPE_STYLES );
			}
		}
		if( $wgAllowUserJs && $title->isJsSubpage() ) {
			$wgOut->setAllowedModules( ResourceLoaderModule::TYPE_SCRIPTS, ResourceLoaderModule::ORIGIN_ALL );
			$link = $wgOut->makeResourceLoaderLink( 'user', ResourceLoaderModule::TYPE_SCRIPTS );
		}
		if( $wgAllowUserCss && $title->isCssSubpage() ) {
			$wgOut->setAllowedModules( ResourceLoaderModule::TYPE_STYLES, ResourceLoaderModule::ORIGIN_ALL );
			$link = $wgOut->makeResourceLoaderLink( 'user', ResourceLoaderModule::TYPE_STYLES );
		}
		if ($link != null) {
			// extract the url from the link src
			preg_match("/.*\"(.*)\"/", $link, $matches);
			if ( isset($matches[1]) ) {
				$urls[]= $matches[1];
			}
		}

		// purge Special:RecentChanges too (SUS-2595)
		$rcTitle = SpecialPage::getTitleFor('RecentChanges');

		$urls[] = $rcTitle->getInternalURL();
		$urls[] = $rcTitle->getInternalURL('feed=rss');
		$urls[] = $rcTitle->getInternalURL('feed=atom');

		wfProfileOut(__METHOD__);
		return true;
	}

	static public function getEnvironmentRobotPolicy(WebRequest $request) {
		global $wgDevelEnvironment, $wgStagingEnvironment, $wgDefaultRobotPolicy;

		$policy = '';

		if( !empty( $wgDevelEnvironment ) || !empty( $wgStagingEnvironment ) ) {
			$policy = $wgDefaultRobotPolicy;
		}

		$stagingHeader = $request->getHeader('X-Staging');

		if( !empty($stagingHeader) ) {
			// we've got special cases like externaltest.* and showcase.* aliases:
			// https://github.com/Wikia/wikia-vcl/blob/master/wikia.com/control-stage.vcl#L15
			// those cases for backend look like production,
			// therefore we don't want to base only on environment variables
			// but on HTML headers as well, see:
			// https://github.com/Wikia/app/blob/dev/redirect-robots.php#L285
			$policy = 'noindex,nofollow';
		}
		return $policy;
	}

	/**
	 * Add variables to SkinTemplate
	 */
	static public function onSkinTemplateOutputPageBeforeExec(SkinTemplate $skinTemplate, QuickTemplate $tpl) {
		wfProfileIn(__METHOD__);

		$out = $skinTemplate->getOutput();
		$title = $skinTemplate->getTitle();

		// Pass parameters to skin, see: Login friction project (Marooned)
		$tpl->set( 'thisurl', $title->getPrefixedURL() );
		$tpl->set( 'thisquery', $skinTemplate->thisquery );

		$robotPolicy = Wikia::getEnvironmentRobotPolicy( $skinTemplate->getRequest() );
		if ( !empty( $robotPolicy ) ) {
			$out->setRobotPolicy( $robotPolicy );
		}

		wfProfileOut(__METHOD__);
		return true;
	}

	/**
	 * Handle URL parameters and set proper global variables early enough :)
	 *
	 * - Detect debug mode for assets (allinone=0) - sets $wgAllInOne and $wgResourceLoaderDebug
	 *
	 * @author macbre
	 */
	static public function onAfterInitialize($title, $article, $output, $user, WebRequest $request, $wiki) {
		global $wgResourceLoaderDebug, $wgAllInOne, $wgUseSiteJs, $wgUseSiteCss,
				$wgAllowUserJs, $wgAllowUserCss, $wgBuckySampling;

		$wgAllInOne = $request->getBool('allinone', $wgAllInOne) !== false;
		if ($wgAllInOne === false) {
			$wgResourceLoaderDebug = true;
			wfDebug("Wikia: using resource loader debug mode\n");
		}

		$wgUseSiteJs = $wgUseSiteJs && $request->getBool( 'usesitejs', $wgUseSiteJs ) !== false;
		$wgUseSiteCss = $wgUseSiteCss && $request->getBool( 'usesitecss', $wgUseSiteCss ) !== false;

		// Don't enable user JS unless explicitly enabled by the user (CE-2509)
		if ( !$user->getGlobalPreference( 'enableuserjs', false ) ) {
			$wgAllowUserJs = false;
		} else {
			$wgAllowUserJs = $wgAllowUserJs && $request->getBool( 'useuserjs',
				$request->getBool( 'allowuserjs', $wgAllowUserJs ) ) !== false;
		}

		$wgAllowUserCss = $wgAllowUserCss && $request->getBool( 'useusercss',
			$request->getBool( 'allowusercss', $wgAllowUserCss ) ) !== false;
		$wgBuckySampling = $request->getInt( 'buckysampling', $wgBuckySampling );

		return true;
	}

	/**
	 * Detect internal HTTP requests: log them and set a response header to ease debugging
	 *
	 * @see PLATFORM-1473
	 *
	 * @param WebRequest $request
	 * @return bool true, it's a hook
	 */
	static public function onWebRequestInitialized( WebRequest $request ) {
		if ( $request->isWikiaInternalRequest() ) {
			$requestSource = $request->getHeader( WebRequest::WIKIA_INTERNAL_REQUEST_HEADER );

			Wikia\Logger\WikiaLogger::instance()->info( 'Wikia internal request', [
				'source' => $requestSource
			] );
			$request->response()->header( 'X-Wikia-Is-Internal-Request: ' . $requestSource );
		}

		return true;
	}

	/**
	 * informJobQueue
	 * Send information to the backend script what job was added
	 *
	 * @static
	 * @access public
	 *
	 * @param Integer count of job params
	 *
	 * @author Piotr Molski (MoLi)
	 * @return true
	 */
	static public function informJobQueue( /*Integer*/ $job_count = 1 ) {
		global $wgCityId, $wgDBname, $wgEnableScribeReport;

		if ( empty( $wgEnableScribeReport ) ) {
			return true;
		}

		$params = array(
			'dbname'	=> $wgDBname,
			'wiki_id'	=> $wgCityId,
			'jobs'		=> $job_count
		);

		try {
			$message = array(
				'method' => 'jobqueue',
				'params' => $params
			);
			$data = json_encode( $message );
			WScribeClient::singleton('trigger')->send($data);
		}
		catch( TException $e ) {
			Wikia::log( __METHOD__, 'scribeClient exception', $e->getMessage() );
		}

		return true;
	}

	/**
	 * get_const_values
	 * Returns some stats values from const_values table
	 *
	 * @static
	 * @access public
	 * @author Piotr Molski
	 *
	 * @param $name String
	 * @return int
	 *
	 */
	static public function get_const_values( $name = '' ) {
		global $wgMemc;
		$key = wfSharedMemcKey('const_values', $name);
		$value = $wgMemc->get($key);

		if ( !is_numeric($value) ) {
			$dbr = wfGetDB( DB_SLAVE, array(), 'specials' );

			$oRes = $dbr->select('const_values', array('val'), array( 'name' =>  $name ), __METHOD__ );

			$value = 0;
			if ( $oRow = $dbr->fetchRow( $oRes ) ) {
				$value = $oRow['val'];
				$wgMemc->set( $key, $value, 60 * 60 * 5 );
			}
		}

		return $value;
	}


	/**
	 * get_content_page
	 * Returns number of pages in content namespaces
	 *
	 * @static
	 * @access public
	 * @author Piotr Molski
	 *
	 * @return int
	 *
	 */
	static public function get_content_pages( ) {
		return self::get_const_values( 'content_ns' );
	}

	/**
	 * Add some extra request parameters to control memcache behavior @author: owen
	 * mcache=none disables memcache for the duration of the request (not really that useful)
	 * mcache=writeonly disables memcache reads for the duration of the request
	 * mcache=readonly disables memcache writes for the duration of the request
	 * TODO: allow disabling specific keys?
	 */
	static public function onBeforeInitializeMemcachePurge( $title, $unused, $output, $user, WebRequest $request, $wiki ) {
		self::setUpMemcachePurge( $request, $user );
		return true;
	}

	/**
	 * Control memcache behavior
	 *
	 * @param WebRequest $request
	 */
	static public function setUpMemcachePurge( WebRequest $request, $user ) {
		global $wgAllowMemcacheDisable, $wgAllowMemcacheReads, $wgAllowMemcacheWrites, $wgDevelEnvironment;

		if ( !$user->isAllowed( 'mcachepurge' ) && empty( $wgDevelEnvironment ) ) {
			return true;
		}

		$mcachePurge = $request->getVal( 'mcache', null );

		if ( $wgAllowMemcacheDisable && $mcachePurge !== null ) {
			switch ( $mcachePurge ) {
				case 'writeonly':
					$wgAllowMemcacheReads = false;
					$wgAllowMemcacheWrites = true;
					break;
				case 'readonly':
					$wgAllowMemcacheReads = true;
					$wgAllowMemcacheWrites = false;
					break;
				case 'none':
					$wgAllowMemcacheReads = $wgAllowMemcacheWrites = false;
					break;
				default: // anything else defaults to mcache on
					$wgAllowMemcacheReads = $wgAllowMemcacheWrites = true;
					break;
			}
		}

		return true;
	}

	/**
	 * Hook: reset recipient's name
	 * @param array of MailAddress $to
	 * @return bool true
	 */
	static public function onUserMailerSend( &$to ) {
		foreach ( $to as $u ) {
			if ( $u instanceof MailAddress && $u->name != '' ) {
				$u->name = '';
			}
		}

		return true;
	}

	/**
	* FIX FOR bugId:42480 File rename is not completed when replacement is required
	* "When moving a file to a file name that already belongs to an existing file,
	* it gives you the option to delete the existing file to make way for the move.
	* After completion, it deletes the existing file as intended, but the file
	* that you originally wanted to move is not moved, and keeps it's previous file name."
	*
	* That is because $nt (NewTitle) -> getArticleId() returns value that is cached
	* (after delete it should be 0)
	 *
	 * @param WikiPage $page
	*/
	public static function onArticleDeleteComplete( $page, $user, $reason, $id ) {

		$title = $page->getTitle();
		if ( $title instanceof Title ) {
			$title->getArticleID( Title::GAID_FOR_UPDATE );
		}
		return true;
	}

	/**
	 * Tries to create an existing title object for current request. Used for setting the title
	 * global during ajax requests. It uses title URL param to keep backward compatibility.
	 *
	 * @static
	 * @param WebRequest $request
	 * @return Title
	 */
	public static function createTitleFromRequest( $request ) {
		if ( $request->getVal('title','') === '' ) {
			$title = Title::newMainPage();
		} else {
			$title = Title::newFromText($request->getVal('title', 'AJAX'), $request->getInt('namespace', NS_MAIN));
			if (!$title instanceof Title) {
				$title = Title::makeTitle( NS_MAIN, 'AJAX' );
			}
		}
		return $title;
	}

	public static function renameArrayKeys( $array, $mapping ) {
		$newArray = array();
		foreach ($array as $k => $v) {
			$k = array_key_exists($k,$mapping) ? $mapping[$k] : $k;
			$newArray[$k] = $v;
		}

		return $newArray;
	}

	/**
	 * Add a link to Special:LookupUser from Special:Contributions/USERNAME
	 * if the user has 'lookupuser' permission on wikis other than Central
	 * since the extension is only enabled there (BugID: 47807)
	 *
	 * @author grunny
	 * @param integer $id User identifier
	 * @param Title $title User page title
	 * @param Array $tools An array of tool links
	 * @return bool true
	 */
	public static function onContributionsToolLinks( $id, $title, &$links ) {
		global $wgUser, $wgCityId;
		if ( $wgCityId !== '177' && $id !== 0 && $wgUser->isAllowed( 'lookupuser' ) ) {
			$links[] = Linker::linkKnown(
				GlobalTitle::newFromText( 'LookupUser', NS_SPECIAL, 177 ),
				wfMsgHtml( 'lookupuser' ),
				array(),
				array( 'target' => $title->getText() )
			);
		}
		return true;
	}

	/**
	 * Add shared AMD modules
	 *
	 * @param $out OutputPage
	 * @return bool
	 */
	public static function onAjaxAddScript(OutputPage $out) {
		// because of dependency resolving this module needs to be loaded via JavaScript
		$out->addModules( 'amd.shared' );
		return true;
	}

	/**
	 * Verifies image being uploaded whether it's not corrupted
	 *
	 * @author macbre
	 *
	 * @param UploadBase $upload
	 * @param string $mime
	 * @param array $error
	 * @return bool
	 */
	public static function onUploadVerifyFile(UploadBase $upload, $mime, &$error) {
		// only check supported images
		$mimeTypes = array(
			'image/gif',
			'image/jpeg',
			'image/png',
		);

		if (!in_array($mime, $mimeTypes)) {
			return true;
		}

		// validate an image using ImageMagick
		$imageFile = $upload->getTempPath();

		$output = wfShellExec("identify -regard-warnings {$imageFile} 2>&1", $retVal);
		wfDebug("Exit code #{$retVal}\n{$output}\n");

		/**
		 * Let's ignore warnings reported by "identify" binary and focus on errors, examples:
		 *
		 * identify: Ignoring attempt to set negative chromaticity value `/tmp/Gree.png' @ warning/png.c/MagickPNGWarningHandler/1671.
		 * identify.im6: no decode delegate for this image format `/tmp/UploadTestExwn06' @ error/constitute.c/ReadImage/544.
		 *
		 * @see SUS-1625
		 */
		$isValid = strpos( $output, ' @ error/' ) === false; /* no errors reported */

		if (!$isValid) {
			Wikia\Logger\WikiaLogger::instance()->warning( __METHOD__ . ' failed', [
				'output' => rtrim($output),
			] );

			// pass an error to UploadBase class
			$error = array('verification-error');
		}

		return $isValid;
	}

	/*
	 * @param $user_name String
	 * @param $s ResultWrapper
	 * @param $bUserObject boolean Return instance of User if true; StdClass (row) otherwise.
	 */
	public static function onUserNameLoadFromId( $user_name, &$s, $bUserObject = false ) {
		global $wgExternalAuthType;
		if ( $wgExternalAuthType ) {
			$mExtUser = ExternalUser::newFromName( $user_name );
			if ( is_object( $mExtUser ) && ( 0 != $mExtUser->getId() ) ) {
				$mExtUser->linkToLocal( $mExtUser->getId() );
				$s = $mExtUser->getLocalUser( $bUserObject );
			}
		}

		return true;
	}

	/**
	 * @param $user User
	 * @param $s ResultWrapper
	 */
	public static function onUserLoadFromDatabase( $user, &$s ) {
		/* wikia change */
		global $wgExternalAuthType;
		if ( $wgExternalAuthType ) {
			$mExtUser = ExternalUser::newFromId( $user->mId );
			if ( is_object( $mExtUser ) && ( 0 != $mExtUser->getId() ) ) {
				$mExtUser->linkToLocal( $mExtUser->getId() );
				$s = $mExtUser->getLocalUser( false );
			}
		}

		return true;
	}

	/**
	 * @desc Adds assets to OutputPage depending on asset type
	 *
	 * @param mixed $assetName the name of a configured package or path to an asset file or an array of them
	 * @param bool $local [OPTIONAL] whether to fetch per-wiki local URLs,
	 * (false by default, i.e. the method returns a shared host URL's for our network);
	 * please note that this parameter has no effect on SASS assets, those will always produce shared host URL's.
	 *
	 * @example Wikia::addAssetsToOutput('path/to/asset/file/assetName.scss')
	 * @example Wikia::addAssetsToOutput('assetName')
	 * (assetName should be set in includes/wikia/AssetsManager/config.php)
	 * @example Wikia::addAssetsToOutput([
	 * 'path/to/asset/file/assetName.scss',
	 * 'path/to/other/asset/file/assetJS.js'
	 * ])
	 */
	public static function addAssetsToOutput( $assetName, $local = false ) {
		$app = F::app();

		$type = false;

		$sources = AssetsManager::getInstance()->getURL( $assetName, $type, $local );

		foreach($sources as $src){
			switch ( $type ) {
				case AssetsManager::TYPE_CSS:
				case AssetsManager::TYPE_SCSS:
					$app->wg->Out->addStyle( $src );
					break;
				case AssetsManager::TYPE_JS:
					$app->wg->Out->addScript( "<script src=\"{$src}\"></script>" );
					break;
			}
		}
	}

	/**
	 * @param $user User
	 */
	public static function invalidateUser( $user, $disabled = false, $keepEmail = true, $ajax = false ) {
		global $wgExternalAuthType;

		if ( $disabled ) {
			$userEmail = $user->getEmail();
			// Optionally keep email in user property
			if ( $keepEmail && !empty( $userEmail ) ) {
				$user->setGlobalAttribute( 'disabled-user-email', $userEmail );
			} elseif ( !$keepEmail ) {
				// Make sure user property is removed
				$user->setGlobalAttribute( 'disabled-user-email', null );
			}
			$user->setEmail( '' );
			$user->setPassword( null );
			$user->setGlobalFlag( 'disabled', 1);
			$user->setGlobalAttribute( 'disabled_date', wfTimestamp( TS_DB ) );
			$user->mToken = null;
			$user->invalidateEmail();
			if ( $ajax ) {
				global $wgRequest;
				$wgRequest->setVal('action', 'ajax');
			}
			$user->saveSettings();
		}
		$id = $user->getId();
		// delete the record from all the secondary clusters
		if ( $wgExternalAuthType == 'ExternalUser_Wikia' ) {
			ExternalUser_Wikia::removeFromSecondaryClusters( $id );
		}
		$user->invalidateCache();

		return true;
	}

	/**
	 * Register Swift file backend
	 *
	 * @author macbre
	 * @param array $repo $wgLocalFileRepo
	 * @return bool true - it's a hook
	 */
	static function onAfterSetupLocalFileRepo(Array &$repo) {
		// $wgUploadPath: http://images.wikia.com/poznan/pl/images
		// $wgFSSwiftContainer: poznan/pl
		global $wgFSSwiftContainer, $wgFSSwiftServer, $wgUploadPath;

		$path = trim( parse_url( $wgUploadPath, PHP_URL_PATH ), '/' );
		$wgFSSwiftContainer = substr( $path, 0, -7 );

		$repo['backend'] = 'swift-backend';
		$repo['zones'] = array (
			'public' => array( 'container' => $wgFSSwiftContainer, 'url' => 'http://' . $wgFSSwiftServer, 'directory' => 'images' ),
			'temp'   => array( 'container' => $wgFSSwiftContainer, 'url' => 'http://' . $wgFSSwiftServer, 'directory' => 'images/temp' ),
			'thumb'  => array( 'container' => $wgFSSwiftContainer, 'url' => 'http://' . $wgFSSwiftServer, 'directory' => 'images/thumb' ),
			'deleted'=> array( 'container' => $wgFSSwiftContainer, 'url' => 'http://' . $wgFSSwiftServer, 'directory' => 'images/deleted' ),
			'archive'=> array( 'container' => $wgFSSwiftContainer, 'url' => 'http://' . $wgFSSwiftServer, 'directory' => 'images/archive' )
		);

		return true;
	}

	/**
	 * Modify timeline extension to use Swift storage (BAC-893)
	 *
	 * @param FileBackend $backend
	 * @param string $fname mwstore abstract path
	 * @param string $hash file hash
	 * @return bool true - it's a hook
	 */
	static function onBeforeRenderTimeline(&$backend, &$fname, $hash) {
		global $wgFSSwiftContainer;

		$backend = FileBackendGroup::singleton()->get( 'swift-backend' );
		$fname = 'mwstore://' . $backend->getName() . "/$wgFSSwiftContainer/images/timeline/$hash";

		return true;
	}

	/**
	 * Filter-out "Tenp_file_" images from list of URLs to purge
	 *
	 * @param File $file image to purge
	 * @param array $urls URLs to purge generated by MW core
	 */
	static function onLocalFileExecuteUrls( File $file, Array &$urls ) {
		if ( strpos( $file->getName(), 'Temp_file_' ) === 0  && $file->getExtension() === '' ) {
			wfDebug(__METHOD__ . ": removed {$file->getName()} from the purger queue\n");
			$urls = [];
		}

		return true;
	}

	/**
	 * Send ETag header with article's last modification timestamp and cache buster
	 *
	 * See BAC-1227 for details
	 *
	 * @param WikiPage $article
	 * @param ParserOptions $popts
	 * @param $eTag
	 * @author macbre
	 */
	static function onParserCacheGetETag(Article $article, ParserOptions $popts, &$eTag) {
		global $wgStyleVersion, $wgUser, $wgCacheEpoch;
		$touched = $article->getTouched();

		// don't emit the default touched value set in WikiPage class (see CONN-430)
		if ($touched === '19700101000000') {
			$eTag = '';
			return true;
		}

		// use the same rules as in OutputPage::checkLastModified
		$timestamps = [
			'page' => $touched,
			'user' => $wgUser->getTouched(),
			'epoch' => $wgCacheEpoch,
		];

		$eTag = sprintf( '%s-%s', max($timestamps), $wgStyleVersion );
		return true;
	}

	/**
	 * Add response headers with debug data and statistics
	 *
	 * @param WebResponse $response
	 * @author macbre
	 */
	private static function addExtraHeaders(WebResponse $response) {
		global $wgRequestTime;
		$elapsed = microtime( true ) - $wgRequestTime;

		$response->header( sprintf( 'X-Served-By: %s', wfHostname() ) );
		$response->header( sprintf( 'X-Backend-Response-Time: %01.3f', $elapsed ) );

		$response->header( sprintf( 'X-Trace-Id: %s', WikiaTracer::instance()->getTraceId() ) );
		$response->header( sprintf( 'X-Span-Id: %s', WikiaTracer::instance()->getSpanId() ) );

		$response->header( sprintf( 'X-Request-Path: %s', WikiaTracer::instance()->getRequestPath() ) );

		$response->header( 'X-Cache: ORIGIN' );
		$response->header( 'X-Cache-Hits: ORIGIN' );
	}

	/**
	 * Add X-Served-By and X-Backend-Response-Time response headers to MediaWiki pages
	 *
	 * See BAC-550 for details
	 *
	 * @param OutputPage $out
	 * @param Skin $sk
	 * @return bool
	 * @author macbre
	 */
	static function onBeforeSendCacheControl(OutputPage $out) {
		self::addExtraHeaders( $out->getRequest()->response() );
		return true;
	}

	/**
	 * Add X-Served-By and X-Backend-Response-Time response headers to ResourceLoader
	 *
	 * See BAC-1319 for details
	 *
	 * @param ResourceLoader $rl
	 * @param ResourceLoaderContext $context
	 * @return bool
	 * @author macbre
	 */
	static function onResourceLoaderAfterRespond(ResourceLoader $rl, ResourceLoaderContext $context) {
		self::addExtraHeaders( $context->getRequest()->response() );
		return true;
	}

	/**
	 * Add X-Served-By and X-Backend-Response-Time response headers to wikia.php
	 *
	 * @param WikiaApp $app
	 * @param WikiaResponse $response
	 * @return bool
	 * @author macbre
	 */
	static function onNirvanaAfterRespond(WikiaApp $app, WikiaResponse $response) {
		self::addExtraHeaders( $app->wg->Request->response() );
		return true;
	}

	/**
	 * Add X-Served-By and X-Backend-Response-Time response headers to api.php
	 *
	 * @param WebResponse $response
	 * @return bool
	 * @author macbre
	 */
	static function onApiMainBeforeSendCacheHeaders( WebResponse $response ) {
		self::addExtraHeaders( $response );
		return true;
	}

	/**
	 * Add X-Served-By and X-Backend-Response-Time response headers to index.php?action=ajax (MW ajax requests dispatcher)
	 *
	 * @return bool
	 * @author macbre
	 */
	static function onAjaxResponseSendHeadersAfter() {
		self::addExtraHeaders( F::app()->wg->Request->response() );
		return true;
	}

	/**
	 * Purge a limited set of language variants on Chinese wikis
	 *
	 * See BAC-1278 / BAC-698 for details
	 *
	 * @param Language $contLang
	 * @param array $variants
	 * @return bool
	 * @author macbre
	 */
	static function onTitleGetLangVariants(Language $contLang, Array &$variants) {
		switch($contLang->getCode()) {
			case 'zh':
				// skin displays links to these variants only
				$variants = ['zh-hans', 'zh-hant'];
				break;
		}

		return true;
	}

	/**
	 * No neeed to purge all thumbnails
	 *
	 * @author macbre
	 * @see PLATFORM-161
	 * @see PLATFORM-252
	 *
	 * @param LocalFile $file
	 * @param array $urls thumbs to purge
	 * @return bool
	 */
	static function onLocalFilePurgeThumbnailsUrls( LocalFile $file, Array &$urls ) {
		// purge only the first thumbnail
		$urls = array_slice($urls, 0, 1);

		return true;
	}

	/**
	 * Restrict editinterface right to whitelist
	 * set $result true to allow, false to deny, leave alone means don't care
	 * usually return true to allow processing other hooks
	 * return false stops permissions processing and we are totally decided (nothing later can override)
	 */
	static function canEditInterfaceWhitelist(
		Title $title, User $wgUser, string $action, &$result
	): bool {
		global $wgEditInterfaceWhitelist;

		// Allowed actions at this point
		$allowedActions = [
			'read',
			'move', // Is being checked in next hook canEditInterfaceWhitelistErrors
			'undelete' // Is being checked in next hook canEditInterfaceWhitelistErrors
		];

		// List the conditions we don't care about for early exit
		if ( in_array( $action, $allowedActions )
			|| $title->getNamespace() != NS_MEDIAWIKI
		) {
			return true;
		}

		// Allow trusted users to edit interface messages (util, vstf, select admins)
		if ( $wgUser->isAllowed( 'editinterfacetrusted' ) ) {
			return true;
		}

		if ( $action === 'delete' && $wgUser->isAllowed( 'deleteinterfacetrusted' ) ) {
			return true;
		}

		// In this NS, editinterface applies only to white listed pages
		if ( in_array( $title->getDBKey(), $wgEditInterfaceWhitelist )
			|| $title->isCssPage()
			|| ( Wikia::isUsingSafeJs() && $title->isJsPage() )
			|| startsWith( lcfirst( $title->getDBKey() ), self::CUSTOM_INTERFACE_PREFIX )
			|| startsWith( lcfirst( $title->getDBKey() ), self::EDITNOTICE_INTERFACE_PREFIX )
			|| startsWith( lcfirst( $title->getDBKey() ), self::TAG_INTERFACE_PREFIX )
			|| startsWith( lcfirst( $title->getDBKey() ), self::GADGETS_INTERFACE_PREFIX )
			|| startsWith( lcfirst( $title->getDBKey() ), self::GADGET_INTERFACE_PREFIX )
		) {
			return $wgUser->isAllowed( 'editinterface' );
		}

		return false;
	}

	/**
	 * Rights checks for MediaWiki namespace
	 * Prepares error message to throw when user is not allowed to do action within MediaWiki namespace
	 * @param Title $title Title on which action will be performed
	 * @param User $user User that wants to perform action
	 * @param $action action to perform
	 * @param $result Allows to pass error. Set $result true to allow, false to deny, leave alone means don't care
	 * @return bool False to break flow to throw an error, true to continue
	 */
	public static function canEditInterfaceWhitelistErrors( \Title $title, \User $user, $action, &$result ) {
		global $wgEditInterfaceWhitelist;

		if ( $title->inNamespace( NS_MEDIAWIKI )
			&& !$user->isAllowed( 'editinterfacetrusted' )
		) {
			// Restrict move
			if ( $action === 'move' ) {
				$result = [ \PermissionsError::prepareBadAccessErrorArray( 'editinterfacetrusted' ) ];
				return false;
			}

			// Restrict undelete
			if ( $action === 'undelete' && !in_array( $title->getDBKey(), $wgEditInterfaceWhitelist ) ) {
				$result = [ \PermissionsError::prepareBadAccessErrorArray( 'editinterfacetrusted' ) ];
				return false;
			}
		}

		return true;
	}

	/**
	 * Displays a warning when viewing site JS pages if JavaScript is disabled
	 * on the wikia.
	 *
	 * @param  OutputPage $out  The OutputPage object
	 * @param  Skin       $skin The Skin object that will be used to render the page.
	 * @return boolean
	 */
	public static function onBeforePageDisplay( OutputPage $out, Skin $skin ) {
		global $wgUseSiteJs;
		$title = $out->getTitle();

		if ( !$wgUseSiteJs && $title->isJsPage() ) {
			\BannerNotificationsController::addConfirmation( wfMessage( 'usesitejs-disabled-warning' )->parse(),
				\BannerNotificationsController::CONFIRMATION_NOTIFY );
		}

		return true;
	}

	/**
	 * Add a preference for enabling personal JavaScript.
	 *
	 * @param  User    $user        The current user.
	 * @param  array   $preferences The preferences array.
	 * @return boolean
	 */
	public static function onGetPreferences( User $user, array &$preferences ) {
		$preferences['enableuserjs'] = array(
			'type' => 'toggle',
			'label-message' => 'tog-enableuserjs',
			'section' => 'under-the-hood/advanced-displayv2',
		);
		return true;
	}

	/**
	 * Checks if a wikia is using safe mechanisms for using and editing custom JS pages.
	 * @return bool
	 */
	public static function isUsingSafeJs() {
		global $wgUseSiteJs, $wgEnableContentReviewExt;

		return !empty( $wgUseSiteJs ) && !empty( $wgEnableContentReviewExt );
	}

	public static function onWikiaSkinTopScripts( &$vars, &$scripts, Skin $skin ) {
		global $wgWikiDirectedAtChildrenByFounder;

		if ( !empty( $wgWikiDirectedAtChildrenByFounder ) ) {
			$vars['wgWikiDirectedAtChildrenByFounder'] = $wgWikiDirectedAtChildrenByFounder;
		}

		if ( self::isUsingSafeJs() ) {
			$vars['wgUseSiteJs'] = true;
		}

		return true;
	}

	/**
	 * Hook for storing historical log of email changes
	 * Depends on the central user_email_log table defined in the EditAccount extension
	 * @return bool
	 */
	public static function logEmailChanges($user, $new_email, $old_email) {
		global $wgExternalSharedDB, $wgUser, $wgRequest;
		if ( $wgExternalSharedDB && isset( $new_email ) && isset( $old_email ) ) {
			$dbw = wfGetDB( DB_MASTER, array(), $wgExternalSharedDB );
			$dbw->insert(
				'user_email_log',
				['user_id' => $user->getId(),
				 'old_email' => $old_email,
				 'new_email' => $new_email,
				 'changed_by_id' => $wgUser->getId(),
				 'changed_by_ip' => $wgRequest->getIP()		// stored as string
				]);
		}
		return true;
	}

	public static function surrogateKey( $args ) {
		global $wgCachePrefix;

		return 'mw-' . implode( '-', [ $wgCachePrefix ?: wfWikiID(), implode( '-', func_get_args() ) ] );
	}

	public static function sharedSurrogateKey( $args ) {
		return 'mw-' . implode( '-', func_get_args() );
	}

	public static function purgeSurrogateKey( $key ) {
		CeleryPurge::purgeBySurrogateKey( $key );
	}

}
