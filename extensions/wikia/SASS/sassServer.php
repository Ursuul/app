<?php
/**
 * @author: Sean Colombo
 *
 * This script will serve up CSS that has been created generated by SASS.
 * The script is responsible for verifying the cryptographic signature,
 * for preventing deadlock from similar requests, for piping colors from the
 * query-string into the .scss files, and for using memcache to speed up responses.
 *
 * NOTE: While SASS can output to standard out, that only happens if now output file
 * is specified.  Since we are using additional command line params, those params end up
 * being used as the output filename if we don't provide one, so for now we are stuck with
 * a tmp file.
 * An alternative would be to write a daemon in Ruby (instead of this PHP script) which would
 * use Sass as a module rather than via the command-line... but that comes with its own set
 * of problems (performance concerns of having a single daemon instead of process-per-request as
 * well as having the challenge of not having the MediaWiki stack available for connecting to the
 * right memcached, etc.).
 *
 * Expected URL params:
 * - "file" (the full filename of the .scss file to parse - eg: "skins/oasis/css/oasis.scss")
 * - "styleVersion" (the wgStyleVersion at the time the calling-code was generated)
 * - "hash" (a cryptographic signature generated by the SassUtil::getSecurityHash() function)
 */

///// CONFIGURATION /////
// There should be a symlink in the docroot (often /usr/wikia/docroot/wiki.factory) pointing to /extensions/wikia/SASS/wikia_sass.rb (or wherever the file actually is).
$RUBY_MODULE_SCRIPT = "wikia_sass.rb";
$FULL_SASS_PATH = "/var/lib/gems/1.8/bin/sass";
$TMP_DIR = "/tmp/sass";
$MEMC_KEY_PREFIX = "sassServer";
$CHECK_BACK_LATER_MESSAGE = "/* processing */";
$CHECK_BACK_CACHE_DURATION = 30; // nice & short.  If it can't generate in this time, it's likely that something went wrong.
$CHECK_BACK_IN_MICROSECONDS = 200000; // 1,000,000 per second.
$CHECK_BACK_MAX_RETRIES = (1000000 / $CHECK_BACK_IN_MICROSECONDS) * ($CHECK_BACK_CACHE_DURATION + 1); // if we don't get a response in this time, we'll stop waiting.
$CSS_CACHE_DURATION = 60 * 60 * 12; // how long to cache the computed CSS in memcache

// Security-hash checking is off for now because it wouldn't allow for arbitrary style like that from the ThemeDesigner.
$CHECK_SECURITY_HASH = false;

// Chose the output style - {nested, expanded, compact, compressed} see: http://sass-lang.com/docs/yardoc/file.SASS_REFERENCE.html#output_style
$OUTPUT_STYLE = "compact";

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = dirname( __FILE__ ) .'/../../..';
}
///// CONFIGURATION /////


