<?php
/**
 * Chat
 *
 * Live chat for MediaWiki
 *
 * @author Christian Williams <christian@wikia-inc.com>, Sean Colombo <sean@wikia-inc.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 * @package MediaWiki
 *
 */

$wgExtensionCredits['specialpage'][] = array(
	'name' => 'Chat',
	'author' => array( 'Christian Williams', 'Sean Colombo' ),
	'url' => 'http://community.wikia.com/wiki/Chat',
	'descriptionmsg' => 'chat-desc',
);

$app = F::app();
$dir = dirname(__FILE__);

// rights
$wgAvailableRights[] = 'chatmoderator';
$wgGroupPermissions['*']['chatmoderator'] = false;
$wgGroupPermissions['sysop']['chatmoderator'] = true;
$wgGroupPermissions['staff']['chatmoderator'] = true;
$wgGroupPermissions['staff']['chatstaff'] = true;
$wgGroupPermissions['helper']['chatmoderator'] = true;
$wgGroupPermissions['chatmoderator']['chatmoderator'] = true;
$wgAvailableRights[] = 'chat';
$wgGroupPermissions['*']['chat'] = false;
$wgGroupPermissions['user']['chat'] = true;

$wgGroupPermissions['util']['chatfailover'] = true;

// Allow admins to control banning/unbanning and chatmod-status


// Let staff & helpers change chatmod & banning status.
if( !is_array($wgAddGroups['staff']) ){
	$wgAddGroups['staff'] = array();
}
if( !is_array($wgAddGroups['helper']) ){
	$wgAddGroups['helper'] = array();
}
if( !is_array($wgRemoveGroups['staff']) ){
	$wgRemoveGroups['staff'] = array();
}
if( !is_array($wgRemoveGroups['helper']) ){
	$wgRemoveGroups['helper'] = array();
}

/*
$wgRemoveGroups['sysop'][] = 'bannedfromchat';
$wgAddGroups['sysop'][] = 'bannedfromchat';
$wgAddGroups['chatmoderator'][] = 'bannedfromchat';
$wgRemoveGroups['chatmoderator'][] = 'bannedfromchat';
$wgAddGroups['staff'][] = 'bannedfromchat';
$wgRemoveGroups['staff'][] = 'bannedfromchat';
$wgAddGroups['helper'][] = 'bannedfromchat';
$wgRemoveGroups['helper'][] = 'bannedfromchat';

// Attempt to do the permissions the other way (adding restriction instead of subtracting permission).
// When in 'bannedfromchat' group, the 'chat' permission will be revoked
// See http://www.mediawiki.org/wiki/Manual:$wgRevokePermissions
$wgRevokePermissions['bannedfromchat']['chat'] = true;

*/


$wgAddGroups['staff'][] = 'chatmoderator';
$wgRemoveGroups['staff'][] = 'chatmoderator';
$wgAddGroups['helper'][] = 'chatmoderator';
$wgRemoveGroups['helper'][] = 'chatmoderator';
$wgAddGroups['sysop'][] = 'chatmoderator';
$wgRemoveGroups['sysop'][] = 'chatmoderator';


// autoloaded classes
$wgAutoloadClasses['Chat'] = "$dir/Chat.class.php";
$wgAutoloadClasses['ChatAjax'] = "$dir/ChatAjax.class.php";
$wgAutoloadClasses['ChatHelper'] = "$dir/ChatHelper.php";
$wgAutoloadClasses['ChatEntryPoint'] = "$dir/ChatEntryPoint.class.php";
$wgAutoloadClasses['ChatController'] = "$dir/ChatController.class.php";
$wgAutoloadClasses['ChatRailController'] = "$dir/ChatRailController.class.php";
$wgAutoloadClasses['SpecialChat'] = "$dir/SpecialChat.class.php";
$wgAutoloadClasses['NodeApiClient'] = "$dir/NodeApiClient.class.php";
$wgAutoloadClasses['ChatfailoverSpecialController'] = "$dir/ChatfailoverSpecialController.class.php";

