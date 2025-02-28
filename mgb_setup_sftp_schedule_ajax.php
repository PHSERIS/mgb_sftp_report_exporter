<?php
namespace MGB\MGBSFTPReportExporter;
/**
 * Manage the module configs
 * Set the cron schedule for a REPORT->SFTP Config combination
 * - Each report can have one and only one SFTP Cron config
 *      - i.e. Report A can be configured to be sent to Site Z; Report B can be Configured for Size E
 *          Report A can also be configured to be sent to Size E
 */
use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;
use DataExport;
use Logging;
use MetaData;
use DateTime;
use DateTimeZone;


if (is_null($module)) { exit(); }
if ( strpos(get_class($module),"MGBSFTPReportExporter") == false ) { exit(); }

global $Proj;

if ( !isset($_POST['rpt']) || !is_numeric($_POST['rpt']) ) exit('[]');
$rep_id = trim(strip_tags(html_entity_decode($_POST['rpt'], ENT_QUOTES)));

// Get the config number that we want to nix
if ( !isset($_POST['cfg']) || !is_numeric($_POST['cfg']) ) exit('[]');
$cfg = trim(strip_tags(html_entity_decode($_POST['cfg'], ENT_QUOTES)));

$target_names       = $module->getProjectSetting('sftp-site-name');

/**
 * The Report Schedules JSON will have the following format:
 * CONFIGID_REPORTID => array (
        repeat_type = Daily|Weekly|Monthly
 *      repeat_day_week = Mo|Tu|We|Th|Fr|Sa|Su
 *      repeat_day_month = 1 to 31
 *      last_sent   = 2021-01-01 (formatted date)
 *      last_sent_status = SUCCESS | FAIL (see log)
 * );
 */

