<?php
namespace MGB\MGBSFTPReportExporter;
/**
 * Run cron for this project right now
 * In essence, trigger the cron jobs secheduled for this project
 * This is going to be called either from a single project OR as NOAUTH from the cron process itself
 */

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use REDCap;
use Logging;
use DataExport;
use DateTime;
use DateTimeZone;

//$nl = "\n\r";
$nl = ' | ';

if (is_null($module)) { exit(); }
if ( strpos(get_class($module),"MGBSFTPReportExporter") == false ) { exit(); }

// Get the PID
if ( !isset($_GET['pid']) || !is_numeric($_GET['pid']) ) exit();

$pid = trim(strip_tags(html_entity_decode($_GET['pid'], ENT_QUOTES)));

if ( !is_numeric($pid) ) {
    exit(); // Second check We need a PID that is numeric
}

if ( isset($_GET['ui']) && is_numeric( trim(strip_tags(html_entity_decode($_GET['ui'], ENT_QUOTES))) ) ) {
    // include the header
    require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
}

$force_run = false;

if ( isset($_GET['force']) && is_numeric($_GET['force']) ) {
    $fr = trim(strip_tags(html_entity_decode($_GET['force'], ENT_QUOTES)));
    if ( $fr == 1 )
        $force_run = true;
}

global $Proj;

if ( isset($Proj) && isset($Proj->project_id) && is_numeric($Proj->project_id) ) {
    // This is a UI run
}
else {
    // This is a cron run
    $Proj = new Project($pid); // Initialize the PID object
}

$framework = ExternalModules::getFrameworkInstance($module->PREFIX);

// Check to see if the module is enabled on this project
$projects_with_em_enabled = $framework->getProjectsWithModuleEnabled();

if ( !in_array($Proj->project_id, $projects_with_em_enabled) ) {
    exit(1); // Module is not enabled on this project
}

// TODO: add a granular level to debug logs.  essential (upload complete), (hourly runs), stats (start stop duration), filesize, verbose (everything we log)
//   need some listing to spell out the levels and what they cover
// TODO: add configuration setting, either global or maybe project level for the log verbose level

// sftp-cron-debug
$debug_cron = getFlagDebugCron($module);

// debug_test_send
$debug_test_send_flag = getFlagDebugTestSend($module);

if ($debug_cron) { // Show the flag value detail
    $msg = '';
    $msg .= 'debug_test_send_flag Flag: (' . ($debug_test_send_flag === false ? 'FALSE [' . $debug_test_send_flag . ']' : 'TRUE [' . $debug_test_send_flag . ']') . ')';
    $module->log($msg);
}

$dateProcessTimeStart = null;
$dateProcessTimeFinish = null;

$dateProcessTimeStart = getProcessNowTime();

// ***** LOG Start of this page activity *****
if ($debug_cron) {
    $module->log('mgb_sftp_cron marker BEGIN: ' . getStrNowTime($dateProcessTimeStart));
}

$target_sites = $module->getProjectSetting('sftp-site-name');
$reports = DataExport::getReportNames(null, false); // do not apply user permissions
$report_names = array();

foreach ($reports as $rep ) {
    $report_names[$rep['report_id']] = $rep['title'];
}

unset ($reports);

$final_status_message = array();
$final_status = "Completed - OK";
$found_active_reports = false;

