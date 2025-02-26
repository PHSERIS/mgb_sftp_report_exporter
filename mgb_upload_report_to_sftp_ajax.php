<?php
namespace MGB\MGBSFTPReportExporter;

/**
 * Export a report to SFTP
 */

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use phpseclib3\Crypt\PublicKeyLoader;
use REDCap;
use DataExport;
use Logging;


if (is_null($module)) { exit(); }
if ( strpos(get_class($module),"MGBSFTPReportExporter") == false ) { exit(); }

// Report ID
if ( !isset($_GET['report_id']) || !is_numeric($_GET['report_id']) ) exit('[]');
$report_id = trim(strip_tags(html_entity_decode($_GET['report_id'], ENT_QUOTES)));
if ( $report_id == 0 )
    $report_id='ALL';

// SFTP Config ID
if ( !isset($_GET['cfg']) || !is_numeric($_GET['cfg']) ) exit('[]');
$cfg = trim(strip_tags(html_entity_decode($_GET['cfg'], ENT_QUOTES)));

// Defaults
$report_format = "csv";
$report_type = "flat";

// Export Format
/**
 * As of version 2.5.0 this is part of the report settings
 */
/**if ( !isset($_GET['report_format']) ) exit('[]');
$report_requested_format = trim(strip_tags(html_entity_decode($_GET['report_format'], ENT_QUOTES)));
$report_format = "csv";

if ( !array_key_exists($report_requested_format, $module->getExportFormats()) ) {
    $result = [
        'status' => 'ERROR',
        'status_message' => 'Invalid Report Format Requested!',
    ];
    print json_encode($result);
    exit();
} else {
    foreach ( $module->getExportFormats() as $rf => $rl ) {
        if ( $rf == $report_requested_format ) {
            $report_format = $rf; // This is subtle, but it's more secure to use this than user input
            break;
        }
    }
}
*/

// Export type
 /**
 * As of version 2.5.0 this is part of the report settings
 */
/**
if ( !isset($_GET['report_type']) ) exit('[]');
$report_requested_type = trim(strip_tags(html_entity_decode($_GET['report_type'], ENT_QUOTES)));
$report_type = "flat";
if ( !array_key_exists($report_requested_type, $module->getExportTypes()) ) {
    $result = [
        'status' => 'ERROR',
        'status_message' => 'Invalid Report Type Requested!',
    ];
    print json_encode($result);
    exit();
} else {
    foreach ( $module->getExportTypes() as $rt => $rl ) {
        if ( $rt == $report_requested_type ) {
            $report_type = $rt; // This is subtle, but it's more secure to use this than user input
            break;
        }
    }
}*/

global $Proj;


// Check to see if we need to include init_functions.php
if ( !function_exists('addBOMtoUTF8') )
    require_once APP_PATH_DOCROOT."Config/init_functions.php";

if ( $report_id == 'ALL') {
    $report_name = "ALL DATA";
    $report_file_identifier = "ALL_DATA";
    $report_valid = true;
}
else {
// Get a list of the reports that this user/project has access to and make sure the provided ID is one of them
    $reports = DataExport::getReportNames(null, $applyUserAccess = true); // TODO WHAT USER (IF Cron)
    $report_name = "";
    $report_file_identifier = "";
    $report_valid = false;
    foreach ($reports as $rep) {
        if ($rep['report_id'] == $report_id) {
            $report_name = $rep['title'];
            $report_file_identifier = substr(str_replace(" ", "", ucwords(preg_replace("/[^a-zA-Z0-9 ]/", "", html_entity_decode($report_name, ENT_QUOTES)))), 0, 20);
            $report_valid = true;
            break;
        }
    }
    if (!$report_valid) {
        $result = [
            'status' => 'ERROR',
            'status_message' => 'Invalid Report OR you do not have permission to access this report!',
        ];
        print json_encode($result); // IF NOT VALID - EXIT
        exit();
    }
}

// Version 2.5.0 updates
// Pull the configuration for this report IF one exists
$all_report_configurations = $module->getProjectSetting('project_report_configurations');
$found_predefined_config = false;
$report_filename = "";
if (is_array($all_report_configurations)) {
    $rpid = -1;
    if ( $report_id == 'ALL' ) $rpid = 0;
    else $rpid = $report_id;
    if (array_key_exists("EXPORTCFG_" . $rpid, $all_report_configurations)) {
        // Found the config
        $rep_cfg = $all_report_configurations['EXPORTCFG_' . $rpid];

        $report_type = isset($rep_cfg['report_type']) ? trim(strip_tags(html_entity_decode($rep_cfg['report_type'], ENT_QUOTES))) : 'flat'; // default is flat
        $report_format = isset($rep_cfg['report_format']) ? trim(strip_tags(html_entity_decode($rep_cfg['report_format'], ENT_QUOTES))) : 'csvraw'; // default is csv
        $report_filename = $module->get_report_filename($rpid, $module, $report_format);
        if ( strlen($report_filename) > 4 )
            $found_predefined_config = true; // the config seems valid and we have a valid filename that was returned (4 characters or longer)
    }
    else {
        // Assume defaults - no configuration exists - do nothing
    }
}
else {
    // No configuration is setup - assume the defaults - do nothing
}


