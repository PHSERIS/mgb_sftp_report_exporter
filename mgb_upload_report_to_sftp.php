<?php
namespace MGB\MGBSFTPReportExporter;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;
use DataExport;

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
global $Proj;

// Present a table with all of the reports (that this user has access to)
// Report Name, Export To button dropdown
// get a list of the reports in the project
$reports = DataExport::getReportNames(null, $applyUserAccess=true);

// Get the DataTables included
print "<link rel=\"stylesheet\" type=\"text/css\" href=\"".$module->getUrl('lib/datatables.min.css')."\"/>";
print "<script type=\"text/javascript\" src=\"".$module->getUrl('lib/datatables.min.js')."\"></script>";
?>
    <div id="sftp_result" name="sftp_result" class="input-div-center" style="display: none">

    </div>
<table id="project_reports" class="display" style="width:95%">
        <thead>
        <tr>
            <th style="text-align: center;">Report Name</th>
            <th style="text-align: center;">Report Settings</th>
            <th style="text-align: center;">Action</th>
        </tr>
        </thead>
    <tbody>

    <?php

    $target_site_names  = $module->getProjectSetting('sftp-site-name');
    $target_sites       = $module->getProjectSetting('sftp-sites');

    // All Data
    $sftp_locations_buttons = "<div class=\"btn-group nowrap\">"
        ."<button id='site_0_btn' onclick=\"displaySFTPDestinations('site_0_div')\" class=\"btn btn-primaryrc\">Upload All Data now</button>"
        ."<div class=\"dropdown-menu\" id='site_0_div'>";
    $sftp_schedule_buttons = "<div class=\"btn-group nowrap\">"
        ."<button id='sched_0_btn' onclick=\"displaySFTPDestinations('sched_0_div')\" class=\"btn btn-info\">Schedule Upload</button>"
        ."<div class=\"dropdown-menu\" id='sched_0_div'>";
    foreach ( $target_sites as $k => $site_enabled ) {
        if ( $site_enabled ) {
            $sftp_locations_buttons .= "<a class=\"dropdown-item\" style='padding: 10px;' href=\"javascript:;\" id=\"sftp_0_".$k."\" onclick=\"sendToSFTP(0,".$k.",'site_0_div','site_0_btn');return false;\">to ".$target_site_names[$k]."</a>";
            $sftp_schedule_buttons .= "<a class=\"dropdown-item\" style='padding: 10px;' href=\"javascript:;\" id=\"sched_0_".$k."\" onclick=\"setup_sftp_report_schedule(0,".$k.",'sched_0_div','sched_0_btn');return false;\">to ".$target_site_names[$k]."</a>";
        }
    }
    $sftp_locations_buttons.= "</div>"
        ."</div>";
    $sftp_schedule_buttons.= "</div>"
        ."</div>";

