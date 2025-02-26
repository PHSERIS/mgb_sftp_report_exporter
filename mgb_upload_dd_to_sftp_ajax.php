<?php
namespace MGB\MGBSFTPReportExporter;
/**
 * Upload the data dictionary to the remote SFTP
 */
use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;
use DataExport;
use Logging;
use MetaData;


if (is_null($module)) { exit(); }
if ( strpos(get_class($module),"MGBSFTPReportExporter") == false ) { exit(); }

// SFTP Config ID
if ( !isset($_GET['cfg']) || !is_numeric($_GET['cfg']) ) exit('[]');
$cfg = trim(strip_tags(html_entity_decode($_GET['cfg'], ENT_QUOTES)));

//header("Content-Type: application/json");

global $Proj;

// Get the data dictionary contents
$dd_contents_csv = MetaData::getDataDictionary('csv', true);

if ( strlen($dd_contents_csv)<1 ) {
    $result = [
        'status' => 'ERROR',
        'status_message' => 'Could not retrieve Data Dictionary!',
    ];
    print json_encode($result); // IF NOT VALID - EXIT
    exit();
}

// Check to see if we need to include init_functions.php
if ( !function_exists('addBOMtoUTF8') )
    require_once APP_PATH_DOCROOT."Config/init_functions.php";

// The below does not seem to work on all platforms
//$dd_contents_csv = iconv("CP1257","UTF-8", $dd_contents_csv);
// Add BOM to file if using UTF-8 encoding
$dd_contents_csv = addBOMtoUTF8($dd_contents_csv);
$today = date('Y_m_d_h_i');
$rand_str = $module->get_random_string(4); // generate a random string for uniqueness
$dd_project_identifier = substr(str_replace(" ", "", ucwords(preg_replace("/[^a-zA-Z0-9 ]/", "", html_entity_decode($Proj->project['app_title'], ENT_QUOTES)))), 0, 20);
//$tmp_file_short = "Data_Dictionary_".$Proj->project['project_name']."_".$rand_str."_".$today.".csv";
$tmp_file_short = "Data_Dictionary_".$dd_project_identifier."_".$rand_str."_".$today.".csv";
$tmp_file = APP_PATH_TEMP.$tmp_file_short;

file_put_contents($tmp_file,$dd_contents_csv);

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

$upload_resposne = [
    'status' => 'ERROR',
    'status_message' => "Something went wrong! Please try again!",
];; // Initialize

// Check if S3
if ( $target_remote_types[$cfg] && $target_remote_types[$cfg] == 's3' ) {
    $config = array(
        'report_name'       => "Data Dictionary",
        'conf_name'         => $target_names[$cfg],
        'aws_s3_bucket'     => $target_buckets[$cfg],
        'aws_s3_region'     => $target_regions[$cfg],
        'aws_s3_key'        => (isset($target_pwds[$cfg]) && strlen($target_pwds[$cfg])>0) ? $target_pwds[$cfg] : "",
        'aws_s3_secret'     => (isset($target_pkis[$cfg]) && strlen($target_pkis[$cfg])>0) ? $target_pkis[$cfg] : "",
    );

    // Upload to the location
    $upload_resposne = $module->upload_file_to_s3_bucket($tmp_file_short,$tmp_file,$config);

    // remove the file
    unlink($tmp_file); // Clean-up
}
elseif ( $target_remote_types[$cfg] && $target_remote_types[$cfg] == 'local' ){

}
else {
    $remote_location = "";
    if ( isset($target_paths[$cfg]) && strlen($target_paths[$cfg])>0 ) {
        $remote_location = $target_paths[$cfg];
        // check to see if we have a leading or trailing slash. We should have a trailing and no leading apparently (remote/path/)
        //if (substr($remote_location, 0, 1) != "/") $remote_location = "/".$remote_location;
        if (substr($remote_location, -1) != "/") $remote_location = $remote_location."/";
    }

    $config = array(
        'report_name'   => "Data Dictionary",
        'conf_name'     => $target_names[$cfg],
        'auth_method'   => $target_auth[$cfg] == 1 ? 'basic' : 'key',
        'host'          => $target_hosts[$cfg],
        'port'          => $target_ports[$cfg],
        'user'          => $target_users[$cfg],
        'pwd'           => (isset($target_pwds[$cfg]) && strlen($target_pwds[$cfg]) > 0) ? $target_pwds[$cfg] : "",
        // This is the private key!!!
        'key'           => (isset($target_pkis[$cfg]) && strlen($target_pkis[$cfg]) > 0) ? $target_pkis[$cfg] : "",
        'remote_path'   => $remote_location
    );

    if ($config['auth_method'] == 'key' && strlen(trim($config['key'])) <= 0) {
        $result = [
            'status' => 'ERROR',
            'status_message' => 'Invalid or missing authentication key! Check the module config!',
        ];

        Logging::logEvent(NULL, "", "OTHER", "", "SFTP Report Exporter - ERROR - Invalid or missing authentication key for " . $target_names[$cfg], "SFTP Report Exporter", "", "", "", true, null, null, false);

        print json_encode($result);
        exit();
    }

    if ($config['auth_method'] == 'basic' && strlen(trim($config['pwd'])) <= 0) {
        $result = [
            'status' => 'ERROR',
            'status_message' => 'Invalid or missing user credentials! Check the module config!',
        ];

        Logging::logEvent(NULL, "", "OTHER", "", "SFTP Report Exporter - ERROR - Invalid or missing user credentials for " . $target_names[$cfg], "SFTP Report Exporter", "", "", "", true, null, null, false);

        print json_encode($result);
        exit();
    }


    // Upload to the location
    $upload_resposne = $module->upload_file_to_sftp($tmp_file_short, $tmp_file, $config);

    // remove the file
    unlink($tmp_file); // Clean-up
}

print json_encode($upload_resposne);
exit();