$all_scheduled_reports = $module->getProjectSetting('project_report_schedules');
if ( isset($_POST['set_sched']) && !is_null($_POST['set_sched']) ) {
    $cfgid = trim(strip_tags(html_entity_decode($_POST['cfg'], ENT_QUOTES)));
    $rptid = trim(strip_tags(html_entity_decode($_POST['rpt'], ENT_QUOTES)));

    // Set the schedule for this report
    $freq = trim(strip_tags(html_entity_decode($_POST['freq'], ENT_QUOTES)));
    $daily_days = trim(strip_tags(html_entity_decode($_POST['dailyrep'], ENT_QUOTES)));
    $week_days = is_array($_POST['wdays']) ? sanitize_post_array($_POST['wdays']) : false;
    $month_days = trim(strip_tags(html_entity_decode($_POST['mday'], ENT_QUOTES)));
    $run_hour = trim(strip_tags(html_entity_decode($_POST['rhrun'], ENT_QUOTES)));
    $is_active = trim(strip_tags(html_entity_decode($_POST['active'], ENT_QUOTES)));
    $use_predefined_configs = trim(strip_tags(html_entity_decode($_POST['use_predefined_configs'], ENT_QUOTES)));
    $selected_type = "flat"; // defaults
    $selected_format = "csvraw"; // defaults

    // Export Format
    if ( !isset($_POST['rformat']) ) {
        $result = [
            'status' => 'ERROR',
            'status_message' => 'Invalid Report Format Requested!',
        ];
        print json_encode($result);
        exit();
    }
    $report_requested_format = trim(strip_tags(html_entity_decode($_POST['rformat'], ENT_QUOTES)));
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
                $selected_format = $rf; // This is subtle, but it's more secure to use this than user input
                break;
            }
        }
    }

    // Export type
    if ( !isset($_POST['rtype']) ) {
        $result = [
            'status' => 'ERROR',
            'status_message' => 'Invalid Report Type Requested!',
        ];
        print json_encode($result);
        exit();
    }
    $report_requested_type = trim(strip_tags(html_entity_decode($_POST['rtype'], ENT_QUOTES)));
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
                $selected_type = $rt; // This is subtle, but it's more secure to use this than user input
                break;
            }
        }
    }


    if ( $freq != 'daily' && $freq != 'monthly' && $freq != 'weekly' ) {
        $result = [
            'status' => 'ERROR',
            'status_message' => 'Oops - Please check the value for Report Frequency',
        ];
        print json_encode($result);
        exit();
    }
    // Try to get the settings, if any. We need to keep the last run time and status
    $all_scheduled_reports = $module->getProjectSetting('project_report_schedules');

    $last_run = "";
    $last_run_status = "";
    if ( isset ($all_scheduled_reports) ) {
        if ( isset ($all_scheduled_reports[$cfgid."_".$rptid]) && isset($all_scheduled_reports[$cfgid."_".$rptid]['report_last_run'])
                && strlen($all_scheduled_reports[$cfgid."_".$rptid]['report_last_run']) > 0 )
            $last_run = $all_scheduled_reports[$cfgid."_".$rptid]['report_last_run'];
        if ( isset ($all_scheduled_reports[$cfgid."_".$rptid]) && isset($all_scheduled_reports[$cfgid."_".$rptid]['$last_run_status'])
            && strlen($all_scheduled_reports[$cfgid."_".$rptid]['$last_run_status']) > 0 )
            $last_run = $all_scheduled_reports[$cfgid."_".$rptid]['$last_run_status'];
    }


    // initialize
    $setting_arr = [
        'repeat_type' => -1,
        'repeat_day_daily'=> -1,
        'repeat_day_week' => -1,
        'repeat_day_month'=> -1,
        'report_run_hour' => -1,
        'use_predefined_configs' => isset($use_predefined_configs) && $use_predefined_configs == 1 ? 1 : 0,
        'report_type'   => $selected_type,
        'report_format' => $selected_format,
        'report_last_run' => $last_run,
        'report_last_run_status' => $last_run_status
    ];
    if ( $freq == 'daily' ) {
        if ( !is_numeric($daily_days)) {
            $result = [
                'status' => 'ERROR!',
                'status_message' => 'Oops - Please check the value for Run Daily',
            ];
            print json_encode($result);
            exit();
        }

        $setting_arr['repeat_type'] = 'daily';
        $setting_arr['report_active'] = $is_active;
        $setting_arr['repeat_day_daily'] = $daily_days; // daily frequency
        if ( $daily_days == 2 ) { // this is the hourly run
            $setting_arr['report_run_hour'] = -1;
        }
        else {
            $setting_arr['report_run_hour'] = $run_hour;
        }

        $all_scheduled_reports[$cfgid."_".$rptid] = $setting_arr; // Update the setting and save it
        $module->setProjectSetting('project_report_schedules', $all_scheduled_reports);

        $result = [
            'status' => 'OK!',
            'status_message' => 'Schedule SAVED!',
        ];
        print json_encode($result);
        exit();
    }
    elseif ( $freq == 'weekly' ) {
        // parse the days of the week
        if ( !is_array($week_days) || $week_days==false ) {
            $result = [
                'status' => 'ERROR!',
                'status_message' => 'Oops - Please check the value for Weekly on',
            ];
            print json_encode($result);
            exit();
        }

        $setting_arr['repeat_type'] = 'weekly';
        $setting_arr['repeat_day_week'] = $week_days;
        $setting_arr['report_run_hour'] = $run_hour;
        $setting_arr['report_active'] = $is_active;

        $all_scheduled_reports[$cfgid."_".$rptid] = $setting_arr; // Update the setting and save it
        $module->setProjectSetting('project_report_schedules', $all_scheduled_reports);

        $result = [
            'status' => 'OK!',
            'status_message' => 'Schedule SAVED!',
        ];
        print json_encode($result);
        exit();
    }
    elseif ( $freq == 'monthly' ) {
        if ( !is_numeric($month_days) ){
            $result = [
                'status' => 'ERROR!',
                'status_message' => 'Oops - Please check the value for Monthly on',
            ];
            print json_encode($result);
            exit();
        }
        elseif ( $month_days>31 || $month_days<1 ) {
            $result = [
                'status' => 'ERROR!',
                'status_message' => 'Oops - Please check the value for Monthly on',
            ];
            print json_encode($result);
            exit();
        }

        $setting_arr['repeat_type'] = 'monthly';
        $setting_arr['repeat_day_month'] = $month_days;
        $setting_arr['report_run_hour'] = $run_hour;
        $setting_arr['report_active'] = $is_active;

        $all_scheduled_reports[$cfgid."_".$rptid] = $setting_arr; // Update the setting and save it
        $module->setProjectSetting('project_report_schedules', $all_scheduled_reports);

        $result = [
            'status' => 'OK!',
            'status_message' => 'Schedule SAVED!',
        ];
        print json_encode($result);
        exit();
    }
    else {
        $result = [
            'status' => 'ERROR',
            'status_message' => 'Oops - Please check the value for Report Frequency',
        ];
        print json_encode($result);
        exit();
    }

    $result = [
        'status' => 'ERROR!',
        'status_message' => 'Please double-check the settings provided!',
    ];
    print json_encode($result);
    exit();
}
else {
    /**
     * Retrieve the report to display
     */
    if (isset($all_scheduled_reports) && is_array($all_scheduled_reports) ) {
        // Nothing - it's already an array
    }
    elseif (isset($all_scheduled_reports) && is_string($all_scheduled_reports) && strlen($all_scheduled_reports) > 0) { 
        $all_scheduled_reports = json_decode($all_scheduled_reports, true);
    } else {
        $all_scheduled_reports = array(); // initializing
    }

    if (is_array($all_scheduled_reports)) {
        if (array_key_exists($cfg . "_" . $rep_id, $all_scheduled_reports)) {
            // It's already in there
            $html = get_schedule_form( $cfg, $rep_id, 0, 0, 0, 0, 0, $module);
        } else {
            // It's new
            $html = get_schedule_form($cfg, $rep_id, 0, 0, 0, 0, 0, $module);
        }

        $result = [
            'status' => 'OK',
            'content' => $html,
            'title' => "Schedule Report Delivery for report to " . trim(strip_tags(html_entity_decode($target_names[$cfg], ENT_QUOTES))),
            'num' => $cfg,
        ];
        //print json_encode($module->escape($result));
        //print $module->escape(json_encode($result)); // TODO: Psalm wants to do this.  Is it safe to do so?

        exit();
    } else {
        $result = [
            'status' => 'ERROR',
            'status_message' => 'Please try again',
        ];
        
        $result = json_encode($result);
        print $module->escape($result);
        //print json_encode($module->escape($result));
        exit();
    }
}