//    $configure_report_button = "<button id='rep_conf_0_btn' onclick=\"setup_sftp_report_export_format_settings(0,".$k.");return false;\" class=\"btn btn-info\"><i class=\"fas fa-cog\"></i>Configure</button>";
    $configure_report_button = "<button id='rep_conf_0_btn' onclick=\"setup_sftp_report_export_format_settings(0);return false;\" class=\"btn btn-info\"><i class=\"fas fa-cog\"></i>Configure</button>";

    print "<tr>
                <td style='text-align: center;'>
                    <a style='font-width: bold; font-size: 1.5em' href=\"#\">ALL Data</a></td>
                <td style='text-align: center;'>".$configure_report_button."</td>
                <td style='text-align: center;'>$sftp_locations_buttons". " " . "$sftp_schedule_buttons</td>
        </tr>";


    foreach ( $reports as $rep ) {
        $sftp_locations_buttons = "<div class=\"btn-group nowrap\">"
            ."<button id='site_".$rep['report_id']."_btn' onclick=\"displaySFTPDestinations('site_".$rep['report_id']."_div')\" class=\"btn btn-primaryrc\">Upload report now</button>"
            ."<div class=\"dropdown-menu\" id='site_".$rep['report_id']."_div'>";
        $sftp_schedule_buttons = "<div class=\"btn-group nowrap\">"
            ."<button id='sched_".$rep['report_id']."_btn' onclick=\"displaySFTPDestinations('sched_".$rep['report_id']."_div')\" class=\"btn btn-info\">Schedule Upload</button>"
            ."<div class=\"dropdown-menu\" id='sched_".$rep['report_id']."_div'>";
        foreach ( $target_sites as $k => $site_enabled ) {
            if ( $site_enabled ) {
                $sftp_locations_buttons .= "<a class=\"dropdown-item\" style='padding: 10px;' href=\"javascript:;\" id=\"sftp_".$rep['report_id']."_".$k."\" onclick=\"sendToSFTP(".$rep['report_id'].",".$k.",'site_".$rep['report_id']."_div','site_".$rep['report_id']."_btn');return false;\">to ".$target_site_names[$k]."</a>";
                $sftp_schedule_buttons .= "<a class=\"dropdown-item\" style='padding: 10px;' href=\"javascript:;\" id=\"sched_".$rep['report_id']."_".$k."\" onclick=\"setup_sftp_report_schedule(".$rep['report_id'].",".$k.",'sched_".$rep['report_id']."_div','sched_".$rep['report_id']."_btn');return false;\">to ".$target_site_names[$k]."</a>";
            }
        }
        $sftp_locations_buttons.= "</div>"
            ."</div>";
        $sftp_schedule_buttons.= "</div>"
            ."</div>";

        //$configure_report_button = "<button id='rep_conf_0_btn' onclick=\"setup_sftp_report_export_format_settings(".$rep['report_id'].",".$k.");return false;\" class=\"btn btn-info\"><i class=\"fas fa-cog\"></i>Configure</button>";
        $configure_report_button = "<button id='rep_conf_0_btn' onclick=\"setup_sftp_report_export_format_settings(".$rep['report_id'].");return false;\" class=\"btn btn-info\"><i class=\"fas fa-cog\"></i>Configure</button>";

        print "<tr>
                <td style='text-align: center;'>
                    <a style='font-width: bold; font-size: 1.5em' href=\"".APP_PATH_WEBROOT."DataExport/index.php?pid=".$Proj->project_id."&report_id=".$rep['report_id']."\">".$rep['title']."</a></td>
                <td style='text-align: center;'>".$configure_report_button."</td>
                <td style='text-align: center;'>$sftp_locations_buttons". " " . "$sftp_schedule_buttons</td>
        </tr>";
    }

    $sftp_dd_locations_buttons = "<div class=\"btn-group nowrap\">"
    ."<button id='dd_upload_btn' onclick=\"displaySFTPDestinations('dd_upload_div')\" class=\"btn btn-primaryrc\">Upload Data Dictionary to ...</button>"
    ."<div class=\"dropdown-menu\" id='dd_upload_div'>";

        foreach ( $target_sites as $k => $site_enabled ) {
            if ( $site_enabled ) {
                $sftp_dd_locations_buttons .= "<a class=\"dropdown-item\" style='padding: 10px;' href=\"javascript:;\" id=\"sftp_dd\" onclick=\"sendToSFTP_DD(".$k.",'dd_upload_div','dd_upload_btn');return false;\">Send Data Dictionary to ".$target_site_names[$k]."</a>";
            }
        }
        $sftp_dd_locations_buttons.= "</div>"
    ."</div>";

/**
 * REMOVED - Actually moved to the control center
 *        $run_all_scheduled_crons_button = "<button id='sched_upload_now_btn' onclick=\"uploadScheduledJobsNow()\" class=\"btn btn-primaryrc \">Run All Scheduled Uploads</button>";
 */
        print "<thead><tr>
                   <td colspan=\"3\" style='text-align: center; font-width: bold; font-size: 2.3em;'>Other Functionality</td> 
                </tr></thead>";
    // Add in the Data Dictionary Export
    print "<tr>
            <td style='font-width: bold; font-size: 1.5em; text-align: center;'>Data Dictionary</td>
            <td style='text-align: center;'>CSV</td>
            <td style='text-align: center;'>$sftp_dd_locations_buttons</td>
        </tr>";
