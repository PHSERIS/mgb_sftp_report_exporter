<?php
namespace MGB\MGBSFTPReportExporter;
/**
 * Manage the report configuration for export
 * This includes:
 * - Report Type
 *      - Flat
 *      - EAV
 * - Report Format
 *      - CSV (Raw)
 *      - CSV (Label)
 *      - ODM / XML (Raw)
 *      - ODM / XML (Label)
 *      - JSON (Raw)
 *      - JSON (Label)
 * - Export File Name/Pattern
 *      - Default - Report_ALL_DATA_17055_qvCJ_2022_08_19_01_59.csv (Report_<REPORT NAME>_<PID>_<4 char random string>_<DATE TIME STAMP>)
 *      - CUSTOM
 *          - Specify name
 *          - Checkbox: Append PID
 *          - Checkbox: Append Random String
 *          - Checkbox: Append Date
 *              - Radio: date ONLY (yyyy-mm-dd)
 *              - Radio: date time (yyyy-mm-dd hh:ii)
 *
 * Save this to the report config
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

/**
 * The Report Export Configuration JSON will have the following format:
 * EXPORTCFG_REPORTID => array (
        report_type => EAV | FLAT
 *      report_format => <the formats>
 *      filename => default | custom
 *      filename_string => <user specified string>
 *      filename_append_pid => 1 | 0
 *      filename_append_rand    => 1 | 0
 *      filename_append_date    => 0 | 1 | 2 (0=nope;1=yyyy-mm-dd;2=yyyy-mm-dd hh:ii)
 * );
 */

$all_report_configurations = $module->getProjectSetting('project_report_configurations');