function get_schedule_form ( $cfg, $rpt, $repeat_type, $repleat_day_week, $repeat_day_month, $last_sent, $last_status, $module ) {
    $all_scheduled_reports = $module->getProjectSetting('project_report_schedules');
    $cfg_rpt_settings = array();
    if ( isset($all_scheduled_reports[$cfg."_".$rpt]) && is_array($all_scheduled_reports[$cfg."_".$rpt]))
        $cfg_rpt_settings = $all_scheduled_reports[$cfg."_".$rpt];

    $css = "<style>".
            "
                .divTableSched{
                    display: table;
                    width: 100%;
                }
                .divTableSchedRow {
                    display: table-row;
                }
                .divTableSchedHeading {
                    background-color: #EEE;
                    display: table-header-group;
                }
                .divTableSchedCell, .divTableSchedHead {
                    border: 1px solid #999999;
                    display: table-cell;
                    padding: 3px 10px;
                }
                .divTableSchedHeading {
                    background-color: #EEE;
                    display: table-header-group;
                    font-weight: bold;
                }
                .divTableSchedFoot {
                    background-color: #EEE;
                    display: table-footer-group;
                    font-weight: bold;
                }
                .divTableSchedBody {
                    display: table-row-group;
                }
                .ui-checkboxradio-label.ui-corner-all.ui-button.ui-widget.ui-checkboxradio-checked.ui-state-active {
                    background-color: #337ab7; !important;
                    border-color: #2e6da4; !important;
                    color: #fff; !important;
                }".
        "</style>";

    /**
        repeat_type = Daily|Weekly|Monthly
       repeat_day_week = Mo|Tu|We|Th|Fr|Sa|Su
       repeat_day_month = 1 to 31
        last_sent   = 2021-01-01 (formatted date)
       last_sent_status = SUCCESS | FAIL (see log)
       use_predefined_configs = 1 | 0
     */
    $rpt_freq = -1;
    $rpt_d_rep = -1;
    $rpt_run_hour = -1;
    $rpt_w_days = "[0,0,0,0,0,0,0]";
    $rpt_w_days_arr = array(
        0=>0,
        1=>0,
        2=>0,
        3=>0,
        4=>0,
        5=>0,
        6=>0
    );
    $rpt_m_day = 0;
    $rpt_active = 0;
    $rpt_type = "";
    $rpt_format = "";
    $use_predefined_configs = 0;
    if ( isset($cfg_rpt_settings['repeat_type']) && $cfg_rpt_settings['repeat_type'] == 'daily' ) {
        $rpt_freq = 'daily';
        $rpt_active = $cfg_rpt_settings['report_active'];
        $rpt_d_rep = $cfg_rpt_settings['repeat_day_daily'];
        $rpt_type = $cfg_rpt_settings['report_type'];
        $rpt_format = $cfg_rpt_settings['report_format'];
        $use_predefined_configs = isset($cfg_rpt_settings['use_predefined_configs']) ? $cfg_rpt_settings['use_predefined_configs'] : 0;
        $rpt_run_hour = $cfg_rpt_settings['report_run_hour'];
    }
    elseif ( isset($cfg_rpt_settings['repeat_type']) && $cfg_rpt_settings['repeat_type'] == 'weekly' ) {
        $rpt_freq = 'weekly';
        $rpt_active = $cfg_rpt_settings['report_active'];
        $rpt_type = $cfg_rpt_settings['report_type'];
        $use_predefined_configs = isset($cfg_rpt_settings['use_predefined_configs']) ? $cfg_rpt_settings['use_predefined_configs'] : 0;
        $rpt_run_hour = $cfg_rpt_settings['report_run_hour'];
        $rpt_format = $cfg_rpt_settings['report_format'];
        if ( isset ($cfg_rpt_settings['repeat_day_week']) && is_array($cfg_rpt_settings['repeat_day_week']) ) {
            $rpt_w_days = "";
            $rpt_w_days_arr = $cfg_rpt_settings['repeat_day_week'];
            foreach ( $cfg_rpt_settings['repeat_day_week'] as $rd => $rd_sel ) {
                $rpt_w_days.= ",".$rd_sel;
            }
            $rpt_w_days = "[".substr($rpt_w_days,1)."]";
        }
        else
            $rpt_w_days = "[0,0,0,0,0,0,0]";
    }
    elseif ( isset($cfg_rpt_settings['repeat_type']) && $cfg_rpt_settings['repeat_type'] == 'monthly' ) {
        $rpt_freq = 'monthly';
        $rpt_m_day = $cfg_rpt_settings['repeat_day_month'];
        $rpt_active = $cfg_rpt_settings['report_active'];
        $rpt_type = $cfg_rpt_settings['report_type'];
        $rpt_format = $cfg_rpt_settings['report_format'];
        $use_predefined_configs = isset($cfg_rpt_settings['use_predefined_configs']) ? $cfg_rpt_settings['use_predefined_configs'] : 0;
        $rpt_run_hour = $cfg_rpt_settings['report_run_hour'];
    }
    else {
        $rpt_freq = -1;
        $rpt_active = 0;
    }

    $curr_time_this_tz = new DateTime("NOW", new DateTimeZone(date_default_timezone_get()));

    $js = "<script type='text/javascript'>".
        "var cfgnum=".$cfg.";".
        "var rptnum=".$rpt.";".
        "var rptfreq='".$rpt_freq."';".
        "var rptdrep='".$rpt_d_rep."';".
        "var rptwdays=".$rpt_w_days.";".
        "var rptmday=".$rpt_m_day.";".
        "var rpthrun=".(is_numeric($rpt_run_hour) ? $rpt_run_hour : -1).";".
        "var rptactive=".$rpt_active.";".
        "var rpttype=".((isset($rpt_type) && strlen($rpt_type)>0) ? "'".$rpt_type."'" : "'flat'" ).";".
        "var rptformat=".((isset($rpt_format) && strlen($rpt_format)>0) ? "'".$rpt_format."'" : "'csvraw'" ).";".
        "var rptuseexistingconfig=".$use_predefined_configs.";".
        "$( function() { $( \"input[type=checkbox]\" ).checkboxradio({icon: false});} );".
        "function schedtypeformat(id) {".
        "  rpttype=$('#export_type_sched_'+id).val();".
        "  rptformat=$('#export_format_sched_'+id).val();".
        "}".
        "function showhidetypeformat() { ".
        " if ( $('#use_predefined_configs').prop('checked') == true ) { rptuseexistingconfig=1; $('#divreptypeformat').hide(); }".
        " if ( $('#use_predefined_configs').prop('checked') == false ) { rptuseexistingconfig=0; $('#divreptypeformat').show(); }".
        "}".
        "function updateSchedDisplay() {".
        "showhidetypeformat(); ".
        "rptfreq=$('#run_every').val();".
        "switch (rptfreq){".
        "case 'daily': ".
        "  $('#daily_repeat').show(); $('#weekly_row').hide(); $('#monthly_row').hide(); $('#hour_row').show(); if (rptdrep==2) { $('#hour_row').hide(); } break;".
        "case 'weekly': ".
        "  $('#daily_repeat').hide(); $('#weekly_row').show(); $('#monthly_row').hide(); $('#hour_row').show(); break;".
        "case 'monthly': ".
        "  $('#daily_repeat').hide(); $('#weekly_row').hide(); $('#monthly_row').show(); $('#hour_row').show(); break;".
        "default: ".
        "  $('#daily_repeat').hide(); $('#weekly_row').hide(); $('#monthly_row').hide(); $('#hour_row').hide(); break;".
        "}".
        "}".
        "$(document).ready(function() {
            updateSchedDisplay();
        } );".
        "</script>";
    $html = "<div class=\"divTableSched\" style=\"width: 100%;\">".
	"<div class=\"divTableSchedBody\">".
		"<div class=\"divTableSchedRow\">".
			"<div class=\"divTableSchedCell\">Upload report frequency</div>".
			"<div class=\"divTableSchedCell\">".
                "<select name='run_every' id='run_every' style='width:150px; text-align: center' onchange='updateSchedDisplay()'>".
                    "<option value=\"-1\" ".($rpt_freq == -1 ? "selected" : "").">--Select Frequency--</option>".
                    "<option value=\"daily\" ".($rpt_freq == 'daily' ? "selected" : "").">Daily</option>".
                    "<option value=\"weekly\" ".($rpt_freq == 'weekly' ? "selected" : "").">Weekly</option>".
                    "<option value=\"monthly\" ".($rpt_freq == 'monthly' ? "selected" : "").">Monthly</option>".
                "</select>".
            "</div>".
		"</div>".
        "<div class=\"divTableSchedRow\" id='daily_repeat'>".
            "<div class=\"divTableSchedCell\">Run daily</div>".
            "<div class=\"divTableSchedCell\">".
                "<input type=\"radio\" id=\"daily_once\" name=\"daily_repeat\" value=\"1\" ".($rpt_d_rep == 1 ? "checked" : "")." onchange=\"rptdrep=this.value; $('#hour_row').show();\">".
                "<label for=\"daily_once\">Once per day</label>"."</br>".
                "<input type=\"radio\" id=\"daily_hourly\" name=\"daily_repeat\" value=\"2\" ".($rpt_d_rep == 2 ? "checked" : "")." onchange=\"rptdrep=this.value; $('#hour_row').hide();\">".
                "<label for=\"daily_hourly\">Every hour</label>"."</br>".
                "<input type=\"radio\" id=\"daily_quarter\" name=\"daily_repeat\" value=\"3\" ".($rpt_d_rep == 3 ? "checked" : "")." onchange=\"rptdrep=this.value; $('#hour_row').hide();\">".
                "<label for=\"daily_quarter\">Every 6 hours</label>"."</br>".
                "<input type=\"radio\" id=\"daily_twice\" name=\"daily_repeat\" value=\"4\" ".($rpt_d_rep == 4 ? "checked" : "")." onchange=\"rptdrep=this.value; $('#hour_row').hide();\">".
                "<label for=\"daily_twice\">Every 12 hours</label>".
            "</div>".
        "</div>".
		"<div class=\"divTableSchedRow\" id='weekly_row'>".
			"<div class=\"divTableSchedCell\">Weekly on</div>".
			"<div class=\"divTableSchedCell\">".
                    "<label for=\"weekly-1\">Mon</label>".
                    "<input type=\"checkbox\" name=\"weekly-1\" id=\"weekly-1\" ".($rpt_w_days_arr[0] == 1 ? "checked" : "")." onchange='$(this).is(\":checked\") ? rptwdays[0] = 1 : rptwdays[0] = 0'>".
                    "<label for=\"weekly-2\">Tue</label>".
                    "<input type=\"checkbox\" name=\"weekly-2\" id=\"weekly-2\" ".($rpt_w_days_arr[1] == 1 ? "checked" : "")." onchange='$(this).is(\":checked\") ? rptwdays[1] = 1 : rptwdays[1] = 0'>".
                    "<label for=\"weekly-3\">Wed</label>".
                    "<input type=\"checkbox\" name=\"weekly-3\" id=\"weekly-3\" ".($rpt_w_days_arr[2] == 1 ? "checked" : "")." onchange='$(this).is(\":checked\") ? rptwdays[2] = 1 : rptwdays[2] = 0'>".
                    "<label for=\"weekly-4\">Thu</label>".
                    "<input type=\"checkbox\" name=\"weekly-4\" id=\"weekly-4\" ".($rpt_w_days_arr[3] == 1 ? "checked" : "")." onchange='$(this).is(\":checked\") ? rptwdays[3] = 1 : rptwdays[3] = 0'>".
                    "<label for=\"weekly-5\">Fri</label>".
                    "<input type=\"checkbox\" name=\"weekly-5\" id=\"weekly-5\" ".($rpt_w_days_arr[4] == 1 ? "checked" : "")." onchange='$(this).is(\":checked\") ? rptwdays[4] = 1 : rptwdays[4] = 0'>".
                    "<label for=\"weekly-6\">Sat</label>".
                    "<input type=\"checkbox\" name=\"weekly-6\" id=\"weekly-6\" ".($rpt_w_days_arr[5] == 1 ? "checked" : "")." onchange='$(this).is(\":checked\") ? rptwdays[5] = 1 : rptwdays[5] = 0'>".
                    "<label for=\"weekly-7\">Sun</label>".
                    "<input type=\"checkbox\" name=\"weekly-7\" id=\"weekly-7\" ".($rpt_w_days_arr[6] == 1 ? "checked" : "")." onchange='$(this).is(\":checked\") ? rptwdays[6] = 1 : rptwdays[6] = 0'>".
            "</div>".
		"</div>".
		"<div class=\"divTableSchedRow\" id='monthly_row'>".
			"<div class=\"divTableSchedCell\">Monthly on</div>".
			"<div class=\"divTableSchedCell\">".
            "<select name='month_day' id='month_day' style='width:150px; text-align: center' onchange='rptmday=this.value'>";
                $html .= "<option value=\"-1\" ".($rpt_m_day == -1 ? "selected" : "").">--Select Day--</option>";
                for ( $z=1; $z<=31; $z++) {
                    $selected = ($rpt_m_day == $z ? "selected" : "");
                    $html .= "<option value=\"".$z."\" $selected>$z</option>";
                }
            $html .= "</select>".
            "</div>".
		"</div>".
        "<div class=\"divTableSchedRow\" id='hour_row'>".
            "<div class=\"divTableSchedCell\">Generate Report at (hour)</div>".
            "<div class=\"divTableSchedCell\">".
                "<select name='hour_run' id='hour_run' style='width:150px; text-align: center' onchange='rpthrun=this.value'>";
                    $html .= "<option value=\"-1\" ".($rpt_run_hour == -1 ? "selected" : "").">--Select Hour--</option>";
                    for ( $z=0; $z<=23; $z++) {
                        $selected = ($rpt_run_hour == $z ? "selected" : "");
                        $html .= "<option value=\"".$z."\" $selected>$z:00</option>";
                    }
                    $html .= "</select>".
                        "</br></br>NOTE: Times specified are ".date_default_timezone_get()." timezone</br>".
                        "Reports will be run based on the timezone above!</br>".
                        "Current date/time: ".$curr_time_this_tz->format('M j, Y H:i')." (".$curr_time_this_tz->format('g:i A').")".
            "</div>".
        "</div>".
        "<div class=\"divTableSchedRow\">".
            "<div class=\"divTableSchedCell\">Report Format</div>".
                "<div class=\"divTableSchedCell\">".
                        "<div id=\"divreptypeformat\">".
                            $module->get_export_types_dropdown($rpt,$rpt_type,"export_type_sched","schedtypeformat('".$rpt."')")." ".$module->get_export_formats_dropdown($rpt,$rpt_format,"export_format_sched","schedtypeformat('".$rpt."')").
                            "</br> OR </p>".
                        "</div>".
                        "<div id=\"divreptypeformatpredefined\">".
                            "<label for=\"use_predefined_configs\">Use Already Defined Configuration</label>".
                            "<input type=\"checkbox\" name=\"use_predefined_configs\" id=\"use_predefined_configs\" ".($use_predefined_configs == 1 ? "checked" : "")." onchange='showhidetypeformat();'>".
                        "</div>".
                "</div>".
            "</div>".
        "</div>".
        "<div class=\"divTableSchedRow\">".
            "<div class=\"divTableSchedCell\">Enabled/Disabled</div>".
            "<div class=\"divTableSchedCell\">".
                "<input type=\"radio\" id=\"scheden\" name=\"schedactive\" value=\"1\" ".($rpt_active == 1 ? "checked" : "")." onchange='rptactive=this.value'>".
                "<label for=\"scheden\">Enabled</label>"."</br>".
                "<input type=\"radio\" id=\"scheddis\" name=\"schedactive\" value=\"0\" ".($rpt_active == 0 ? "checked" : "")." onchange='rptactive=this.value'>".
                "<label for=\"scheddis\">Disabled</label>".
            "</div>".
        "</div>".
		"<div class=\"divTableSchedRow\">".
			"<div class=\"divTableSchedCell\">Last Send Date / Status</div>".
			"<div class=\"divTableSchedCell\">".
                ((isset($cfg_rpt_settings['report_last_run']) && strlen($cfg_rpt_settings['report_last_run'])>0) ? $cfg_rpt_settings['report_last_run'] : "n/a").
                "</p>".
                ((isset($cfg_rpt_settings['report_last_run_status']) && strlen($cfg_rpt_settings['report_last_run_status'])>0) ? $cfg_rpt_settings['report_last_run_status'] : "n/a").
            "</div>".
		"</div>".
	"</div>".
"</div>";

    return $css.$js.$html;
}

function sanitize_post_array( $arr ) {
    $newarr = array();
    foreach ( $arr as $a ) {
        $v = trim(strip_tags(html_entity_decode($a, ENT_QUOTES)));
        if ( $v != 1 && $v != 0 )
            return false; // incorrect value here
        $newarr[] = $v;
    }
    return $newarr;
}