/**
 * REMOVED - Actually moved to the control center
 *    print "<tr>
            <td style='font-width: bold; font-size: 1.5em; text-align: center;'>Manually Run All Scheduled Uploads</td>
            <td style='text-align: center; font-style: italic; font-size: 12px;'>Force all scheduled reportes to be run and uploaded NOW</br>(regardless of their schedule)</br>NOTE: This does not alter the established schedule</td>
            <td style='text-align: center;'>$run_all_scheduled_crons_button</td>
        </tr>";
 */
    ?>
    </tbody>
</table>

<script type="text/javascript" >
    $(document).ready(function() {
        $('#project_reports').DataTable();
    } );

    function displaySFTPDestinations(tdiv) {
        document.getElementById(tdiv).classList.toggle("show");
    }

    function sendToSFTP(rep_id,config_id,tdiv,tbtn) {
        //var repformat = $('#export_format_'+rep_id).val();
        //if ( !repformat || repformat == '' ) repformat = 'csv';
        //var reptype   = $('#export_type_'+rep_id).val();
        //if ( !reptype || reptype == '' ) reptype = 'flat';

        document.getElementById(tdiv).classList.toggle("show");
        document.getElementById(tbtn).disabled = true;
        //document.getElementById('export_format_'+rep_id).disabled = true;
        //document.getElementById('export_type_'+rep_id).disabled = true;

        $("#sftp_result").html("");
        $("#sftp_result").css("display","block");

        <?php
        print "
            $.post('".$module->getUrl('mgb_upload_report_to_sftp_ajax.php')."&report_id='+rep_id+'&cfg='+config_id,{}, function ( data ) {
                    data = jQuery.parseJSON(data);
                    if ( typeof data.status !== 'undefined' ) {
                        if ( data.status == \"ERROR\" ) {
                            // Regular Error
                            htmltext = \"<table>\";
                            htmltext += \"<tr><td colspan='2' class='logt' style='padding: 15px; font-size: 26px; text-align: center;'>\"+data.status_message+\"</td></tr>\";
                            htmltext += \"</table>\";
                            
                            $(\"#sftp_result\").html(htmltext);
                            $(\"#sftp_result\").css(\"display\",\"block\");
                            $(\"#\"+tbtn).prop(\"disabled\", false);
                            //$(\"#export_format_\"+rep_id).prop(\"disabled\", false);
                            //$(\"#export_type_\"+rep_id).prop(\"disabled\", false);
                        }
                        else {
                            if ( data.status == \"OK\" ) {
                                // All Good!
                                htmltext = \"<table>\";
                                htmltext += \"<tr><td colspan='2' class='logt' style='padding: 15px; font-size: 26px; text-align: center;'>\"+data.status_message+\"</td></tr>\";
                                htmltext += \"</table>\";
                                
                                $(\"#sftp_result\").html(htmltext);
                                $(\"#sftp_result\").css(\"display\",\"block\");
                                $(\"#\"+tbtn).prop(\"disabled\", false);
                                //$(\"#export_format_\"+rep_id).prop(\"disabled\", false);
                                //$(\"#export_type_\"+rep_id).prop(\"disabled\", false);
                            }
                            else {
                                // We should not get here
                                htmltext = \"<table>\";
                                htmltext += \"<tr><td colspan='2' class='logt' style='padding: 15px; font-size: 26px; text-align: center;'>\"+data.status_message+\"</td></tr>\";
                                htmltext += \"</table>\";
                                
                                $(\"#sftp_result\").html(htmltext);
                                $(\"#sftp_result\").css(\"display\",\"block\");
                                $(\"#\"+tbtn).prop(\"disabled\", false);
                                //$(\"#export_format_\"+rep_id).prop(\"disabled\", false);
                                //$(\"#export_type_\"+rep_id).prop(\"disabled\", false);
                            }
                        }
                    }
                    else {
                        // There was a problem!
                        htmltext = \"<table>\";
                        htmltext += \"<tr><td colspan='2' class='logt' style='padding: 15px; font-size: 26px; text-align: center;'>ERROR - Try again or contact system administrator!</td></tr>\";
                        htmltext += \"</table>\";
                        
                        $(\"#sftp_result\").html(htmltext);
                        $(\"#sftp_result\").css(\"display\",\"block\");
                        $(\"#\"+tbtn).prop(\"disabled\", false);
                        //$(\"#export_format_\"+rep_id).prop(\"disabled\", false);
                    }
                });";
        ?>
        
    }

    function sendToSFTP_DD(config_id,tdiv,tbtn) {
        document.getElementById(tdiv).classList.toggle("show");
        document.getElementById(tbtn).disabled = true;

        $("#sftp_result").html("");
        $("#sftp_result").css("display","block");

        <?php
        print "
            $.post('".$module->getUrl('mgb_upload_dd_to_sftp_ajax.php')."&cfg='+config_id,{}, function ( data ) {
                    data = jQuery.parseJSON(data);
                    if ( typeof data.status !== 'undefined' ) {
                        if ( data.status == \"ERROR\" ) {
                            // Regular Error
                            htmltext = \"<table>\";
                            htmltext += \"<tr><td colspan='2' class='logt' style='padding: 15px; font-size: 26px; text-align: center;'>\"+data.status_message+\"</td></tr>\";
                            htmltext += \"</table>\";
                            
                            $(\"#sftp_result\").html(htmltext);
                            $(\"#sftp_result\").css(\"display\",\"block\");
                            $(\"#\"+tbtn).prop(\"disabled\", false);
                        }
                        else {
                            if ( data.status == \"OK\" ) {
                                // All Good!
                                htmltext = \"<table>\";
                                htmltext += \"<tr><td colspan='2' class='logt' style='padding: 15px; font-size: 26px; text-align: center;'>\"+data.status_message+\"</td></tr>\";
                                htmltext += \"</table>\";
                                
                                $(\"#sftp_result\").html(htmltext);
                                $(\"#sftp_result\").css(\"display\",\"block\");
                                $(\"#\"+tbtn).prop(\"disabled\", false);
                            }
                            else {
                                // We should not get here
                                htmltext = \"<table>\";
                                htmltext += \"<tr><td colspan='2' class='logt' style='padding: 15px; font-size: 26px; text-align: center;'>\"+data.status_message+\"</td></tr>\";
                                htmltext += \"</table>\";
                                
                                $(\"#sftp_result\").html(htmltext);
                                $(\"#sftp_result\").css(\"display\",\"block\");
                                $(\"#\"+tbtn).prop(\"disabled\", false);
                            }
                        }
                    }
                    else {
                        // There was a problem!
                        htmltext = \"<table>\";
                        htmltext += \"<tr><td colspan='2' class='logt' style='padding: 15px; font-size: 26px; text-align: center;'>ERROR - Try again or contact system administrator!</td></tr>\";
                        htmltext += \"</table>\";
                        
                        $(\"#sftp_result\").html(htmltext);
                        $(\"#sftp_result\").css(\"display\",\"block\");
                        $(\"#\"+tbtn).prop(\"disabled\", false);
                    }
                });";
        ?>

    }

    function setup_sftp_report_schedule ( rep_id,config_id,tdiv ) {
        document.getElementById(tdiv).classList.toggle("show");
        $('#sched_'+rep_id+'_btn').prop("disabled",true);
        $("#sftp_result").css("display","none");
        $('#sched_'+rep_id+'_btn').html("Loading ... ");
        $.post("<?php print $module->getUrl('mgb_setup_sftp_schedule_ajax.php'); ?>", {
            cfg: config_id,
            rpt: rep_id
        },function(data){
            var json_data = jQuery.parseJSON(data);
            if (json_data.length < 1) {
                alert(woops);
                return false;
            }
            $('#sched_'+rep_id+'_btn').html("Schedule Upload");
            simpleDialog(json_data.content,json_data.title,"set_schedule_frequency",700,"$('#sched_'+rptnum+'_btn').prop(\"disabled\",false);","Cancel","savescheddata( rptnum, cfgnum, rptfreq, rptwdays, rptmday, rptactive, rptdrep, rpttype, rptformat, rpthrun, rptuseexistingconfig );","Save");
        });
    }

    function savescheddata( rep_id, config_id, rptfreq, rptwdays, rptmday, rptactive, rptdrep, rpttype, rptformat, rpthrun, rptuseexistingconfig) {
        $('#sched_'+rep_id+'_btn').prop("disabled",false);
        if ( !rptformat || rptformat == '' || rptformat == 0 ) rptformat = 'csv';
        if ( !rpttype || rpttype == '' || rpttype == 0 ) rpttype = 'flat';
        $.post("<?php print $module->getUrl('mgb_setup_sftp_schedule_ajax.php'); ?>", {
            cfg: config_id,
            rpt: rep_id,
            set_sched: 1,
            freq: rptfreq,
            wdays: rptwdays,
            mday: rptmday,
            active: rptactive,
            dailyrep: rptdrep,
            rtype: rpttype,
            rformat: rptformat,
            rhrun: rpthrun,
            use_predefined_configs: rptuseexistingconfig
        },function(data){
            var json_data = jQuery.parseJSON(data);
            htmltext = "<table>";
            htmltext += "<tr><td colspan='2' class='logt' style='padding: 15px; font-size: 26px; text-align: center;'>"+json_data.status_message+"</td></tr>";
            htmltext += "</table>";

            $("#sftp_result").html(htmltext);
            $("#sftp_result").css("display","block");
        });
    }

    function setup_sftp_report_export_format_settings ( rep_id ) {
        $('#rep_conf_'+rep_id+'_btn').prop("disabled",true);
        $("#sftp_result").css("display","none");
        $('#rep_conf_'+rep_id+'_btn').html("Loading ... ");
        $.post("<?php print $module->getUrl('mgb_upload_report_configs_ajax.php'); ?>", {
            rpt: rep_id
        },function(data){
            var json_data = jQuery.parseJSON(data);
            if (json_data.length < 1) {
                alert(woops);
                $('#rep_conf_'+rep_id+'_btn').html("<i class=\"fas fa-cog\"></i>Configure");
                $('#rep_conf_'+rep_id+'_btn').prop("disabled",false);
                return false;
            }
            $('#rep_conf_'+rep_id+'_btn').html("<i class=\"fas fa-cog\"></i>Configure");
            simpleDialog(json_data.content,json_data.title,"set_report_configuration",600,"$('#rep_conf_'+rptnum+'_btn').prop(\"disabled\",false);","Cancel","saverepconfigdata( rptnum, reptype, repformat, repfiletype, repfilecuststr, repfileappendpid, repfileappendrand, repfileappenddate );","Save Configuration");
        });
    }

    function saverepconfigdata( rep_id, reptype, repformat, repfiletype, repfilecuststr, repfileappendpid, repfileappendrand, repfileappenddate ) {
        $('#rep_conf_'+rep_id+'_btn').prop("disabled",false);
        if ( !repformat || repformat == '' || repformat == 0 ) repformat = 'csvraw';
        if ( !reptype || reptype == '' || reptype == 0 ) reptype = 'flat';
        $.post("<?php print $module->getUrl('mgb_upload_report_configs_ajax.php'); ?>", {
            rpt: rep_id,
            set_config: 1,
            reptype: reptype,
            repformat: repformat,
            repfiletype: repfiletype,
            repfilecuststr: repfilecuststr,
            repfileappendpid: repfileappendpid,
            repfileappendrand: repfileappendrand,
            repfileappenddate: repfileappenddate
        },function(data){
            var json_data = jQuery.parseJSON(data);
            htmltext = "<table>";
            htmltext += "<tr><td colspan='2' class='logt' style='padding: 15px; font-size: 26px; text-align: center;'>"+json_data.status_message+"</td></tr>";
            htmltext += "</table>";

            $("#sftp_result").html(htmltext);
            $("#sftp_result").css("display","block");
        });
    }

</script>

<?php

require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