// If we get to here the report is valid
// Get the report in CSV format
//REDCap::getReport ( int $report_id [, string $outputFormat = 'array' [, bool $exportAsLabels = FALSE [, bool $exportCsvHeadersAsLabels = FALSE ]]] )
// 'array', 'csv', 'json', and 'xml'
//$csv_data = REDCap::getReport ( $report_id, 'csv' ); // ORIGINAL
if ( $report_type == "eav") {
    // EAV
    $fout = "csv";
    if ( $report_format == 'csv' || $report_format == 'csvraw' || $report_format == 'csvlabels' ) {
        $fout = "csv";
    }
    elseif ( $report_format == 'odm' || $report_format == 'odmraw' || $report_format == 'odmlabels' ) {
        $fout = "xml";
    }
    elseif ( $report_format == "json" || $report_format == 'jsonraw' || $report_format == 'jsonlabels' ) {
        $fout = "json";
    }
    //$csv_data = REDCap::getReport ( $report_id, 'array' ); // Get it as array - technically the best, but you CAN'T DO LABELS
    //$csv_data = $module::reformat_data_as_EAV( $csv_data, $fout ); // This is if we want to use ARRAY

    if ( $report_id == 'ALL') {
        $csv_data = $module->get_all_data_report_for_sftp('json', (strpos($report_format, "labels") === false ? false : true));
        $csv_data = $module->reformat_json_as_EAV($csv_data, $fout);
    }
    else {
        $csv_data = REDCap::getReport($report_id, 'json', (strpos($report_format, "labels") === false ? false : true));
        $csv_data = $module->reformat_json_as_EAV($csv_data, $fout);
    }
}
else {
    // Flat
    $fout = "csv";
    if ( $report_format == 'csv' || $report_format == 'csvraw' || $report_format == 'csvlabels' ) {
        $fout = "csv";
    }
    elseif ( $report_format == 'odm' || $report_format == 'odmraw') {
        $fout = "odm";
    }
    elseif ( $report_format == 'odmlabels' ) {
        $fout = "xml";
    }
    elseif ( $report_format == "json" || $report_format == 'jsonraw' || $report_format == 'jsonlabels' ) {
        $fout = "json";
    }

    if ( $report_id == 'ALL' ) {
        $csv_data = $module->get_all_data_report_for_sftp($fout, (strpos($report_format, "labels") === false ? false : true));
    }
    else {
        $csv_data = REDCap::getReport($report_id, $fout, (strpos($report_format, "labels") === false ? false : true));
    }
}

$today = date('Y_m_d_h_i');
$rand_str = $module->get_random_string(4); // generate a random string for uniqueness
if ( $report_format == 'csv' || $report_format == 'csvraw' || $report_format == 'csvlabels' ) {
    // The below does not seem to work on all platforms
    //$csv_data = iconv("CP1257","UTF-8", $csv_data);
    $csv_data = addBOMtoUTF8($csv_data);
    //$tmp_file_short = "Report_Export_".$Proj->project_id."_".$rand_str."_".$today.".csv";
    $tmp_file_short = $found_predefined_config ?
            $report_filename
            :
            "Report_".$report_file_identifier."_".$Proj->project_id."_".$rand_str."_".$today.".csv";
}
elseif ( $report_format == 'odm' || $report_format == 'odmraw' || $report_format == 'odmlabels' ) {
    //$tmp_file_short = "Report_Export_".$Proj->project_id."_".$rand_str."_".$today.".xml";
    $tmp_file_short = $found_predefined_config ?
            $report_filename
            :
            "Report_".$report_file_identifier."_".$Proj->project_id."_".$rand_str."_".$today.".xml";
}
elseif ( $report_format == "json" || $report_format == 'jsonraw' || $report_format == 'jsonlabels' ) {
    //$tmp_file_short = "Report_Export_".$Proj->project_id."_".$rand_str."_".$today.".json";
    $tmp_file_short = $found_predefined_config ?
        $report_filename
        :
        "Report_".$report_file_identifier."_".$Proj->project_id."_".$rand_str."_".$today.".json";
}

$tmp_file = APP_PATH_TEMP.$tmp_file_short;

file_put_contents($tmp_file,$csv_data);


// Upload the file to the designated config location
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