// If we get here then the module is enabled on this project
// Loop through any specified cron jobs
$schedules = $module->getProjectSetting('project_report_schedules');
if ( isset($schedules) && is_array($schedules) ) {
    foreach ( $schedules as $cr => $cr_settings ) {
        /**
            'ConfigID_ReportID' = [
                'repeat_type'            => -1,
                'repeat_day_daily'       => -1,
                'repeat_day_week'        => -1,
                'repeat_day_month'       => -1,
                'use_predefined_configs' => 1 | 0,
                'report_type'            => $selected_type,
                'report_format'          => $selected_format,
                'report_last_run'        => 'date time yyyy-mm-dd hh:mm',
                'report_last_run_status' => 'OK | ERROR'
            ];
        */
        
        $parts = explode("_",$cr);
        $config_id = $parts[0];
        $report_id = $parts[1];
        $report_requested_type = $cr_settings['report_type'];
        $report_requested_format = $cr_settings['report_format'];
        $use_predefined_configs = isset($cr_settings['use_predefined_configs']) ? $cr_settings['use_predefined_configs'] : 0;

        $run_and_deliver_report = false;

        if ($debug_cron) {
            $module->log('SFTP CRON Looking at Report ID '. $report_id .' for PID: '.$pid);
        }

        // Check if this cron is active
        if ( isset($cr_settings['report_active']) && $cr_settings['report_active'] == 1 ) {
            $found_active_reports = true;

            if ($debug_cron) {
                $module->log('SFTP CRON Report ID '. $report_id .' is active for PID: '.$pid);
            }

            // Report is active
            $repeat_type = $cr_settings['repeat_type'];
            $scheduled_run_hour = ((isset($cr_settings['report_run_hour']) && !is_null($cr_settings['report_run_hour'])) ? $cr_settings['report_run_hour'] : -1);
            $last_run = (isset($cr_settings['report_last_run']) && strlen($cr_settings['report_last_run'])>0) ? $cr_settings['report_last_run'] : "1990-01-01 00:00";
            $last_run = DateTime::createFromFormat('Y-m-d H:i', $last_run, new DateTimeZone("America/New_York"));
            $now = new DateTime();
            $now->setTimezone(new DateTimeZone("America/New_York"));
            $current_hour = $now->format("H");
            $time_difference = $now->diff($last_run, true);
            $minutes_since_last_run = $time_difference->days * 24 * 60;
            $minutes_since_last_run += $time_difference->h * 60;
            $minutes_since_last_run += $time_difference->i;
            
            $nowLastRunStampStr = ''; // clear stamp.  used for daily hourly time stamp

            if ($debug_cron) {
                $module->log('SFTP CRON Report ID '. $report_id .$nl .' Repeat Type: '. $repeat_type .$nl .'Last Run: '. $last_run->format('Y-m-d H:i') .$nl .' Minutes since last run: '. $minutes_since_last_run .$nl .' Scheduled hour to run: '. $scheduled_run_hour .$nl .' for PID: '.$pid);
            }

            if ( $repeat_type == 'daily' ) {
                $daily_repeat = ((isset($cr_settings['repeat_day_daily']) && is_numeric($cr_settings['repeat_day_daily'])) ? $cr_settings['repeat_day_daily'] : -1);
                if ( $daily_repeat == 1 ) {
                    // Once per day
                    if ( $minutes_since_last_run >= (60*24) )
                        $run_and_deliver_report = true; // it's been more than 24 hours
                }
                elseif ( $daily_repeat == 2 ) {
                    // Every hour
                    
                    // attempt to be more precise in determining we have an "hour" elapsed from last time. 
                    // our granularity of checking is on each hour, however some of the math will have delays that shift the finish time and throw off the calc.
                    // so, we are checking, on the next hour, and if our last run was 51 minutes ago, due to finish time took us some minutes, we dont see time is up, but it is. 
                    $last_run_hour = $last_run->format("H");
                    $diff_hours = $current_hour - $last_run_hour;
                    
                    // handle the midnight boundary math
                    // this really only is a concern when minutes_since_last_run is 59 minutes or less
                    if ($diff_hours == -23) { // 1AM - midnight  ( 0 - 23 )
                        $diff_hours = 1;
                    }
                    if ($diff_hours == -22) { // 2AM - midnight  ( 1 - 23 ) if it had skipped an hour
                        $diff_hours = 2;
                    }
                    
                    $hourlyrunMsg = 'HOURLY RUN ';

                    if ($diff_hours > 0 || ($minutes_since_last_run >= 60) ) {  // NOTE: check if hour has ticked over
                        if ( $minutes_since_last_run >= 50 ) { // every hour: approx 50 to 60 plus minutes
                            // about an hour.  we are close enough to an hour to consider we are up for the task
                            $run_and_deliver_report = true; // it's been more than one hour
                            
                            // capture what we will call the last run time
                            $nowLastRunStamp = new DateTime();
                            $nowLastRunStamp->setTimezone(new DateTimeZone("America/New_York"));
                            $nowLastRunStampStr = $nowLastRunStamp->format("Y-m-d H:i");
                            
                            $hourlyrunMsg .= 'GO: [' . $diff_hours . ']';
                        } else {
                            $hourlyrunMsg .= 'SKIP minutes_since_last_run: [' . $minutes_since_last_run . ']' . ' diff_hours: [' . $diff_hours . ']';
                        }
                    } else {
                        $hourlyrunMsg .= 'SKIP diff_hours: [' . $diff_hours . ']';
                    }

                    $hourlyrunMsg .= $nl;
                    $hourlyrunMsg .= 'PID: ' . $pid;
                    $hourlyrunMsg .= $nl;
                    $hourlyrunMsg .= 'Report ID ' . $report_id;
                    $hourlyrunMsg .= $nl;
                    $hourlyrunMsg .= 'Last Run: ' . $last_run->format('Y-m-d H:i');
                    $hourlyrunMsg .= $nl;
                    $hourlyrunMsg .= 'Minutes since last run: ' . $minutes_since_last_run;
                    $hourlyrunMsg .= $nl;
                    $hourlyrunMsg .= '';
                    
                    if ($debug_cron) {
                        $module->log($hourlyrunMsg);
                    }
                    
                    $hourlyrunMsg = '';
                }
                elseif ( $daily_repeat == 3 ) {
                    // Every 6 hours
                    if ( $minutes_since_last_run >= (60*6) )
                        $run_and_deliver_report = true; // it's been more than six hours
                        if ($debug_cron) {
                            $module->log('REPEAT Daily Every 6 hours: [' . $minutes_since_last_run . ']' . ' PID: ' . $pid . ' Report ID ' . $report_id);
                        }
                }
                elseif ( $daily_repeat == 4 ) {
                    // Every 12 hours
                    if ( $minutes_since_last_run >= (60*12) )
                        $run_and_deliver_report = true; // it's been more than twelve hours
                        if ($debug_cron) {
                            $module->log('REPEAT Daily Every 12 hours: [' . $minutes_since_last_run . ']' . ' PID: ' . $pid . ' Report ID ' . $report_id);
                        }
                }
                else {
                    // UNKNOWN Schedule - can't do anything
                    $run_and_deliver_report = false; // just in case
                    if ($debug_cron) {
                        $module->log('UNKNOWN Schedule daily_repeat: [' . $daily_repeat . ']' . ' PID: ' . $pid . ' Report ID ' . $report_id);
                    }
                }
            }
            elseif ( $repeat_type == 'weekly' ) {
                $today_day_date = new DateTime();
                $today_day_week = $today_day_date->format("N");

                // I didn't think this one through - this is zero-based and the days format is 1-based
                foreach ( $cr_settings['repeat_day_week'] as $day_week => $day_week_selected ) {
                    if ( $day_week_selected == 1 && $today_day_week == ($day_week+1)) {
                        // The day is selected and it's today
                        if ( $minutes_since_last_run >= (60*24) ) {
                            $run_and_deliver_report = true; // we should be running the report - it's scheduled for today and last time it ran was more than 24 hours ago
                            if ($debug_cron) {
                                $module->log('REPEAT Every Week: [' . $minutes_since_last_run . ']' . ' PID: ' . $pid . ' Report ID ' . $report_id);
                            }
                        }
                    }
                }
            }
            elseif ( $repeat_type == 'monthly' ) {
                $today_day_date = new DateTime();
                $today_date = $today_day_date->format("j");

                if ( $today_date == $cr_settings['repeat_day_month'] && $minutes_since_last_run >= (60*24) ) {
                    $run_and_deliver_report = true; // if today is the scheduled day AND the last run is more than 24 hours ago
                    if ($debug_cron) {
                      $module->log('REPEAT Every Month: [' . $minutes_since_last_run . ']' . ' PID: ' . $pid . ' Report ID ' . $report_id);
                    }
                }
            }
            else {
                // Invalid repeat frequency
                $run_and_deliver_report = false;
            }
        }
        else {
            $run_and_deliver_report = false; // report is not active - do not run
        }

        // Check the hour for the run
        if ( $run_and_deliver_report ) {
            if ( $current_hour == $scheduled_run_hour ) {
                $run_and_deliver_report = true;
            }
            elseif ( $scheduled_run_hour == -1 ) {
                $run_and_deliver_report = true; // the hour is not actually configured for this BUT the report criteria for run has been met, so run it
            }
            else {
                $run_and_deliver_report = false;
            }

            if ($debug_cron) {
                $module->log('current_hour: [' . $current_hour . ']' . ' scheduled_run_hour: [' . $scheduled_run_hour . '] Report ID ' . $report_id . ' PID: ' . $pid);
                $module->log('SFTP CRON Report ID '. $report_id .$nl .' Should report run?: '. ($run_and_deliver_report == true ? 'YES' : 'NO') .$nl .' for PID: '.$pid);
            }
        }

        // Check to see if we should force it
        if ( $force_run && isset($cr_settings['report_active']) && $cr_settings['report_active'] == 1 ) {
            // If we're forcing a run AND the report is Active, then run it
            $run_and_deliver_report = true; // FORCE IT!
            
            // (OVERRIDE test setting) if was true (was test mode as in 'do not send file'), 
            // if force run, then bypass the config setting and make it SEND file.
            if ($debug_test_send_flag) {  
                $debug_test_send_flag = false;  
            }
            
            if ($debug_cron) {
                $module->log('SFTP CRON Report ID '. $report_id .$nl .' FORCING CRON Report Run by Admin: '. ($run_and_deliver_report == true ? 'YES' : 'NO') .$nl .' for PID: '.$pid);
            }
        }

        if ( $run_and_deliver_report ) {
            $report_run_result = run_and_deliver_report_to_destination($module, $Proj, $report_id, $config_id, $report_requested_format, $report_requested_type, $use_predefined_configs, $debug_cron, $debug_test_send_flag);
            $log_msg = "Scheduled Report Upload\n".
                "REPORT: ".($report_id == 0 ? "All Data" : $report_names[$report_id])."\n".
                "TO: ".$target_sites[$config_id]."\n".
                "STATUS: ".$report_run_result['status']. ($report_run_result['status'] == "OK" ? "" :  "\nSTATUS Message: ".$report_run_result['status_message']);
            
            if ( $report_run_result['status'] == "ERROR" ) {
                $final_status = "Completed - WITH ERRORS (see log)";
            }
            
            Logging::logEvent(NULL, "", "OTHER", "", "$log_msg", "SFTP Report Exporter CRON", "", "", "", true, null, null, false);
            $final_status_message[] = $log_msg;
            $nowStampTime = new DateTime();
            $nowStampTime->setTimezone(new DateTimeZone("America/New_York"));
            
            // for stamp when process 'last run'
            $cr_settings['report_last_run'] = $nowStampTime->format("Y-m-d H:i");
            if (strlen($nowLastRunStampStr) > 0) { // daily hourly we will use time when we set the flag, as the process times from run_and_deliver_report_to_destination may create too much drift
                $cr_settings['report_last_run'] = $nowLastRunStampStr;
            }
            
            $cr_settings['report_last_run_status'] = $report_run_result['status'];
            $schedules[$cr] = $cr_settings;
            $module->setProjectSetting('project_report_schedules', $schedules); // Save the last run time and status
        }
    }

    print json_encode(
        [
            'status' => $final_status,
            'status_message' => (($found_active_reports==true) ? implode("\n\n",$final_status_message) : "There are no active Scheduled Uploads for this project"),
        ]
    );

    // ***** LOG Finish of this page activity *****
    $dateProcessTimeFinish = processorComplete($module, $debug_cron, $dateProcessTimeStart, null);
        
    exit();
}
else {

    if ($debug_cron) {
        $module->log('SFTP CRON No Scheduled Uploads are available for PID: '.$pid);
    }

    print json_encode(
        [
            'status' => $final_status,
            'status_message' => "No Scheduled Uploads are available for this project",
        ]
    );

    // ***** LOG Finish of this page activity *****
    $dateProcessTimeFinish = processorComplete($module, $debug_cron, $dateProcessTimeStart, null);

    exit();
}

