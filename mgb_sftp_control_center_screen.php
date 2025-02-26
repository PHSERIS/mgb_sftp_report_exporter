<?php
namespace MGB\MGBSFTPReportExporter;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;
use Project;
use DataExport;

require_once APP_PATH_DOCROOT . 'ControlCenter/header.php';
include APP_PATH_VIEWS . 'HomeTabs.php';

print "<link rel=\"stylesheet\" type=\"text/css\" href=\"".$module->getUrl('lib/datatables.min.css')."\"/>";
print "<script type=\"text/javascript\" src=\"".$module->getUrl('lib/datatables.min.js')."\"></script>";

$debug_cron = $module->getSystemSetting('sftp-cron-debug');
if ( !isset($debug_cron) || is_null($debug_cron) || strlen(trim($debug_cron))<1 ) {
    $module->setSystemSetting('sftp-cron-debug',0); // Initiate the setting to 0 - debug disabled
}
else {
    if ( $debug_cron == 1 || $debug_cron == '1')
        $debug_cron = true;
    else
        $debug_cron = false;
}

?>

    <style>
        .switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cd0a04;
            -webkit-transition: .4s;
            transition: .4s;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            -webkit-transition: .4s;
            transition: .4s;
        }

        input:checked + .slider {
            background-color: #3ecd04;
        }

        input:focus + .slider {
            box-shadow: 0 0 1px #2196F3;
        }

        input:checked + .slider:before {
            -webkit-transform: translateX(26px);
            -ms-transform: translateX(26px);
            transform: translateX(26px);
        }

        /* Rounded sliders */
        .slider.round {
            border-radius: 34px;
        }

        .slider.round:before {
            border-radius: 50%;
        }
    </style>

    <div id="OLD_sftp_result" name="OLD_sftp_result" class="input-div-center" style="display: none"></div>

    <div id="sftp_result" name="sftp_result" class="input-div-center" style="display: none">
        <table>
            <tr>
                <td colspan="2" class="logt" style="padding: 15px; font-size: 26px; text-align: center;">
                    <div id="sftp_result_text" name="sftp_result_text" class="input-div-center"></div>
                </td>
            </tr>
        </table>

    </div>
    <div class='yellow repo-updates' style="text-align: center;">
        The list below displays all projects that have the sFTP Report Exporter module enabled.</br>
        Module statistics and actions are displayed below</br>
        This is work in progress...
    </div>

    <table>
        <tr>
            <td>Enable / Disable CRON Debugging</td>
            <td>
                <label class="switch">
                    <input id="enable_debug" type="checkbox" onchange="en_dis_debug()" <?php echo ($debug_cron == true ? 'checked' : '') ?>>
                    <span class="slider round"></span>
                </label>
            </td>
        </tr>
    </table>

    <table id="sftp_projects" class="display" style="width:95%">
    <thead>
    <tr>
        <th style="text-align: center;">Project</th>
        <th style="text-align: center;">Configured Reports</th>
        <th style="text-align: center;">Cron Status</th>
        <th style="text-align: center;">ACTIONS</th>
    </tr>
    </thead>
    <tbody>

    <?php
$framework = \ExternalModules\ExternalModules::getFrameworkInstance($module->PREFIX);
$projects = $framework->getProjectsWithModuleEnabled();
//}
//else
//    $projects = [$single_pid];

if (count($projects) > 0) {
    foreach ($projects as $project_id) {
        $this_project = new Project($project_id);
        $run_all_scheduled_crons_button = "<button id='sched_upload_now_btn_".$project_id."' onclick=\"uploadScheduledJobsNow('".$project_id."')\" class=\"btn btn-primaryrc \">Run All Scheduled Uploads</button>";
        print "<tr>
                <td style='text-align: center;'>
                    <a style='font-width: bold; font-size: 1.5em' href=\"".APP_PATH_WEBROOT."index.php?pid=".$project_id."\">".decode_filter_tags($this_project->project['app_title'])."</a></td>
                <td style='text-align: center;'>REPORTS (WIP)</td>
                <td style='text-align: center;'>CRON STATUS (WIP)</td>
                <td style='text-align: center;'>".$run_all_scheduled_crons_button."</td>
        </tr>";
    }
}
?>

    </tbody>
</table>

    <script type="text/javascript" >
        $(document).ready(function() {
            $('#sftp_projects').DataTable();
        } );

    </script>

<script type="text/javascript">

    function uploadScheduledJobsNow(prid) {
        $('#sched_upload_now_btn_'+prid).prop("disabled",true);
        $('#sched_upload_now_btn_'+prid).html("<i class=\"fa fa-spinner fa-spin\"></i> Run All Scheduled Uploads");
        $.get("<?php print \ExternalModules\ExternalModules::getUrl($module->PREFIX, 'mgb_sftp_cron.php'); ?>&pid="+prid+"&NOAUTH", {
            force: 1
        },function(data){
            var json_data = jQuery.parseJSON(data);
            //htmltext = "<table>";
            //htmltext += "<tr><td colspan='2' class='logt' style='padding: 15px; font-size: 26px; text-align: center;'>"+json_data.status_message+"</td></tr>";
            //htmltext += "</table>";
            
            //$("#sftp_result").html(htmltext);
            $("#json_data.status_message").html(json_data.status_message);
            //$("#json_data.status_message").html("test json message here");
            $("#sftp_result").css("display","block");

            $('#sched_upload_now_btn_'+prid).prop("disabled",false);
            $('#sched_upload_now_btn_'+prid).html("Run All Scheduled Uploads");
        });
    }

    function en_dis_debug () {
        $.post( "<?php print $module->getUrl('mgb_sftp_control_center_ajax.php'); ?>", {
            cron_debug: $('#enable_debug').prop('checked') == true ? 1 : 0,
        }, function ( data ) {

        });
    }
</script>

<?php
require_once APP_PATH_DOCROOT . 'ControlCenter/footer.php';

