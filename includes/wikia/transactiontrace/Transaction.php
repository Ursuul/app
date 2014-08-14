<?php

/**
 * Class Transaction defines various constants and gives access to TransactionTrace singleton object
 */
class Transaction {
	// Transaction names
	const ENTRY_POINT_PAGE = 'page';
	const ENTRY_POINT_SPECIAL_PAGE = 'special_page';
	const ENTRY_POINT_RESOURCE_LOADER = 'assets/resource_loader';
	const ENTRY_POINT_ASSETS_MANAGER = 'assets/assets_manager';
	const ENTRY_POINT_NIRVANA = 'api/nirvana';
	const ENTRY_POINT_AJAX = 'api/ajax';
	const ENTRY_POINT_API = 'api/api';

	// Parameters
	const PARAM_ENTRY_POINT = 'entry_point';
	const PARAM_LOGGED_IN = 'logged_in';
	const PARAM_PARSER_CACHE_USED = 'parser_cache_used';
	const PARAM_SIZE_CATEGORY = 'size_category';
	const PARAM_NAMESPACE = 'namespace';
	const PARAM_ACTION = 'action';
	const PARAM_SKIN = 'skin';
	const PARAM_VERSION = 'version';
	const PARAM_VIEW_TYPE = 'view_type'; // For Category only
	const PARAM_CONTROLLER = 'controller';
	const PARAM_METHOD = 'method';
	const PARAM_FUNCTION = 'function';
	const PARAM_SPECIAL_PAGE_NAME = 'special_page';
	const PARAM_API_ACTION = 'api_action';
	const PARAM_WIKI = 'wiki';

	const PSEUDO_PARAM_TYPE = 'type';

	// Definition of different size categories
	const SIZE_CATEGORY_SIMPLE = 'simple';
	const SIZE_CATEGORY_AVERAGE = 'average';
	const SIZE_CATEGORY_COMPLEX = 'complex';

	/**
	 * Returns TransactionTrace singleton instance
	 *
	 * @return TransactionTrace
	 */
	public static function getInstance() {
		static $instance;
		if ( $instance === null ) {
			$instance = new TransactionTrace( array(
				// plugins
				new TransactionTraceNewrelic(),
			) );
		}
		return $instance;
	}

	/**
	 * Sets an entry point attribute
	 *
	 * @param string $entryPoint Entry point - should be one of Transaction::ENTRY_POINT_xxxxx
	 */
	public static function setEntryPoint( $entryPoint ) {
		self::getInstance()->set( self::PARAM_ENTRY_POINT, $entryPoint );
	}

	/**
	 * Sets a named attribute to be recorded in transaction trace
	 *
	 * @param string $key Name of the parameter - should be one of Transaction::PARAM_xxxxx
	 * @param string $value Value of the parameter
	 */
	public static function setAttribute( $key, $value ) {
		self::getInstance()->set( $key, $value );
	}

	/**
	 * Shorthand for setting "size category" attribute based on thresholds
	 *
	 * @param int $observationCounter Current value
	 * @param int $lowerBound Maximum value that classifies as "simple"
	 * @param int $middleBound Maximum value that classifies as "average"
	 */
	public static function setSizeCategoryByDistributionOffset( $observationCounter, $lowerBound, $middleBound ) {
		if ( $observationCounter <= $lowerBound ) {
			self::setAttribute( self::PARAM_SIZE_CATEGORY, self::SIZE_CATEGORY_SIMPLE );
		} elseif ( $observationCounter <= $middleBound ) {
			self::setAttribute( self::PARAM_SIZE_CATEGORY, self::SIZE_CATEGORY_AVERAGE );
		} else {
			self::setAttribute( self::PARAM_SIZE_CATEGORY, self::SIZE_CATEGORY_COMPLEX );
		}
	}

	/**
	 * Returns the automatically generated transaction type name
	 *
	 * @return string
	 */
	public static function getType() {
		return self::getInstance()->getType();
	}

	/**
	 * Returns all the attributes of the current transaction
	 *
	 * @return array
	 */
	public static function getAll() {
		return self::getInstance()->getAll();
	}

	/**
	 * Hook handler. Sets a "size category" attribute based on the article that is displayed
	 *
	 * @param Article $article
	 * @param ParserOutput $parserOutput
	 * @return bool true (hook handler)
	 */
	public static function onArticleViewAddParserOutput( Article $article, ParserOutput $parserOutput ) {
		$wikitextSize = $parserOutput->getPerformanceStats( 'wikitextSize' );
		$htmlSize = $parserOutput->getPerformanceStats( 'htmlSize' );
		$expFuncCount = $parserOutput->getPerformanceStats( 'expFuncCount' );
		$nodeCount = $parserOutput->getPerformanceStats( 'nodeCount' );

		if ( !is_numeric( $wikitextSize ) || !is_numeric( $htmlSize ) || !is_numeric( $expFuncCount ) || !is_numeric( $nodeCount ) ) {
			return true;
		}

		if ( $wikitextSize < 3000 && $htmlSize < 5000 && $expFuncCount == 0 && $nodeCount < 100 ) {
			$sizeCategory = self::SIZE_CATEGORY_SIMPLE;
		} elseif ( $wikitextSize < 30000 && $htmlSize < 50000 && $expFuncCount <= 4 && $nodeCount < 3000 ) {
			$sizeCategory = self::SIZE_CATEGORY_AVERAGE;
		} else {
			$sizeCategory = self::SIZE_CATEGORY_COMPLEX;
		}

		Transaction::setAttribute( Transaction::PARAM_SIZE_CATEGORY, $sizeCategory );

		return true;
	}

	/**
	 * Given the list of respons headers detect whether the response can be cached on CDN
	 *
	 * We assume that the response is cacheable if s-maxage entry in Cache-Control header
	 * is greater than 5 seconds - refer to WikiaResponse::setCacheValidity
	 *
	 * Examples:
	 *
	 * - Cache-Control: s-maxage=86400, must-revalidate, max-age=0 (an article, cacheable)
	 * - Cache-Control: public, max-age=2592000 (AssetsManager, cacheable)
	 * - Cache-Control: private, must-revalidate, max-age=0 (special page, not cacheable)
	 *
	 * @param array $headers key - value list of HTTP response headers
	 * @return bool|null will return null for maintenance / CLI scripts
	 */
	public static function isCacheable( $headers ) {
		if ( empty( $headers['Cache-Control'] ) ) {
			return null;
		}

		$cacheControl = $headers['Cache-Control'];
		$sMaxAge = 0;

		// has "private" entry?
		if ( strpos( $cacheControl, 'private' ) !== false ) {
			$sMaxAge = 0;
		}
		// has "s-maxage" entry?
		else if ( preg_match( '#s-maxage=(\d+)#', $cacheControl, $matches ) ) {
			$sMaxAge = intval( $matches[1] );
		}
		// has "max-age" entry?
		else if ( preg_match( '#max-age=(\d+)#', $cacheControl, $matches ) ) {
			$sMaxAge = intval( $matches[1] );
		}

		// TODO: report $sMaxAge value?
		return $sMaxAge > 5;
	}

	/**
	 * Analyze the response header and set "cacheablity" flag
	 *
	 * @return bool true (hook handler
	 */
	public static function onRestInPeace() {
		if ( function_exists( 'apache_response_headers' ) ) {
			$isCacheable = self::isCacheable( apache_response_headers() );

			if ( is_bool( $isCacheable ) ) {
				self::setAttribute( 'cacheable', $isCacheable );
			}
		}
		return true;
	}
}