// ***** LOG Finish of this page activity *****
$dateProcessTimeFinish = processorComplete($module, $debug_cron, $dateProcessTimeStart, null);


// ***** ***** ***** *****
// ***** ***** ***** *****
// ***** ***** ***** *****
// ***** ***** ***** *****


/** 
 *
 */
function processorComplete($module, $debug_cron, $startDateTime = null, $endDateTime = null)
{
    if ($endDateTime == null) {
        $endDateTime = getProcessNowTime();
    }
    
    processDurationMsg($module, $debug_cron, $startDateTime, $endDateTime);
    finishMsg($module, $debug_cron, $endDateTime);

    return $endDateTime;
}

/** 
 *
 */
function getStrNowTime($now = null)
{
    if (!$now) {
        $now = getProcessNowTime();
    }
    
    return $now->format("Y-m-d H:i");   
}

/** 
 *
 */
function getProcessNowTime()
{
    $now = new DateTime();
    $now->setTimezone(new DateTimeZone("America/New_York"));
    
    return $now;    
}

/** 
 *
 */
function finishMsg($module, $debug_cron, $now = null)
{
    // ***** LOG Finish of this page activity *****
    if ($debug_cron) {
        $module->log('mgb_sftp_cron marker FINISH: ' . getStrNowTime($now));
    }
}