if ( isset($_POST['set_config']) && !is_null($_POST['set_config']) ) {
    $rptid = trim(strip_tags(html_entity_decode($_POST['rpt'], ENT_QUOTES)));

/**
 *    reptype: reptype,
        repformat: repformat,
            repfiletype: repfiletype,
            repfilecuststr: repfilecuststr,
            repfileappendpid: repfileappendpid,
            repfileappendrand: repfileappendrand,
            repfileappenddate: repfileappenddate
 */

    // Set the report configuration
    //$reptype = trim(strip_tags(html_entity_decode($_POST['reptype'], ENT_QUOTES)));
    //$repformat = trim(strip_tags(html_entity_decode($_POST['repformat'], ENT_QUOTES)));

    $remove[] = "'";
    $remove[] = '"';
    $remove[] = "-";

    $repfiletype = trim(strip_tags(html_entity_decode($_POST['repfiletype'], ENT_QUOTES)));
    $repfilecuststr = trim(strip_tags(html_entity_decode(str_replace( $remove, "", $_POST['repfilecuststr']), ENT_QUOTES)));
    $repfileappendpid = trim(strip_tags(html_entity_decode($_POST['repfileappendpid'], ENT_QUOTES)));
    $repfileappendrand = trim(strip_tags(html_entity_decode($_POST['repfileappendrand'], ENT_QUOTES)));
    $repfileappenddate = trim(strip_tags(html_entity_decode($_POST['repfileappenddate'], ENT_QUOTES)));

    $selected_type = "flat"; // defaults
    $selected_format = "csvraw"; // defaults

    // Export Format
    if ( !isset($_POST['repformat']) ) {
        $result = [
            'status' => 'ERROR',
            'status_message' => 'Invalid Report Format Requested!',
        ];
        print json_encode($result);
        exit();
    }
    $report_requested_format = trim(strip_tags(html_entity_decode($_POST['repformat'], ENT_QUOTES)));
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
    if ( !isset($_POST['reptype']) ) {
        $result = [
            'status' => 'ERROR',
            'status_message' => 'Invalid Report Type Requested!',
        ];
        print json_encode($result);
        exit();
    }
    $report_requested_type = trim(strip_tags(html_entity_decode($_POST['reptype'], ENT_QUOTES)));
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

    if ( $repfiletype != 'default' && $repfiletype != 'custom' ) {
        $result = [
            'status' => 'ERROR',
            'status_message' => 'Oops - Please check the value for File Type',
        ];
        print json_encode($result);
        exit();
    }

    // initialize
    $setting_arr = [
        'report_type'       => 'FLAT', // EAV or FLAT
        'report_format'     => 'csvraw',
        'filename'          => 'default', // default | custom
        'filename_string'   => '',
        'filename_append_pid' => 0, // 1 | 0
        'filename_append_rand'    => 0, // 1 | 0
        'filename_append_date'    => 0, // 0 | 1 | 2 (0=nope;1=yyyy-mm-dd;2=yyyy-mm-dd hh:ii)
    ];

    if ( $repfiletype == 'custom' ) {
        if ( strlen(trim($repfilecuststr))<=0 ) {
            $result = [
                'status' => 'ERROR!',
                'status_message' => 'Oops - Please check the value for Custom Filename',
            ];
            print json_encode($result);
            exit();
        }

        // Form a final report filename example
        $selected_date_append = 0;
        if ( isset($repfileappenddate) && $repfileappenddate == 0 )
            $selected_date_append = 0;
        if ( isset($repfileappenddate) && $repfileappenddate == 1 )
            $selected_date_append = 1;
        if ( isset($repfileappenddate) && $repfileappenddate == 2 )
            $selected_date_append = 2;

        $setting_arr = [
            'report_type'       => $selected_type, // EAV or FLAT
            'report_format'     => $selected_format,
            'filename'          => 'custom', // default | custom
            'filename_string'   => $repfilecuststr,
            'filename_append_pid' => (isset($repfileappendpid) && $repfileappendpid == 1) ? 1 : 0, // 1 | 0
            'filename_append_rand'    => isset($repfileappendrand) && $repfileappendrand == 1 ? 1 : 0, // 1 | 0
            'filename_append_date'    => $selected_date_append, // 0 | 1 | 2 (0=nope;1=yyyy-mm-dd;2=yyyy-mm-dd hh:ii)
        ];

        $all_report_configurations["EXPORTCFG_".$rptid] = $setting_arr; // Update the setting and save it
        $module->setProjectSetting('project_report_configurations', $all_report_configurations);

        $result = [
            'status' => 'OK!',
            'status_message' => 'Configuration SAVED!',
        ];
        print json_encode($result);
        exit();
    }
    else {
        // Default file format selected
        $setting_arr = [
            'report_type'               => $selected_type, // EAV or FLAT
            'report_format'             => $selected_format,
            'filename'                  => 'default', // default | custom
            'filename_string'           => "",
            'filename_append_pid'       => 0,
            'filename_append_rand'      => 0,
            'filename_append_date'      => 0,
        ];

        $all_report_configurations["EXPORTCFG_".$rptid] = $setting_arr; // Update the setting and save it
        $module->setProjectSetting('project_report_configurations', $all_report_configurations);

        $result = [
            'status' => 'OK!',
            'status_message' => 'Configuration SAVED!',
        ];
        print json_encode($result);
        exit();
    }

    $result = [
        'status' => 'ERROR!',
        'status_message' => 'Please double-check the configuration provided - something went wrong!',
    ];
    print json_encode($result);
    exit();
}
else {
    /**
     * Retrieve the report configuration to display
     */
    if (is_array($all_report_configurations)) {
        if (array_key_exists("EXPORTCFG_" . $rep_id, $all_report_configurations)) {
            // It's already in there
            $rep_cfg = $all_report_configurations['EXPORTCFG_'.$rep_id];

            $rep_type = isset($rep_cfg['report_type']) ? trim(strip_tags(html_entity_decode($rep_cfg['report_type'], ENT_QUOTES))) : 'flat'; // default is flat
            $rep_format = isset($rep_cfg['report_format']) ? trim(strip_tags(html_entity_decode($rep_cfg['report_format'], ENT_QUOTES))) : 'csvraw'; // default is csv
            $rep_fname = isset($rep_cfg['filename']) ? trim(strip_tags(html_entity_decode($rep_cfg['filename'], ENT_QUOTES))) : 'default'; // default is default
            $rep_fname_string = isset($rep_cfg['filename_string']) ? trim(strip_tags(html_entity_decode($rep_cfg['filename_string'], ENT_QUOTES))) : ''; // default is empty
            $rep_fname_append_pid = isset($rep_cfg['filename_append_pid']) ? trim(strip_tags(html_entity_decode($rep_cfg['filename_append_pid'], ENT_QUOTES))) : 0; // default is 0
            $rep_fname_append_rand = isset($rep_cfg['filename_append_rand']) ? trim(strip_tags(html_entity_decode($rep_cfg['filename_append_rand'], ENT_QUOTES))) : 0; // default is 0
            $rep_fname_append_date = isset($rep_cfg['filename_append_date']) ? trim(strip_tags(html_entity_decode($rep_cfg['filename_append_date'], ENT_QUOTES))) : 0; // default is 0

            $html = get_report_export_config_form( $rep_id, $rep_type, $rep_format, $rep_fname, $rep_fname_string, $rep_fname_append_pid, $rep_fname_append_rand, $rep_fname_append_date, $module);
        } else {
            // It's new

            $rep_type = 'flat'; // default is flat
            $rep_format = 'csvraw'; // default is csv
            $rep_fname = 'default'; // default is default
            $rep_fname_string = ''; // default is empty
            $rep_fname_append_pid = 0; // default is 0
            $rep_fname_append_rand = 0; // default is 0
            $rep_fname_append_date = 0; // default is 0

            $html = get_report_export_config_form( $rep_id, $rep_type, $rep_format, $rep_fname, $rep_fname_string, $rep_fname_append_pid, $rep_fname_append_rand, $rep_fname_append_date, $module);
        }

        $result = [
            'status' => 'OK',
            'content' => $html,
            'title' => "Configure Report Export",
        ];
        print json_encode($result);
        exit();
    } else {
        // It's new

        $rep_type = 'flat'; // default is flat
        $rep_format = 'csvraw'; // default is csv
        $rep_fname = 'default'; // default is default
        $rep_fname_string = ''; // default is empty
        $rep_fname_append_pid = 0; // default is 0
        $rep_fname_append_rand = 0; // default is 0
        $rep_fname_append_date = 0; // default is 0

        $html = get_report_export_config_form( $rep_id, $rep_type, $rep_format, $rep_fname, $rep_fname_string, $rep_fname_append_pid, $rep_fname_append_rand, $rep_fname_append_date, $module);

        $result = [
            'status' => 'OK',
            'content' => $html,
            'title' => "Configure Report Export",
        ];
        print json_encode($result);
        exit();
    }
}

