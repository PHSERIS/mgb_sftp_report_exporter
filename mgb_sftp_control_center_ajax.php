<?php

namespace MGB\MGBSFTPReportExporter;
/**
 * Control Center Ajax stuff
 */
use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

if (is_null($module)) { exit(); }
if ( strpos(get_class($module),"MGBSFTPReportExporter") == false ) { exit(); }

if ( !isset($_POST['cron_debug']) || !is_numeric($_POST['cron_debug']) ) exit('[]');
$en_dis_cron = trim(strip_tags(html_entity_decode($_POST['cron_debug'], ENT_QUOTES)));

if ( $en_dis_cron == 1 ) {
    $module->setSystemSetting('sftp-cron-debug',1);
}
elseif( $en_dis_cron == 0 ) {
    $module->setSystemSetting('sftp-cron-debug',0);
}
else {
    // Nothing
}

$result = [
    'status' => 'OK',
    'status_message' => 'OK',
];
print json_encode($result);
exit();