/** 
 *
 */
function processDurationMsg($module, $debug_cron, $startDateTime = null, $endDateTime = null)
{
    // ***** LOG Finish of this page activity *****
    if ($debug_cron) {
        
        $durationStr = '';
        
        // no start time, you are out.
        if ($startDateTime == null) {
            $module->log('Missing startDateTime');
            return;
        }

        // add an end time if none
        if ($endDateTime == null) {
            $endDateTime = getProcessNowTime();

            // still none, then, out.
            if ($endDateTime == null) {
                $module->log('Missing endDateTime');    
                return;         
            }
        }
        
        // calc duration = end - start
        // $durationStr = math of end minus start added here
        $duration = $startDateTime->diff($endDateTime);
        
        $durationStr = sprintf(
            '%d days, %d hours, %d minutes, %d seconds',
            $duration->d,
            $duration->h,
            $duration->i,
            $duration->s
        );
            
        if ($debug_cron) {
            //                                  start time         end time           duration 
            // mgb_sftp_cron marker DURATION: YYYY-MM-DD HH:mm YYYY-MM-DD HH:mm dd days, dd hours, dd minutes, dd seconds
            //
            $module->log('mgb_sftp_cron marker DURATION: ' . getStrNowTime($startDateTime) . ' ' . getStrNowTime($endDateTime) . ' ' . $durationStr);
        }
    }
}

/** 
 *  getFlagDebugCron - get flag sftp-cron-debug from SYSTEM config
 */
function getFlagDebugCron($module)
{
    $debug_cron = $module->getSystemSetting('sftp-cron-debug');
    
    if (!isset($debug_cron) || is_null($debug_cron) || strlen(trim($debug_cron)) < 1) {
        $module->setSystemSetting('sftp-cron-debug', 0); // Initiate the setting to 0 - debug disabled
    }
    else {
        if ($debug_cron == 1 || $debug_cron == '1') {
            $debug_cron = true;
        } else {
            $debug_cron = false;
        }
    }
    
    return $debug_cron;
}

/** 
 *  getFlagDebugTestSend - get flag debug_test_send from SYSTEM config
 */
