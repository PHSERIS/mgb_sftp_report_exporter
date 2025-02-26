<?php
namespace MGB\MGBSFTPReportExporter;
/**
 * Manage the module configs
 * The main idea here is to hide the data that should NOT be shown to the users
 */
use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;
use DataExport;
use Logging;
use MetaData;


if (is_null($module)) { exit(); }
if ( strpos(get_class($module),"MGBSFTPReportExporter") == false ) { exit(); }

global $Proj;

// Get the config number that we want to nix
if ( !isset($_POST['cfg']) || !is_numeric($_POST['cfg']) ) exit('[]');
$cfg = trim(strip_tags(html_entity_decode($_POST['cfg'], ENT_QUOTES)));

$target_sites       = $module->getProjectSetting('sftp-sites');
$target_remote_types    = $module->getProjectSetting('remote-site-type');
$target_names       = $module->getProjectSetting('sftp-site-name');
$target_hosts       = $module->getProjectSetting('sftp-site-host');
$target_ports       = $module->getProjectSetting('sftp-site-port');
$target_users       = $module->getProjectSetting('sftp-site-user');
$target_pwds        = $module->getProjectSetting('sftp-site-pwd');
$target_pkis        = $module->getProjectSetting('sftp-site-pk');
$target_auth        = $module->getProjectSetting('sftp-site-auth-method'); // 1=PWD, 2=Key

// Path
$target_paths       = $module->getProjectSetting('sftp-site-folder');

// S3 stuff
$target_buckets     = $module->getProjectSetting('s3-bucket-name');
$target_regions     = $module->getProjectSetting('s3-region-name');

unset($target_sites[$cfg]);
$module->setProjectSetting('sftp-sites',$target_sites);
unset($target_remote_types[$cfg]);
$module->setProjectSetting('remote-site-type',$target_remote_types);
unset($target_names[$cfg]);
$module->setProjectSetting('sftp-site-name',$target_names);
unset($target_hosts[$cfg]);
$module->setProjectSetting('sftp-site-host',$target_hosts);
unset($target_ports[$cfg]);
$module->setProjectSetting('sftp-site-port',$target_ports);
unset($target_users[$cfg]);
$module->setProjectSetting('sftp-site-user',$target_users);
unset($target_auth[$cfg]);
$module->setProjectSetting('sftp-site-auth-method',$target_auth);
unset($target_pwds[$cfg]);
$module->setProjectSetting('sftp-site-pwd',$target_pwds);
unset($target_pkis[$cfg]);
$module->setProjectSetting('sftp-site-pk',$target_pkis);
unset($target_paths[$cfg]);
$module->setProjectSetting('sftp-site-folder',$target_paths);
unset($target_buckets[$cfg]);
$module->setProjectSetting('s3-bucket-name',$target_buckets);
unset($target_regions[$cfg]);
$module->setProjectSetting('s3-region-name',$target_regions);

// Delete any active schedules associated with this
// CONFIGID_REPORTID
$schedules      = $module->getProjectSetting('project_report_schedules');
foreach ( $schedules as $cr => $cr_details ) {
    $parts = explode("_",$cr);
    $config_id = $parts[0];
    $report_id = $parts[1];

    if ( $config_id == $cfg )
        unset($schedules[$cr]); // remove it from the schedules
}
$module->setProjectSetting('project_report_schedules', $schedules);

$result = [
    'status' => 'OK',
    'status_message' => 'Configuration Deleted',
];
print json_encode($result);
exit();