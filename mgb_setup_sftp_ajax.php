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

// We are going to be given:
// SFTP Name
// SFTP Host
// SFTP Port
// SFTP User
// SFTP Authentication Method
// SFTP PWD
// SFTP Key

// UPDATE for S3 - we need the "TYPE" to be provided
if ( !isset($_POST['type']) ) exit('[]');
$remote_type = trim(strip_tags(html_entity_decode($_POST['type'], ENT_QUOTES)));
if ( !array_key_exists($remote_type, $module->getAllowedRemoteTypes()) ) {
    $result = [
        'status' => 'ERROR',
        'status_message' => 'Invalid Remote Type Requested!',
    ];
    print json_encode($result);
    exit();
} else {
    foreach ( $module->getAllowedRemoteTypes() as $rt => $rl ) {
        if ( $rt == $remote_type ) {
            $remote_type = $rt; // This is subtle, but it's more secure to use this than user input
            break;
        }
    }
}

if ( $remote_type == 'sftp' ) {
    // Get the name
    if (!isset($_POST['name'])) exit('[]');
    $sftp_name = trim(strip_tags(html_entity_decode($_POST['name'], ENT_QUOTES)));
    // Get the Host
    if (!isset($_POST['host'])) exit('[]');
    $sftp_host = trim(strip_tags(html_entity_decode($_POST['host'], ENT_QUOTES)));
    // Get the Port
    if (!isset($_POST['port']) || !is_numeric($_POST['port'])) exit('[]');
    $sftp_port = trim(strip_tags(html_entity_decode($_POST['port'], ENT_QUOTES)));
    // Get the Username
    if (!isset($_POST['uname'])) exit('[]');
    $sftp_uname = trim(strip_tags(html_entity_decode($_POST['uname'], ENT_QUOTES)));
    // Get the Auth
    if (!isset($_POST['auth']) || !is_numeric($_POST['auth'])) exit('[]');
    $sftp_auth = trim(strip_tags(html_entity_decode($_POST['auth'], ENT_QUOTES)));
    $sftp_pwd = "";
    $sftp_key = "";
    if ($sftp_auth == 1) {
        // Get the pwd
        if (!isset($_POST['pwd'])) exit('[]');
        $sftp_pwd = trim(strip_tags(html_entity_decode($_POST['pwd'], ENT_QUOTES)));
        $sftp_pwd = $module->encrypt_config_string($sftp_pwd, $Proj->project_id);
    } elseif ($sftp_auth == 2) {
        // Get the key
        if (!isset($_POST['key'])) exit('[]');
        $sftp_key = trim(strip_tags(html_entity_decode($_POST['key'], ENT_QUOTES)));
        $sftp_key = $module->encrypt_config_string($sftp_key, $Proj->project_id);
    } else {
        exit('[]'); // we don't have options other than these
    }

    // Get the list of active configs
    $target_sites = $module->getProjectSetting('sftp-sites');
    $target_types = $module->getProjectSetting('remote-site-type');
    $target_names = $module->getProjectSetting('sftp-site-name');
    $target_hosts = $module->getProjectSetting('sftp-site-host');
    $target_ports = $module->getProjectSetting('sftp-site-port');
    $target_users = $module->getProjectSetting('sftp-site-user');
    $target_pwds = $module->getProjectSetting('sftp-site-pwd');
    $target_pkis = $module->getProjectSetting('sftp-site-pk');
    $target_auth = $module->getProjectSetting('sftp-site-auth-method'); // 1=PWD, 2=Key

    $target_key = 0;
    foreach ($target_sites as $k => $site_enabled) {
        if ($site_enabled) {
            $target_key = $k + 1;
        }
    }

    $target_sites[$target_key] = true;
    $module->setProjectSetting('sftp-sites', $target_sites);
    $target_types[$target_key] = $remote_type;
    $module->setProjectSetting('remote-site-type', $target_types);
    $target_names[$target_key] = $sftp_name;
    $module->setProjectSetting('sftp-site-name', $target_names);
    $target_hosts[$target_key] = $sftp_host;
    $module->setProjectSetting('sftp-site-host', $target_hosts);
    $target_ports[$target_key] = $sftp_port;
    $module->setProjectSetting('sftp-site-port', $target_ports);
    $target_users[$target_key] = $sftp_uname;
    $module->setProjectSetting('sftp-site-user', $target_users);
    $target_auth[$target_key] = $sftp_auth;
    $module->setProjectSetting('sftp-site-auth-method', $target_auth);
    if ($sftp_auth == 1) {
        $target_pwds[$target_key] = $sftp_pwd;
        $module->setProjectSetting('sftp-site-pwd', $target_pwds);
    } elseif ($sftp_auth == 2) {
        $target_pkis[$target_key] = $sftp_key;
        $module->setProjectSetting('sftp-site-pk', $target_pkis);
    } else {
        exit('[]'); // we don't have this option - we should never get here - this is captured above
    }

    $result = [
        'status' => 'OK',
        'status_message' => 'Configuration Added',
    ];
    print json_encode($result);
    exit();
}
if ( $remote_type == 's3') {
    // Get the name
    if (!isset($_POST['name'])) exit('[]');
    $s3_name = trim(strip_tags(html_entity_decode($_POST['name'], ENT_QUOTES)));
    // Get the Bucket
    if (!isset($_POST['bucket'])) exit('[]');
    $s3_bucket = trim(strip_tags(html_entity_decode($_POST['bucket'], ENT_QUOTES)));
    // Get the Username
    if (!isset($_POST['region'])) exit('[]');
    $s3_region = trim(strip_tags(html_entity_decode($_POST['region'], ENT_QUOTES)));
    // Get the pwd (the S3 Secret)
    if (!isset($_POST['pwd'])) exit('[]');
    $s3_pwd = trim(strip_tags(html_entity_decode($_POST['pwd'], ENT_QUOTES)));
    $s3_pwd = $module->encrypt_config_string($s3_pwd, $Proj->project_id);
    // Get the key (the S3 Key)
    if (!isset($_POST['key'])) exit('[]');
    $s3_key = trim(strip_tags(html_entity_decode($_POST['key'], ENT_QUOTES)));
    $s3_key = $module->encrypt_config_string($s3_key, $Proj->project_id);

    // Get the list of active configs
    $target_sites = $module->getProjectSetting('sftp-sites');
    $target_types = $module->getProjectSetting('remote-site-type');
    $target_names = $module->getProjectSetting('sftp-site-name');
    $target_s3_buckets = $module->getProjectSetting('s3-bucket-name');
    $target_s3_regions = $module->getProjectSetting('s3-region-name');
    $target_pwds = $module->getProjectSetting('sftp-site-pwd');
    $target_pkis = $module->getProjectSetting('sftp-site-pk');

    // Find the next available config that we can add to
    $target_key = 0;
    foreach ($target_sites as $k => $site_enabled) {
        if ($site_enabled) {
            $target_key = $k + 1;
        }
    }

    $target_sites[$target_key] = true;
    $module->setProjectSetting('sftp-sites', $target_sites);
    $target_types[$target_key] = $remote_type;
    $module->setProjectSetting('remote-site-type', $target_types);
    $target_names[$target_key] = $s3_name;
    $module->setProjectSetting('sftp-site-name', $target_names);
    $target_s3_buckets[$target_key] = $s3_bucket;
    $module->setProjectSetting('s3-bucket-name', $target_s3_buckets);
    $target_s3_regions[$target_key] = $s3_region;
    $module->setProjectSetting('s3-region-name', $target_s3_regions);
    $target_pwds[$target_key] = $s3_pwd;
    $module->setProjectSetting('sftp-site-pwd', $target_pwds); // S3 Secret
    $target_pkis[$target_key] = $s3_key;
    $module->setProjectSetting('sftp-site-pk', $target_pkis); // S3 Key

    $result = [
        'status' => 'OK',
        'status_message' => 'Configuration Added',
    ];
    print json_encode($result);
    exit();
}
if ( $remote_type == 'local' ) {
    // Get the name
    if (!isset($_POST['name'])) exit('[]');
    $local_storage_name = trim(strip_tags(html_entity_decode($_POST['name'], ENT_QUOTES)));
    // Get the path
    if (!isset($_POST['path'])) exit('[]');
    $local_path = trim(strip_tags(html_entity_decode($_POST['path'], ENT_QUOTES)));

    if ( !is_dir($local_path) ) {
        $result = [
            'status' => 'ERROR',
            'status_message' => 'Invalid Path!',
        ];
        print json_encode($result);
        exit();
    }

    //@todo check to see if this path is outside of the webroot

    // Get the list of active configs
    $target_sites = $module->getProjectSetting('sftp-sites');
    $target_types = $module->getProjectSetting('remote-site-type');
    $target_names = $module->getProjectSetting('sftp-site-name');
    $target_paths = $module->getProjectSetting('sftp-site-folder');

    // Find the next available config that we can add to
    $target_key = 0;
    foreach ($target_sites as $k => $site_enabled) {
        if ($site_enabled) {
            $target_key = $k + 1;
        }
    }

    $target_sites[$target_key] = true;
    $module->setProjectSetting('sftp-sites', $target_sites);
    $target_types[$target_key] = $remote_type;
    $module->setProjectSetting('remote-site-type', $target_types);
    $target_names[$target_key] = $local_storage_name;
    $module->setProjectSetting('sftp-site-name', $target_names);
    $target_paths[$target_key] = $local_path;
    $module->setProjectSetting('sftp-site-folder', $target_paths);

    $result = [
        'status' => 'OK',
        'status_message' => 'Configuration Added',
    ];
    print json_encode($result);
    exit();
}

exit('[]'); // Default is don't do anything