// Load the MediaWiki stack.
require( dirname(__FILE__) . '/../../../includes/WebStart.php' );

	$errorStr = "";

	// Get the special parameters from the URL and unset them so that only the sass-params remain in the _GET array.
	$inputFile = getFromUrlAndUnset("file"); // Get the path & file that the user is actually looking for.
	$requestedStyleVersion = getFromUrlAndUnset("styleVersion", $wgStyleVersion); // Get style version to use for keys (don't just use wgStyleVersion).
	$hashFromUrl = getFromUrlAndUnset("hash");
	$nameOfFile = getFileAlphanumeric($inputFile); // Build a reasonable name for the tmp file.

	$idString = ""; // will be filled with a filename-safe string unique to this set of sass parameters.
	$sassParamsForHashChecking = "";
	$sassParams = getSassParamsFromUrl($idString, $sassParamsForHashChecking); // Build a string of parameters to pass into sass.

	// The filename gets impractically long... so we'll hash the idString in the filename.
	$tmpFile = "$TMP_DIR/$nameOfFile"."_$requestedStyleVersion"."_".md5($idString).".css";

	$cssContent = "";
	if(!securityHashIsOkay($requestedStyleVersion, $sassParamsForHashChecking, $hashFromUrl)){
		$errorStr .= "Invalid request: signature was invalid.\n";
		Wikia::log( __METHOD__, "", "There was an attempt to access a SASS sheet with an invalid cryptographic signature. If there are many of these, then there is either a bug (most likely) or a failed attack.");
	} else {
		// Use memcache to see if we should process now, used stored value, or wait and check back (because another process is already working on it).
		$memcKey = wfMemcKey($MEMC_KEY_PREFIX, $nameOfFile, $requestedStyleVersion, md5($idString));
		$cachedResult = $wgMemc->get($memcKey);
		if(empty($cachedResult)){
			// Add a placeholder to memcached so that other processes with the same parameters just wait for this result instead of generating their own.
			$wgMemc->set($memcKey, $CHECK_BACK_LATER_MESSAGE, $CHECK_BACK_CACHE_DURATION);

			// Since there was no memcached result yet, process the scss file.
			$cssContent = runSass($inputFile, $tmpFile, $sassParams, $errorStr);

			// Store the value for the other waiting threads to access.
			$wgMemc->set($memcKey, $cssContent, $CSS_CACHE_DURATION);
		} else if($cachedResult == $CHECK_BACK_LATER_MESSAGE){
			$numRetries = 0;
			while(($cachedResult == $CHECK_BACK_LATER_MESSAGE) && ($numRetries < $CHECK_BACK_MAX_RETRIES)){
				$numRetries++;
				usleep($CHECK_BACK_IN_MICROSECONDS);

				$cachedResult = $wgMemc->get($memcKey);

				if(empty($cachedResult)){
					// If the cache expired, then we give up on the other process and try to generate it ourselves.
					$cssContent = runSass($inputFile, $tmpFile, $sassParams, $errorStr);
				} else if($cachedResult == $CHECK_BACK_LATER_MESSAGE){
					if($numRetries >= $CHECK_BACK_MAX_RETRIES){
						// For now, assume that the processing was too intense and just give up.
						$errorStr .= "Timout: Hit max-retries while waiting for another process to generate the CSS.\n";
					}
				} else {
					$cssContent = $cachedResult;
					break;
				}
			}
		} else {
			$cssContent = $cachedResult;
		}
	}

	outputHeadersAndCss($cssContent, $errorStr);




/**
 * Returns true if security-hash-checking is off or if the hash given in the URL
 * matches the hash that getSecurityHash() would return for the same inputs as
 * this function.
 *
 * The purpose of this is to let us sign URLs so that users can't just create
 * as many URLs as they want and DoS us by stressing our CPUs with Sass processing.
 *
 * This expects the sassParams in the format that SassUtil::getSassParams() delivers them,
 * that is: urlencoded.  This is usually the format that you'll have the sassParams in already.
 */
function securityHashIsOkay($styleVersion, $sassParams, $hashFromUrl){
	global $CHECK_SECURITY_HASH;
	$hashIsOkay = true;
	if(($CHECK_SECURITY_HASH) && ($hashFromUrl != SassUtil::getSecurityHash($styleVersion, $sassParams))){
		$hashIsOkay = false;
	}
	return $hashIsOkay;
} // end securityHashIsOkay()

/**
 * Given some configuration, runs sass on the inputFile, sends output to the tmpFile, passes the sassParams
 * into sass via the command line, then reads the tmpFile, deletes the tmpFile, and returns the CSS which
 * the tmpFile contained.  Any erorrs are appended to errorStr.
 */