if ( !isset($target_sites[$cfg]) ) {
    $result = [
        'status' => 'ERROR',
        'status_message' => 'SFTP Configuration is invalid!',
    ];

    Logging::logEvent(NULL, "", "OTHER", "", "SFTP Report Exporter - ERROR - Invalid Configuration!", "SFTP Report Exporter", "", "", "", true, null, null, false);

    print json_encode($result);
    exit();
}
if ( $target_sites[$cfg] != true ) {
    $result = [
        'status' => 'ERROR',
        'status_message' => 'SFTP Configuration is invalid!',
    ];

    Logging::logEvent(NULL, "", "OTHER", "", "SFTP Report Exporter - ERROR - Invalid Configuration!", "SFTP Report Exporter", "", "", "", true, null, null, false);

    print json_encode($result);
    exit();
}

//$upload_resposne = [

$upload_response = [
    'status' => 'ERROR',
    'status_message' => "Something went wrong! Please try again!",
]; // Initialize

// Check if S3
if ( $target_remote_types[$cfg] && $target_remote_types[$cfg] == 's3' ) {
    $config = array(
        'report_name'   => $report_name,
        'conf_name'     => $target_names[$cfg],
        'aws_s3_bucket'     => $target_buckets[$cfg],
        'aws_s3_region'     => $target_regions[$cfg],
        'aws_s3_key'        => (isset($target_pwds[$cfg]) && strlen($target_pwds[$cfg])>0) ? $target_pwds[$cfg] : "",
        'aws_s3_secret'     => (isset($target_pkis[$cfg]) && strlen($target_pkis[$cfg])>0) ? $target_pkis[$cfg] : "",
    );

    // Upload to the location
    $upload_response = $module->upload_file_to_s3_bucket($tmp_file_short,$tmp_file,$config);

    // remove the file
    unlink($tmp_file); // Clean-up

}
elseif ( $target_remote_types[$cfg] && $target_remote_types[$cfg] == 'local' ){
    // Local storage
    $local_path = $target_paths[$cfg];
    if ( substr($local_path, -1) !== DIRECTORY_SEPARATOR )
        $local_path = $local_path.DIRECTORY_SEPARATOR;
    rename($tmp_file, $local_path.$tmp_file_short);
    unlink($tmp_file); // Just in case remove it from the temp folder

    $upload_response = [
        'status' => 'OK',
        'status_message' => "File Stored!",
    ];
}
else {
    // default is SFTP
    $remote_location = "";
    if ( isset($target_paths[$cfg]) && strlen($target_paths[$cfg])>0 ) {
        $remote_location = $target_paths[$cfg];
        // check to see if we have a leading or trailing slash. We should have a trailing and no leading apparently (remote/path/)
        //if (substr($remote_location, 0, 1) != "/") $remote_location = "/".$remote_location;
        // REMOVED - using chdir  -- if (substr($remote_location, -1) != "/") $remote_location = $remote_location."/";
    }

    $config = array(
        'report_name'   => $report_name,
        'conf_name'     => $target_names[$cfg],
        'auth_method'   => $target_auth[$cfg] == 1 ? 'basic' : 'key',
        'host'          => $target_hosts[$cfg],
        'port'          => $target_ports[$cfg],
        'user'          => $target_users[$cfg],
        'pwd'           => (isset($target_pwds[$cfg]) && strlen($target_pwds[$cfg])>0) ? $target_pwds[$cfg] : "",
        // This is the private key!!!
        'key'           => (isset($target_pkis[$cfg]) && strlen($target_pkis[$cfg])>0) ? $target_pkis[$cfg] : "",
        'remote_path'   => $remote_location
    );

    if ( $config['auth_method'] == 'key' && strlen(trim($config['key']))<=0 ) {
        $result = [
            'status' => 'ERROR',
            'status_message' => 'Invalid or missing authentication key! Check the module config!',
        ];

        Logging::logEvent(NULL, "", "OTHER", "", "SFTP Report Exporter - ERROR - Invalid or missing authentication key for ".$target_names[$cfg],"SFTP Report Exporter", "", "", "", true, null, null, false);

        print json_encode($result);
        exit();
    }

    if ( $config['auth_method'] == 'basic' && strlen(trim($config['pwd']))<=0 ) {
        $result = [
            'status' => 'ERROR',
            'status_message' => 'Invalid or missing user credentials! Check the module config!',
        ];

        Logging::logEvent(NULL, "", "OTHER", "", "SFTP Report Exporter - ERROR - Invalid or missing user credentials for ".$target_names[$cfg], "SFTP Report Exporter", "", "", "", true, null, null, false);

        print json_encode($result);
        exit();
    }

    // Upload to the location
    $upload_response = $module->upload_file_to_sftp($tmp_file_short,$tmp_file,$config);

    // remove the file
    unlink($tmp_file); // Clean-up
}

print json_encode($upload_response);
exit();