function get_report_export_config_form ( $rpt,      $export_type,   $export_format,     $filename_type,     $filename_custom_string,    $filename_append_pid,   $filename_append_random,    $filename_append_date,      $module ) {
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
    * The Report Export Configuration JSON will have the following format:
    * EXPORTCFG_REPORTID => array (
    *       report_type => EAV | FLAT
    *       report_format => <the formats>
    *       filename => default | custom
    *       filename_string => <user specified string>
    *       filename_append_pid => 1 | 0
    *       filename_append_rand    => 1 | 0
    *       filename_append_date    => 0 | 1 | 2 (0=nope;1=yyyy-mm-dd;2=yyyy-mm-dd hh:ii)
    * );
    */

    $js = "<script type='text/javascript'>".
        "var rptnum=".$rpt.";".
        "var reptype='".$export_type."';".
        "var repformat='".$export_format."';".
        "var repfiletype='".$filename_type."';".
        "var repfilecuststr=`".$filename_custom_string."`;".
        "var repfileappendpid='".$filename_append_pid."';".
        "var repfileappendrand='".$filename_append_random."';".
        "var repfileappenddate='".$filename_append_date."';".
        "$( function() { $( \"input[type=checkbox]\" ).checkboxradio({icon: false});} );".
        "function show_hide_date_format() { ".
            " if ( $('#filename_append_date').prop('checked') == true ) { $('#date_formats').show(); }".
            " if ( $('#filename_append_date').prop('checked') == false ) { $('#date_formats').hide(); repfileappenddate=0; }".
        "}".
        "function schedtypeformat(id) {".
        "  reptype=$('#export_type_sched_'+id).val();".
        "  repformat=$('#export_format_sched_'+id).val();".
        "}".
        "function setfname() {".
        "   repfilecuststr=$('#filename_custom_string').val();".
        "}".
        "function setappendpid() {".
        " if ( $('#filename_append_pid').prop('checked') == true ) { repfileappendpid=1; }".
        " if ( $('#filename_append_pid').prop('checked') == false ) { repfileappendpid=0; }".
        "}".
        "function setappendrand() {".
        " if ( $('#filename_append_rand').prop('checked') == true ) { repfileappendrand=1; }".
        " if ( $('#filename_append_rand').prop('checked') == false ) { repfileappendrand=0; }".
        "}".
        "function updateConfDisplay() {".
        " if ( repfileappenddate != 0 ) { $('#date_formats').show(); }".
        " else { $('#date_formats').hide(); }".
        " if ( repfilecuststr.length > 0 ) { $('#filename_custom_string').val(repfilecuststr); }".
        "switch (repfiletype){".
        "case 'default': ".
        "  $('#custom_attributes').hide(); $('#custom_f_string').hide(); break;".
        "case 'custom': ".
        "  $('#custom_attributes').show(); $('#custom_f_string').show(); break;".
        "default: ".
        "  $('#custom_attributes').hide(); $('#custom_f_string').hide(); break;".
        "}".
        "}".
        "$(document).ready(function() {
            updateConfDisplay();
        } );".
        "</script>";
    $html = "<div class=\"divTableSched\" style=\"width: 100%;\">".
	"<div class=\"divTableSchedBody\">".
		"<div class=\"divTableSchedRow\">".
			"<div class=\"divTableSchedCell\">Report Type and Format</div>".
			"<div class=\"divTableSchedCell\">".
                $module->get_export_types_dropdown($rpt,$export_type,"export_type_sched","schedtypeformat('".$rpt."')")." ".$module->get_export_formats_dropdown($rpt,$export_format,"export_format_sched","schedtypeformat('".$rpt."')").
            "</div>".
		"</div>".
        "<div class=\"divTableSchedRow\" id='export_file_name'>".
            "<div class=\"divTableSchedCell\">Export File Name</div>".
            "<div class=\"divTableSchedCell\">".
                "<input type=\"radio\" id=\"filename_default\" name=\"filename_default_custom\" value=\"default\" ".($filename_type == 'default' ? "checked" : "")." onchange=\"repfiletype=this.value; $('#custom_attributes').hide(); $('#custom_f_string').hide();\">".
                "<label for=\"filename_default\">Default Filename</label>"."</br>".
                "<input type=\"radio\" id=\"filename_custom\" name=\"filename_default_custom\" value=\"custom\" ".($filename_type == 'custom' ? "checked" : "")." onchange=\"repfiletype=this.value; $('#custom_attributes').show(); $('#custom_f_string').show(); \">".
                "<label for=\"filename_custom\">Custom Filename</label>".
            "</div>".
        "</div>".
        "<div class=\"divTableSchedRow\" id='custom_f_string'>".
            "<div class=\"divTableSchedCell\">Custom Filename</div>".
            "<div class=\"divTableSchedCell\">".
                "<input type=\"text\" id=\"filename_custom_string\" autocomplete=\"off\" onblur=\"setfname();\" size=\"45\"/>".
            "</div>".
        "</div>".
		"<div class=\"divTableSchedRow\" id='custom_attributes'>".
			"<div class=\"divTableSchedCell\">Custom Filename Settings</div>".
			"<div class=\"divTableSchedCell\">".
                    "<label for=\"filename_append_pid\">Append Project ID (PID)</label>".
                    "<input type=\"checkbox\" name=\"filename_append_pid\" id=\"filename_append_pid\" ".($filename_append_pid == 1 ? "checked" : "")." onchange='setappendpid();'>".
                    "<label for=\"filename_append_rand\">Append Random String</label>".
                    "<input type=\"checkbox\" name=\"filename_append_rand\" id=\"filename_append_rand\" ".($filename_append_random == 1 ? "checked" : "")." onchange='setappendrand();'>".
                    "<label for=\"filename_append_date\">Append Date/Datetime</label>".
                    "<input type=\"checkbox\" name=\"filename_append_date\" id=\"filename_append_date\" ".($filename_append_date == 1 || $filename_append_date == 2 ? "checked" : "")." onchange='show_hide_date_format();'>".
                "<div id='date_formats'>".
                    "<input type=\"radio\" id=\"filename_append_date_ymd\" name=\"filename_append_date_format\" value=\"1\" ".($filename_append_date == '1' ? "checked" : "")." onchange=\"repfileappenddate=this.value;\">".
                    "<label for=\"filename_append_date_format\">Date (".date('Y_m_d').")</label>"."</br>".
                    "<input type=\"radio\" id=\"filename_append_date_ymdis\" name=\"filename_append_date_format\" value=\"2\" ".($filename_append_date == '2' ? "checked" : "")." onchange=\"repfileappenddate=this.value;\">".
                    "<label for=\"filename_append_date_ymdis\">Datetime (".date('Y_m_d_h_i').")</label>".
                "</div>".
            "</div>".
		"</div>".
        "<div class=\"divTableSchedRow\" id='example_name'>".
            "<div class=\"divTableSchedCell\">Example Name</div>".
            "<div class=\"divTableSchedCell\">".
                "Current: <span style='font-weight: bold;'>".$module->get_report_filename($rpt, $module, $export_format)."</span></br></br> Updated Example Filename will be shown after the configuration is saved!".
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