function runSass($inputFile, $tmpFile, $sassParams, &$errorStr){
	global $FULL_SASS_PATH, $IP, $OUTPUT_STYLE, $RUBY_MODULE_SCRIPT;
	wfProfileIn( __METHOD__ );

	// Pass the values from the query-string into the sass script (results will go in a tmp file).
	$commandLine = escapeshellcmd("$FULL_SASS_PATH $IP/$inputFile $tmpFile --style $OUTPUT_STYLE -r $RUBY_MODULE_SCRIPT $sassParams 2>&1");

	$sassResult = `$commandLine`;
	if($sassResult != ""){
		// On the first failure, check if this is because /tmp/sass doesn't exist anymore (it will go away on reboot, etc.).
		global $TMP_DIR;
		if(!is_dir($TMP_DIR)){
			if(!mkdir($TMP_DIR, 0755, true)){
				$cantCreateErr = "Sass server's tmp dir didn't exist and could not be created: \"$TMP_DIR\"";
				$errorStr .= $cantCreateErr;
				Wikia::log( __METHOD__, "", $cantCreateErr);
			} else {
				// We created the tmp directory, now retry the sass parsing.
				$sassResult = `$commandLine`;
				if($sassResult != ""){
					$errorStr .= "Sass commandline error: $sassResult\n";
				}
			}
		} else {
			$errorStr .= "Sass commandline error: $sassResult\n";
		}
	}

	$cssContent = readThenDeleteFile($tmpFile, $errorStr);

	///// ADDITIONAL POST-SASS PROCESSING BELOW /////

	// Do some post-processing so that @imports of .css files are included right in the content (SASS will pull-in .scss files, but leaves .css @imports alone).
	// NOTE: This expects all .css references from inside of .scss files to be relative to the root of the project rather than to the .scss file whence they're being imported.
	$matches = array();
	$importRegexOne = "/@import ['\\\"]([^\\n]*\\.css)['\\\"]([^\\n]*)(\\n|$)/is"; // since this stored is in a string, remember to escape quotes, slashes, etc.
	$importRegexTwo = "/@import url[\\( ]['\\\"]?([^\\n]*\\.css)['\\\"]?[ \\)]([^\\n]*)(\\n|$)/is";
	if((0 < preg_match_all($importRegexOne, $cssContent, $matches, PREG_SET_ORDER))
		|| (0 < preg_match_all($importRegexTwo, $cssContent, $matches, PREG_SET_ORDER))){
		foreach($matches as $match){
			$lineMatched = $match[0];
			$fileName = trim($match[1]);

			$fileContents = file_get_contents($IP . $fileName);

			// Check for nested imports and generate a warning if they are found (.css shouldn't be using @imports).
			if((0 < preg_match($importRegexOne, $fileContents)) || (0 < preg_match($importRegexTwo, $fileContents))){
				$errorStr .= "Bad for performance: @import detected from inside of a .css file (this results in an extra HTTP request). The import is inside the file: \"$fileName\".";
				$errorStr .= " - Please change that file to a .scss file if you need to import something into it.\n";
			}

			$cssContent = str_replace($lineMatched, $fileContents, $cssContent);
		}
	}

	// Apply wgStylePath substitutions like in StaticChute.
	require "$IP/extensions/wikia/StaticChute/wfReplaceCdnStylePathInCss.php";
	$cssContent = wfReplaceCdnStylePathInCss($cssContent);

	// If RTL is set as a sass-param, then pass the entire output through cssjanus to convert the CSS to right-to-left.
	if($sassParams && (0 < preg_match("/(^| )rtl=/i", $sassParams)) && !(0 < preg_match("/(^| )rtl=(false|0)/i", $sassParams)) ){
		$descriptorspec = array(
		   0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
		   1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
		   2 => array("pipe", "a")
		);
		$cwd = '';
		$env = array();
		$process = proc_open("./cssjanus.py", $descriptorspec, $pipes, NULL, $env);
		if (is_resource($process)) {
		    fwrite($pipes[0], $cssContent);
		    fclose($pipes[0]);

		    $cssContent = stream_get_contents($pipes[1]);
		    fclose($pipes[1]);
		    fclose($pipes[2]);

		    // proc_close in order to avoid a deadlock
		    proc_close($process);
		}
	}

	wfProfileOut( __METHOD__ );
	return $cssContent;
} // end runSass()


/**
 * Convenience-wrapper around getting the contents of tmpFile with graceful error handling.
 *
 * NOTE: Since it is a tmp file, when it is done being read, THE TMP FILE WILL BE DELETED!
 *
 * If there is an error, it will be appended to the 'errorStr' parameter.
 */
