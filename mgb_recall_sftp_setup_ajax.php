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

// Get the module config and format it in the following way:
$final_data = array();
$final_data["data"] = array();

$target_sites       = $module->getProjectSetting('sftp-sites');
$target_types       = $module->getProjectSetting('remote-site-type');
$target_names       = $module->getProjectSetting('sftp-site-name');
$target_hosts       = $module->getProjectSetting('sftp-site-host');
$target_ports       = $module->getProjectSetting('sftp-site-port');
$target_users       = $module->getProjectSetting('sftp-site-user');
$target_auth        = $module->getProjectSetting('sftp-site-auth-method'); // 1=PWD, 2=Key
$target_paths       = $module->getProjectSetting('sftp-site-folder');

// S3 stuff
$target_buckets     = $module->getProjectSetting('s3-bucket-name');
$target_regions     = $module->getProjectSetting('s3-region-name');

global $Proj;

foreach ( $target_sites as $k => $site_enabled ) {
    if ( $site_enabled ) {
        if ( $target_types[$k] == 's3') {
            $final_data["data"][] = array(
                $target_names[$k], // SFTP Name
                $target_buckets[$k], // Bucket Name
                $target_regions[$k], // Bucket Region
                "", // SFTP Username
                "Secret",   // Authentication Method
                "<button title='Delete This Config' id='del_sftp_cfg_" . $k . "' onclick='delete_sft_config(" . $k . ")' class='btn btn-secondary' style='background-color: orange; color: black; font-weight: bold;'>X</button>"
            );
        }
        elseif ( $target_types[$k] == 'local' ) {
            $final_data["data"][] = array(
                $target_names[$k], // SFTP Name
                $target_paths[$k], // Path Location
                "", // No Port
                "", // No Username
                "", // No Authentication
                "<button title='Delete This Config' id='del_sftp_cfg_" . $k . "' onclick='delete_sft_config(" . $k . ")' class='btn btn-secondary' style='background-color: orange; color: black; font-weight: bold;'>X</button>"
            );
        }
        else {
            // SFTP is our default
            $remote_location = "";
            if ( isset($target_paths[$k]) && strlen($target_paths[$k])>0 ) {
                $remote_location = $target_paths[$k];
                // check to see if we have a leading or trailing slash. We should have a leading and trailing (/remote/path/)
                if (substr($remote_location, 0, 1) != "/") $remote_location = "/".$remote_location;
                if (substr($remote_location, -1) != "/") $remote_location = $remote_location."/";
            }

            $final_data["data"][] = array(
                $target_names[$k], // SFTP Name
                $target_hosts[$k] ."". (strlen($remote_location)>2 ? "</br>Path: ". $remote_location: ""), // SFTP Host
                $target_ports[$k], // SFTP Port
                $target_users[$k], // SFTP Username

                $target_auth[$k] == 1 ? "Password" : "Public / Private Key",   // Authentication Method
                "<button title='Specify Remote Folder Location' id='sftp_folder_cfg_" . $k . "' onclick='select_sftp_remote_location(" . $k . ")' class='btn btn-primary' style='background-color: lightgray; color: black; font-weight: bold;'>Set Target Folder</button>&nbsp;&nbsp;" .
                "<button title='Delete This Config' id='del_sftp_cfg_" . $k . "' onclick='delete_sft_config(" . $k . ")' class='btn btn-secondary' style='background-color: orange; color: black; font-weight: bold;'>X</button>"
            );
        }
    }
}

print json_encode($final_data);
exit();