$app->registerSpecialPage('Chatfailover', 'ChatfailoverSpecialController');

// special pages
$wgSpecialPages['Chat'] = 'SpecialChat';

// i18n
$wgExtensionMessagesFiles['Chat'] = $dir.'/Chat.i18n.php';
$wgExtensionMessagesFiles['ChatDefaultEmoticons'] = $dir.'/ChatDefaultEmoticons.i18n.php';

// hooks
$wgHooks[ 'GetRailModuleList' ][] = 'ChatHelper::onGetRailModuleList';
$wgHooks[ 'StaffLog::formatRow' ][] = 'ChatHelper::onStaffLogFormatRow';
$wgHooks[ 'MakeGlobalVariablesScript' ][] = 'ChatHelper::onMakeGlobalVariablesScript';
$wgHooks[ 'ParserFirstCallInit' ][] = 'ChatEntryPoint::onParserFirstCallInit';
$wgHooks[ 'LinkEnd' ][] = 'ChatHelper::onLinkEnd';
$wgHooks[ 'BeforePageDisplay' ][] = 'ChatHelper::onBeforePageDisplay';
$wgHooks[ 'ContributionsToolLinks' ][] = 'ChatHelper::onContributionsToolLinks';

$wgHooks[ 'LogLineExt' ][] = 'ChatHelper::onLogLineExt';

$wgLogTypes[] = 'chatban';
$wgLogHeaders['chatban'] = 'chat-chatban-log';
$wgLogNames['chatban'] = 'chat-chatban-log';

$wgLogTypes[] = 'chatconnect';
$wgLogHeaders['chatconnect'] = 'chat-chatconnect-log';
$wgLogNames['chatconnect'] = 'chat-chatconnect-log';
$wgLogActions['chatconnect/chatconnect'] = 'chat-chatconnect-log-entry';
$wgLogRestrictions["chatconnect"] = 'checkuser';

$wgHooks[ 'LogLine' ][] = 'ChatHelper::onLogLine';

$wgLogActionsHandlers['chatban/chatbanchange'] = "ChatHelper::formatLogEntry";
$wgLogActionsHandlers['chatban/chatbanremove'] = "ChatHelper::formatLogEntry";
$wgLogActionsHandlers['chatban/chatbanadd'] = "ChatHelper::formatLogEntry";


// register messages package for JS
F::build('JSMessages')->registerPackage('Chat', array(
	'chat-*',
));

F::build('JSMessages')->registerPackage('ChatBanModal', array(
	'chat-log-reason-banadd',
	'chat-ban-modal-change-ban-heading',
	'chat-ban-modal-button-cancel',
	'chat-ban-modal-button-ok',
	'chat-ban-modal-button-change-ban',
));


define( 'CHAT_TAG', 'chat' );
define( 'CUC_TYPE_CHAT', 128);	// for CheckUser operation type

// ajax
$wgAjaxExportList[] = 'ChatAjax';
function ChatAjax() {

	error_log(var_export($_REQUEST, true));

	global $wgRequest, $wgUser, $wgMemc;
	$method = $wgRequest->getVal('method', false);

	if (method_exists('ChatAjax', $method)) {
		wfProfileIn(__METHOD__);

		// Don't let Varnish cache this.
		header("X-Pass-Cache-Control: max-age=0");

		$key = $wgRequest->getVal('key');

		// macbre: check to protect against BugId:27916
		if (!is_null($key)) {
			$data = $wgMemc->get( $key, false );
			if( !empty($data) ) {
				$wgUser = User::newFromId( $data['user_id'] );
			}
		}

		$data = ChatAjax::$method();

		// send array as JSON
		$json = Wikia::json_encode($data);
		$response = new AjaxResponse($json);
		$response->setCacheDuration(0); // don't cache any of these requests
		$response->setContentType('application/json; charset=utf-8');

		wfProfileOut(__METHOD__);
		return $response;
	}
}
