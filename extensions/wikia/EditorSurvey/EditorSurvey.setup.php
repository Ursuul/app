<?php

$dir = dirname(__FILE__) . '/';

// classes
$wgAutoloadClasses['EditorSurveyController'] =  $dir . 'EditorSurveyController.class.php';
$wgAutoloadClasses['WAMApiController'] = $dir . '../WAM/controllers/api/WAMApiController.class.php';

// i18n
$wgExtensionMessagesFiles['EditorSurvey'] = $dir . 'EditorSurvey.i18n.php';