function readThenDeleteFile($tmpFile, &$errorStr=""){
	// Get the contents of the temporary file then delete the file.
	$cssContent = @file_get_contents($tmpFile);
	if($cssContent === false){
		$errorStr .= "Could not open tmp file \"$tmpFile\".  Make sure the apache process still has the right permissions to write to this directory.\n";
	}
	@unlink($tmpFile);
	return $cssContent;
} // end readThenDeleteFile()

/**
 * Prints out the headers and content of the CSS when given the name of a tmpFile containing the CSS
 * and an optional error string containing any error-information to add (inside of a comment) at the
 * bottom of the file.
 */
function outputHeadersAndCss($cssContent, $errorStr=""){
	// Print the generated CSS (with correct headers)
	header("Content-type: text/css");


	// TODO: Should we detect the error-case where 'tmpFile' doesn't exist?  What this would imply to me was that there was a race-condition and another
	// process was creating the file slightly before this one, and it managed to delete the file before we got to read it.  In this case, the result would be in memcached either now or
	// in a few miliseconds.


	// Since this emits a header, it needs to be done before printing content.
	$timeToGenerate = "/* ".wfReportTime()." */";

	print $cssContent;

	// If there was an error, print it out into the resulting CSS.
	if($errorStr != ""){
		print "\n/*\n   sassServer ERROR: $errorStr */\n";
		// TODO: Should we also output some really subtle marking in CSS (a red dot somewhere?) to indicate to us when we're browsing that we should look at the css code to see the error (like the thin-red-line on Pedlr).
		// NOTE: If we wanted to, we could output this data above only when on devel environments (is the var called $wgDevelEnvironment?)
	}

	print "\n".$timeToGenerate; // Print the server and how long it took to serve this file.
} // end outputHeadersAndCss()


/**
 * Gets the given parameter from the url, then unsets it from the _GET array
 * so that it isn't confused for being an actual sass-parameter to be passed
 * through to the command-line).
 */
function getFromUrlAndUnset($paramName, $default=""){
	$retVal = $default;
	if(isset($_GET[$paramName])){
		$retVal = $_GET[$paramName];

		// Unset so that it isn't used as a sass-param.
		unset($_GET[$paramName]);
	}
	return $retVal;
} // end getFromUrlAndUnsest()


/**
 * Returns a file-safe memcache-key-safe version of the filename.
 */
function getFileAlphanumeric($inputFile){
	// Must be based off of the full path since we have .scss files with the same name in several directories.
	$safeName = str_replace("/", "-", $inputFile);
	$safeName = str_replace("\\", "-", $safeName); // be nice to windows-users.
	$safeName = preg_replace("/[^A-Za-z0-9_-]/", "", $safeName);
	return $safeName;
} // end getFileAlphanumeric()


/**
 * Uses the $_GET array to construct a string of key/value pairs to pass into the SASS command line.
 * Since the output is designed for the command-line, the key and value are separated by an equals sign
 * and the pairs are separated from each other by spaces.
 *
 * The sassParamsForHashChecking string will be similar to sassParams except that the values will be
 * urlencoded just like in the normal generation by SassUtil.
 *
 * Modifies the 'idString' param to have a short, filename-safe version of the parameters.  This can
 * be used as the suffix for the tmpFile or as part of a memcached key.
 */
function getSassParamsFromUrl(&$idString='', &$sassParamsForHashChecking=''){
	$sassParams = "";
	foreach($_GET as $key => $value){
		$sassParams .= ($sassParams == ""?"":" ");
		$sassParams .= "$key=$value";
		$sassParamsForHashChecking .= ($sassParamsForHashChecking == ""?"":" ");
		$sassParamsForHashChecking .= "$key=".urlencode($value);

		$keyId = preg_replace("/[^A-Za-z0-9]/", "", $key);
		$valueId = preg_replace("/[^A-Za-z0-9]/", "", $value);
		$idString .= ($idString==""?"":"_");
		$idString .= "$keyId-$valueId";
	}

	return $sassParams;
} // end getSassParamsFromUrl()
