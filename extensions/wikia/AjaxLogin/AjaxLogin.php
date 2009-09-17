<?php
/*
 * Author: Inez Korczynski
 */

$wgExtensionCredits['other'][] = array(
	'name' => 'AjaxLogin',
	'description' => 'Dynamic box which allow users to login and remind password',
	'author' => 'Inez Korczyński'
);

$wgHooks['GetHTMLAfterBody'][] = 'GetAjaxLoginForm';

function GetAjaxLoginForm($skin) {
	global $wgTitle, $wgUser;

	// different approach for Lean Monaco
	global $wgUseMonaco2;
	if (!empty($wgUseMonaco2)) {
		return true;
	}

	if ($wgUser->isAnon() && $wgTitle->getNamespace() != 8 && $wgTitle->getDBkey() != 'Userlogin') {
		$tmpl = new EasyTemplate(dirname( __FILE__ ));
		echo $tmpl->execute('AjaxLogin');
	}
	return true;
}

$wgAjaxExportList[] = 'GetAjaxLogin';
function GetAjaxLogin() {
	$tmpl = new EasyTemplate(dirname( __FILE__ ));

	$response = new AjaxResponse();
	$response->addText( $tmpl->execute('AwesomeAjaxLogin') );
	$response->setCacheDuration( 3600 * 24 * 365 * 10); // 10 years

	header("X-Pass-Cache-Control: s-maxage=315360000, max-age=315360000");

	return $response;
}