function getFlagDebugTestSend($module)
{
    // debug_test_send
    $debug_test_send_flag = false;
    
    $debug_test_send = $module->getSystemSetting('debug_test_send');
    
    if (!isset($debug_test_send) || is_null($debug_test_send) || strlen(trim($debug_test_send)) < 1) {
        $module->setSystemSetting('debug_test_send', 0); // Initiate the setting to 0 - debug disabled
        $msg = 'Setting debug_test_send to ZERO 0 Initializing.';
        $module->log($msg);
    } else {
        if ($debug_test_send == 1 || $debug_test_send == '1') {
            $debug_test_send_flag = true;   
            $msg = 'SFTP WARNING: system will not send files via SFTP only simulate sending.';
            $module->log($msg);
        } else {
            $debug_test_send_flag = false;
        }
    }
    
    return $debug_test_send_flag;
}

/**
 * Function to handle the uplaod
 * @param $module
 * @param $Proj
 * @param $report_id
 * @param $cfg
 * @param $report_requested_format
 * @param $report_requested_type
 * @param $use_predefined_configs
 * @param $debug_cron
 * @param $debug_test_send_flag
 * @return string[]
 */
function run_and_deliver_report_to_destination($module, $Proj, $report_id, $cfg, $report_requested_format, $report_requested_type, $use_predefined_configs, $debug_cron = false, $debug_test_send_flag = false) 
{
    $flagSendFile = true;
    //$nl = "\n\r";
    $nl = ' | ';
   
    if ($debug_test_send_flag) {
        $flagSendFile = false;  // do not do actual file sending.  just simulate sending.
    }
    
    if ( $report_id == 0 ) {
        $report_id='ALL';
    }

    $report_format = 'csv';
    $found_predefined_config = false;
    $report_filename = '';

    if ($debug_cron) {
        $module->log('SFTP CRON Attempting Report Delivery for Report ID '. $report_id .$nl .' for PID: '.$Proj->project_id);
    }

    if ( $use_predefined_configs == 1 ) {
        // Version 2.5.0 updates
        // Pull the configuration for this report IF one exists
        $all_report_configurations = $module->getProjectSetting('project_report_configurations');
        
        if (is_array($all_report_configurations)) {
            $rpid = -1;
            
            if ($report_id == 'ALL') {
                $rpid = 0;
            } else {
                $rpid = $report_id;
            }
            
            if (array_key_exists('EXPORTCFG_' . $rpid, $all_report_configurations)) {
                // Found the config
                $rep_cfg = $all_report_configurations['EXPORTCFG_' . $rpid];

                $report_requested_type = isset($rep_cfg['report_type']) ? trim(strip_tags(html_entity_decode($rep_cfg['report_type'], ENT_QUOTES))) : 'flat'; // default is flat
                $report_requested_format = isset($rep_cfg['report_format']) ? trim(strip_tags(html_entity_decode($rep_cfg['report_format'], ENT_QUOTES))) : 'csvraw'; // default is csv
                $report_filename = $module->get_report_filename($rpid, $module, $report_requested_format);

                if (strlen($report_filename) > 4) {
                    $found_predefined_config = true; // the config seems valid and we have a valid filename that was returned (4 characters or longer)
                }
            } else {
                // Assume defaults - no configuration exists - do nothing
            }
        } else {
            // No configuration is setup - assume the defaults - do nothing
        }
    }

    if ( !array_key_exists($report_requested_format, $module->getExportFormats()) ) {
        if ($debug_cron) {
            $module->log('SFTP CRON Attempting Report Delivery for Report ID '. $report_id .' FAILED - Invalid Report Format ' . $nl . ' for PID: '.$Proj->project_id);
        }
        
        $result = [
            'status' => 'ERROR',
            'status_message' => 'Invalid Report Format Requested!',
        ];
        
        return $result;
        
    } else {
        foreach ( $module->getExportFormats() as $rf => $rl ) {
            if ( $rf == $report_requested_format ) {
                $report_format = $rf; // This is subtle, but it's more secure to use this than user input
                break;
            }
        }
    }

    $report_type = "flat";
    if ( !array_key_exists($report_requested_type, $module->getExportTypes()) ) {
        if ($debug_cron) {
            $module->log('SFTP CRON Attempting Report Delivery for Report ID '. $report_id .' FAILED - Invalid Report Type ' . $nl . ' for PID: '.$Proj->project_id);
        }
        $result = [
            'status' => 'ERROR',
            'status_message' => 'Invalid Report Type Requested!',
        ];
        
        return $result;
        
    } else {
        foreach ( $module->getExportTypes() as $rt => $rl ) {
            if ( $rt == $report_requested_type ) {
                $report_type = $rt; // This is subtle, but it's more secure to use this than user input
                break;
            }
        }
    }

    // Check to see if we need to include init_functions.php
    if ( !function_exists('addBOMtoUTF8') )
        require_once APP_PATH_DOCROOT."Config/init_functions.php";

    if ( $report_id == 'ALL') {
        $report_name = "ALL DATA";
        $report_file_identifier = "ALL_DATA";
        $report_valid = true;
    } else {
        // Get a list of the reports that this user/project has access to and make sure the provided ID is one of them
        $reports = DataExport::getReportNames(null, $applyUserAccess = false); // No User Permissions
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
            if ($debug_cron) {
                $module->log('SFTP CRON Attempting Report Delivery for Report ID '. $report_id .' FAILED - Invalid Report OR Permission Issue! ' . $nl . ' for PID: '.$Proj->project_id);
            }
            $result = [
                'status' => 'ERROR',
                'status_message' => 'Invalid Report OR you do not have permission to access this report!',
            ];
            
            return $result; // IF NOT VALID - EXIT
        }
    }

    // If we get to here the report is valid
    // Get the report in CSV format
    //REDCap::getReport ( int $report_id [, string $outputFormat = 'array' [, bool $exportAsLabels = FALSE [, bool $exportCsvHeadersAsLabels = FALSE ]]] )
    // 'array', 'csv', 'json', and 'xml'
    //$csv_data = REDCap::getReport ( $report_id, 'csv' ); // ORIGINAL

    if ($debug_cron) {
        $module->log('SFTP CRON Attempting Report Delivery for Report ID '. $report_id .' Getting Data in format: '. $report_type .$nl .' for PID: '.$Proj->project_id);
    }

    if ( $report_type == "eav") {
        // EAV
        $fout = "csv";
        if ( $report_format == 'csv' || $report_format == 'csvraw' || $report_format == 'csvlabels' ) {
            $fout = "csv";
        } elseif ( $report_format == 'odm' || $report_format == 'odmraw' || $report_format == 'odmlabels' ) {
            $fout = "xml";
        } elseif ( $report_format == "json" || $report_format == 'jsonraw' || $report_format == 'jsonlabels' ) {
            $fout = "json";
        }
        //$csv_data = REDCap::getReport ( $report_id, 'array' ); // Get it as array - technically the best, but you CAN'T DO LABELS
        //$csv_data = $module::reformat_data_as_EAV( $csv_data, $fout ); // This is if we want to use ARRAY

        if ( $report_id == 'ALL') {
            $csv_data = $module->get_all_data_report_for_sftp('json', (strpos($report_format, "labels") === false ? false : true)); //PHP8fix
            $csv_data = $module->reformat_json_as_EAV($csv_data, $fout); //PHP8fix
        } else {
            $csv_data = REDCap::getReport($report_id, 'json', (strpos($report_format, "labels") === false ? false : true));
            $csv_data = $module->reformat_json_as_EAV($csv_data, $fout); //PHP8fix
        }
    }
    else {
        // Flat
        $fout = "csv";
        if ( $report_format == 'csv' || $report_format == 'csvraw' || $report_format == 'csvlabels' ) {
            $fout = "csv";
        } elseif ( $report_format == 'odm' || $report_format == 'odmraw') {
            $fout = "odm";
        } elseif ( $report_format == 'odmlabels' ) {
            $fout = "xml";
        } elseif ( $report_format == "json" || $report_format == 'jsonraw' || $report_format == 'jsonlabels' ) {
            $fout = "json";
        }

        if ( $report_id == 'ALL' ) {
            $csv_data = $module->get_all_data_report_for_sftp($fout, (strpos($report_format, "labels") === false ? false : true)); //PHP8fix
        } else {
            $csv_data = REDCap::getReport($report_id, $fout, (strpos($report_format, "labels") === false ? false : true));
        }
    }

    $today = date('Y_m_d_h_i');
    $rand_str = $module->get_random_string(4); // generate a random string for uniqueness    
    $reportFileNameStr = 'Report_' . $report_file_identifier . '_' . $Proj->project_id . '_' . $rand_str . '_' . $today;  // and add suffix depending on report format type
    
    if ( $report_format == 'csv' || $report_format == 'csvraw' || $report_format == 'csvlabels' ) {
        // The below does not seem to work on all platforms
        //$csv_data = iconv("CP1257","UTF-8", $csv_data);
        
        $csv_data = addBOMtoUTF8($csv_data);
        $tmp_file_short = $found_predefined_config ? $report_filename : $reportFileNameStr . '.csv';
        
    } elseif ( $report_format == 'odm' || $report_format == 'odmraw' || $report_format == 'odmlabels' ) {
        
        $tmp_file_short = $found_predefined_config ? $report_filename : $reportFileNameStr . '.xml';
        
    } elseif ( $report_format == "json" || $report_format == 'jsonraw' || $report_format == 'jsonlabels' ) {
        
        $tmp_file_short = $found_predefined_config ? $report_filename : $reportFileNameStr . '.json';
    }

    $tmp_file = APP_PATH_TEMP.$tmp_file_short;

    file_put_contents($tmp_file,$csv_data);

    // bump the modification time to allow the file to stay in the temp folder longer and not be swept away too early
    $hourFactor = 3;  // Three hours.  TODO: use config?
    $time = time() + ($hourFactor * 3600);
    touch($tmp_file, $time);
    // log the file size
    $local_file_size = filesize($tmp_file);
    if ($debug_cron) {
        $module->log('SFTP Temp Local File Size: (' . $local_file_size . ')');
    }

    if ($debug_cron) {
        $module->log('SFTP CRON Attempting Report Delivery for Report ID '. $report_id .' Data SAVED to temp file ' . $nl . ' for PID: '.$Proj->project_id);
    }

    // Upload the file to the designated config location
    $target_sites        = $module->getProjectSetting('sftp-sites');
    $target_remote_types = $module->getProjectSetting('remote-site-type');
    $target_names        = $module->getProjectSetting('sftp-site-name');
    $target_hosts        = $module->getProjectSetting('sftp-site-host');
    $target_ports        = $module->getProjectSetting('sftp-site-port');
    $target_users        = $module->getProjectSetting('sftp-site-user');
    $target_pwds         = $module->getProjectSetting('sftp-site-pwd');
    $target_pkis         = $module->getProjectSetting('sftp-site-pk');
    $target_auth         = $module->getProjectSetting('sftp-site-auth-method'); // 1=PWD, 2=Key

    // Path
    $target_paths        = $module->getProjectSetting('sftp-site-folder');

    // S3 stuff
    $target_buckets      = $module->getProjectSetting('s3-bucket-name');
    $target_regions      = $module->getProjectSetting('s3-region-name');

    if ( !isset($target_sites[$cfg]) ) {
        if ($debug_cron) {
            $module->log('SFTP CRON Attempting Report Delivery for Report ID '. $report_id .' FAIL - sFTP Invalid Configuration' . $nl . ' for PID: '.$Proj->project_id);
        }
        $result = [
            'status' => 'ERROR',
            'status_message' => 'SFTP Configuration is invalid!',
        ];

        Logging::logEvent(NULL, "", "OTHER", "", "SFTP Report Exporter CRON - ERROR - Invalid Configuration!", "SFTP Report Exporter CRON", "", "", "", true, null, null, false);

        return $result;
    }
    
    if ( $target_sites[$cfg] != true ) {
        if ($debug_cron) {
            $module->log('SFTP CRON Attempting Report Delivery for Report ID '. $report_id .' FAIL - sFTP Invalid Configuration' . $nl . ' for PID: '.$Proj->project_id);
        }
        $result = [
            'status' => 'ERROR',
            'status_message' => 'SFTP Configuration is invalid!',
        ];

        Logging::logEvent(NULL, "", "OTHER", "", "SFTP Report Exporter - ERROR - Invalid Configuration!", "SFTP Report Exporter", "", "", "", true, null, null, false);

        return $result;
    }

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

        if ($debug_cron) {
            $module->log('SFTP CRON Attempting Report Delivery for Report ID '. $report_id .$nl .' Uploading report to S3: '. $target_names[$cfg] .$nl .' for PID: '.$Proj->project_id);
        }

        // Upload to the location
        try {
            // Upload to the location
            // TRUE = SEND, FALSE = TEST
            //
            if ($flagSendFile) {  // SEND mode
                // SEND THE FILE via S3
                $upload_response = $module->upload_file_to_s3_bucket($tmp_file_short,$tmp_file,$config);

                if ($debug_cron) {
                    $msg = 'UPLOAD SEND S3: ' . $tmp_file;
                    $msg .= ' Response: ' . print_r($upload_response, true);
                    $msg .= ' File Size: ' . filesize($tmp_file);
                    $module->log($msg);
                }
                
            } else {              // debug test mode
                // do not actually S3 send  Just test getting to this point.
                if ($debug_cron) {
                    $msg = 'TEST UPLOAD SEND S3: ' . $tmp_file;
                    $msg .= ' File Size: ' . filesize($tmp_file);
                    $module->log($msg);
                }         

                // make the response message match what we are doing
                $upload_response = [
                    'status' => 'OK',
                    'status_message' => "This is a SIMULATION TEST ONLY!",
                ]; // indicate simulation message
            }
                    
        } catch (Exception $e) {
            $logMsgError = 'Upload File to S3 Failure. ' . $e->getMessage();
            $module->log($logMsgError);
        }
        
        if ($debug_cron) {
            $module->log('SFTP CRON Attempting Report Delivery for Report ID '. $report_id .$nl .' Uploading report to sFTP: '. $target_names[$cfg] .$nl .' RESULT '.$upload_response['status_message'].$nl .' for PID: '.$Proj->project_id);
        }

        // remove the file
        unlink($tmp_file); // Clean-up
    }
    elseif ( $target_remote_types[$cfg] && $target_remote_types[$cfg] == 'local' ){
        // Local storage
        $local_path = $target_paths[$cfg];
        
        if ( substr($local_path, -1) !== DIRECTORY_SEPARATOR ) {
            $local_path = $local_path.DIRECTORY_SEPARATOR;
        }
        
        rename($tmp_file, $local_path.$tmp_file_short);
        unlink($tmp_file); // Just in case remove it from the temp folder

        if ($debug_cron) {
            $module->log('SFTP CRON Attempting Report Delivery for Report ID '. $report_id .$nl .' Uploading report to LOCAL: '. $target_paths[$cfg] .$nl .' for PID: '.$Proj->project_id);
        }

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
            if ($debug_cron) {
                $module->log('SFTP CRON Attempting Report Delivery for Report ID '. $report_id .$nl .' FAILED Uploading report to sFTP: '. $target_names[$cfg] .' - Missing AUTH key ' . $nl . ' for PID: '.$Proj->project_id);
            }
            
            $result = [
                'status' => 'ERROR',
                'status_message' => 'Invalid or missing authentication key! Check the module config!',
            ];

            Logging::logEvent(NULL, "", "OTHER", "", "SFTP Report Exporter - ERROR - Invalid or missing authentication key for ".$target_names[$cfg],"SFTP Report Exporter", "", "", "", true, null, null, false);

            return $result;
        }

        if ( $config['auth_method'] == 'basic' && strlen(trim($config['pwd']))<=0 ) {
            if ($debug_cron) {
                $module->log('SFTP CRON Attempting Report Delivery for Report ID '. $report_id .$nl .' FAILED Uploading report to sFTP: '. $target_names[$cfg] .' - Credentials Issue ' . $nl . ' for PID: '.$Proj->project_id);
            }
            
            $result = [
                'status' => 'ERROR',
                'status_message' => 'Invalid or missing user credentials! Check the module config!',
            ];

            Logging::logEvent(NULL, "", "OTHER", "", "SFTP Report Exporter - ERROR - Invalid or missing user credentials for ".$target_names[$cfg], "SFTP Report Exporter", "", "", "", true, null, null, false);

            return $result;
        }

        if ($debug_cron) {
            $module->log('SFTP CRON Attempting Report Delivery for Report ID '. $report_id .$nl .' Uploading report to sFTP: '. $target_names[$cfg] .$nl .' for PID: '.$Proj->project_id);
        }

        // Upload to the location
        try {
            // Upload to the location
            // TRUE = SEND, FALSE = TEST
            //
            if ($flagSendFile) {  // SEND mode
                // SEND THE FILE via SFTP
                $upload_response = $module->upload_file_to_sftp($tmp_file_short, $tmp_file, $config);
                
                if ($debug_cron) {
                    $local_file_size = filesize($tmp_file);
                    
                    $msg = 'UPLOAD SEND SFTP: ' . $tmp_file;
                    $msg .= ' Response: ' . print_r($upload_response, true);
                    $msg .= ' Local File Size: ' . $local_file_size;

                    $remoteFileSize = ( isset($upload_response['filesize']) ? $upload_response['filesize'] : 0 );
                    
                    if ($remoteFileSize == -1) {
                        $msg .= ' TRANSFER ERROR: [' . $upload_response['status_message'] . ']';
                        $module->log($msg);
                    }
                    
                    if ($remoteFileSize > -1) {
                        $msg .= ' Remote File Size: [' . $remoteFileSize . ']';
                        $module->log($msg);
                        
                        if ($module->areFileSizesDifferent($remoteFileSize, $local_file_size)) {
                            $msg = 'PARTIAL FILE UPLOAD WARNING [' . $remoteFileSize . '] [' . $local_file_size . ']';
                            $module->log($msg);
                        }
                    }
                }

            } else {              // debug test mode
                // do not actually SFTP send  Just test getting to this point.
                if ($debug_cron) {
                    $local_file_size = filesize($tmp_file);

                    $msg = 'TEST UPLOAD SEND SFTP: ' . $tmp_file;
                    $msg .= ' Local File Size: ' . $local_file_size;

                    // fake it  mock transfer: replaces action of upload_file_to_sftp
                    $upload_response = [
                        'status' => 'OK',
                        'status_message' => "UPLOAD COMPLETE - OK!",
                        'filesize' => $local_file_size
                    ];

                    $remoteFileSize = ( isset($upload_response['filesize']) ? $upload_response['filesize'] : 0 );
                    $msg .= ' Remote File Size: [' . $remoteFileSize . ']';
                    
                    $module->log($msg);

                    if ($module->areFileSizesDifferent($remoteFileSize, $local_file_size)) {
                        $msg = 'PARTIAL FILE UPLOAD WARNING [' . $remoteFileSize . '] [' . $local_file_size . ']';
                        $module->log($msg);
                    }
                }
                
                if ($debug_cron) {
                    $module->log('TEST SFTP Remote File Size: (' . $local_file_size .')'); // fake it for the simulation
                }

                // make the response message match what we are doing
                $upload_response = [
                    'status' => 'OK',
                    'status_message' => "This is a SIMULATION TEST ONLY!",
                ]; // indicate simulation message                
            }
        
        } catch (Exception $e) {
            $logMsgError = 'Upload File to SFTP Failure. ' . $e->getMessage();
            $module->log($logMsgError);
        }
        
        if ($debug_cron) {
            $module->log('SFTP CRON Attempting Report Delivery for Report ID '. $report_id .$nl .' Uploading report to sFTP: '. $target_names[$cfg] .$nl .' RESULT '.$upload_response['status_message'].$nl .' for PID: '.$Proj->project_id);
        }

        // remove the file
        unlink($tmp_file); // Clean-up
    }

    return $upload_response;
}


if ( isset($_GET['ui']) && is_numeric( trim(strip_tags(html_entity_decode($_GET['ui'], ENT_QUOTES))) ) ) {
    // include the footer
    require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
}
