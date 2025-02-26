<?php
namespace MGB\MGBSFTPReportExporter;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;
use DataExport;
use UserRights;

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
global $Proj, $user_rights;

//@TODO checkPrivileges
// For now the EM privileges - project design/setup can be used
//$ur = new UserRights();
//$is_user_allwoed = $ur->checkPrivileges();

// Get the DataTables included
print "<link rel=\"stylesheet\" type=\"text/css\" href=\"".$module->getUrl('lib/datatables.min.css')."\"/>";
print "<link rel=\"stylesheet\" type=\"text/css\" href=\"".$module->getUrl('lib/jstree/themes/default/style.min.css')."\" />";
print "<script type=\"text/javascript\" src=\"".$module->getUrl('lib/datatables.min.js')."\"></script>";
print "<script type=\"text/javascript\" src=\"".$module->getUrl('lib/jstree/jstree.min.js')."\"></script>";

if ( $user_rights['design'] != 1 || $user_rights['design'] != "1") {
    print "You do not have access to this page! Project Design is needed to access this!";
    exit();
}

// Display a DIV to add the new config in:
// SFTP Name
// SFTP Host
// SFTP Port
// SFTP User
// SFTP Authentication Method
// SFTP PWD
// SFTP Key
?>

    <div id="sftp_conf_result" name="sftp_conf_result" class="input-div-center" style="display: none">
        <table>
            <tr><td colspan='2' class='logt' style='padding: 15px; font-size: 26px; text-align: center;'>
                <div id="sftp_conf_result_text" name="sftp_conf_result_text" class="input-div-center">

                </div>
            </td></tr>
        </table>
    </div>
    
<h3>SFTP Report Exporter Setup</h3>
<p>
    Use this page to setup the available SFTP locations where reports can be uploaded to.
</p>
<div id="sftp_config_settings_div" style="width: 70%;">
    <h3>Add a new SFTP Configuration (click to expand)</h3>
    <div>
    <div id="sftp_result" name="sftp_result" class="input-div-center" style="display: none">
        <table>
            <tr><td colspan='2' style='padding: 15px; font-size: 26px; text-align: center; border: 1px #da963b solid;'>
                <div id="sftp_result_text" name="sftp_result_text" class="input-div-center">
SFTP Result
                </div>
            </td></tr>
        </table>
    </div>
    
        <table>
            <tbody>
            <tr>
                <th class="sftp_hdr">SFTP Name</th>
                <td><input type="text" id="sftp_name_in" autocomplete="off" size="45" onblur="$(this).removeClass('error_input')"/></td>
            </tr>
            <tr>
                <th class="sftp_hdr">SFTP Host</th>
                <td><input type="text" id="sftp_host_in" autocomplete="off" size="45" onblur="$(this).removeClass('error_input')"/></td>
            </tr>
            <tr>
                <th class="sftp_hdr">SFTP Port</th>
                <td><input type="text" id="sftp_port_in" autocomplete="off" size="45" onblur="$(this).removeClass('error_input')"/></td>
            </tr>
            <tr>
                <th class="sftp_hdr">Username</th>
                <td><input type="text" id="sftp_uname_in" autocomplete="off" size="45" onblur="$(this).removeClass('error_input')"/></td>
            </tr>
            <tr>
                <th class="sftp_hdr">Authentication Method</th>
                <td>
                    <fieldset class="auth_set">
                        <legend style="font-weight: bold; font-size: 1.2em;">Authentication method</legend>
                        <input type="hidden" id="auth_selected"/>
                        <input type="radio" id="with_pwd" name="authconf" value="1" onClick="change_sftp_auth(1)">
                        <label for="pwd">With Username and Password</label><br>
                        <input type="radio" id="with_key" name="authconf" value="2" onClick="change_sftp_auth(2)">
                        <label for="with_key">With Username and public/private keys</label><br>
                    </fieldset>
                </td>
            </tr>
            <tr id="sftp_pwd_input">
                <th class="sftp_hdr">Password</th>
                <td><input type="password" id="sftp_pwd_in" autocomplete="off" size="45" onblur="$(this).removeClass('error_input')"/></td>
            </tr>
            <tr id="sftp_key_input">
                <th class="sftp_hdr">Key</th>
                <td><textarea id="sftp_key_ta" cols="45" rows="15" onblur="$(this).removeClass('error_input')"></textarea></td>
            </tr>
            </tbody>
        </table>
        <button id='add_sftp_config' onclick='add_sftp_config()' class='btn btn-primaryrc'>Add New SFTP Config</button>
    </div>
    <h3>Add a new AWS S3 Configuration (click to expand)</h3>
    <div>
        <div id="aws_s3_result" name="aws_s3_result" class="input-div-center" style="display: none"></div>
        <table>
            <tbody>
            <tr>
                <th class="sftp_hdr">AWS S3 Name</th>
                <td><input type="text" id="aws_s3_name_in" autocomplete="off" size="45" onblur="$(this).removeClass('error_input')"/></td>
            </tr>
            <tr>
                <th class="sftp_hdr">AWS S3 Bucket</th>
                <td><input type="text" id="aws_s3_bucket_in" autocomplete="off" size="45" onblur="$(this).removeClass('error_input')"/></td>
            </tr>
            <tr>
                <th class="sftp_hdr">AWS S3 Access Key</th>
                <td><input type="password" id="aws_s3_key_in" autocomplete="off" size="45" onblur="$(this).removeClass('error_input')"/></td>
            </tr>
            <tr>
                <th class="sftp_hdr">AWS S3 Secret</th>
                <td><input type="password" id="aws_s3_secret_in" autocomplete="off" size="45" onblur="$(this).removeClass('error_input')"/></td>
            </tr>
            <tr>
                <th class="sftp_hdr">AWS S3 Region</th>
                <td><input type="text" id="aws_s3_region_in" autocomplete="off" size="45" onblur="$(this).removeClass('error_input')"/></td>
            </tr>
            </tbody>
        </table>
        <button id='add_aws_s3_config' onclick='add_aws_s3_config()' class='btn btn-primaryrc'>Add New AWS S3 Bucket Config</button>
    </div>
    <h3>Add a new Local Storage Upload Configuration (click to expand)</h3>
    <div>
        <div id="local_storage_result" name="local_storage_result" class="input-div-center" style="display: none"></div>
        <table>
            <tbody>
            <tr>
                <th class="sftp_hdr">Local Storage Name</th>
                <td><input type="text" id="local_name_in" autocomplete="off" size="45" onblur="$(this).removeClass('error_input')"/></td>
            </tr>
            <tr>
                <th class="sftp_hdr">Path</th>
                <td><input type="text" id="local_path_in" autocomplete="off" size="45" onblur="$(this).removeClass('error_input')"/></td>
            </tr>
            </tbody>
        </table>
        <button id='add_aws_s3_config' onclick='add_local_storage_config()' class='btn btn-primaryrc'>Add New Local Storage Path Config</button>
    </div>
</div>
<p></p>
<h3>Existing Configs Listing</h3>
<div id="sftp_existing_configs" style="width: 90%">
    <table id="sftp_conf_list" class="display" style="width:90%">
        <thead>
        <tr>
            <th>SFTP Name</th>
            <th>SFTP Host</th>
            <th>SFTP Port</th>
            <th>SFTP Username</th>
            <th>Auth Method</th>
            <th>Other Actions</th>
        </tr>
        </thead>
    </table>
</div>

    <script type="text/javascript" >
        var sftp_dt_table;
        $(document).ready(function() {
            $( "#sftp_config_settings_div" ).accordion({
                collapsible: true,
                heightStyle: "content"
            });
            $('#sftp_pwd_input').hide();
            $('#sftp_key_input').hide();

            sftp_dt_table = $('#sftp_conf_list').DataTable( {
                "ajax": "<?php print $module->getUrl('mgb_recall_sftp_setup_ajax.php'); ?>"
            } );
        } );

        function change_sftp_auth(authtype) {
            if ( authtype == 1) {
                $('#sftp_pwd_input').show();
                $('#sftp_key_input').hide();
                $('#with_pwd').removeClass("error_input");
                $('#with_key').removeClass("error_input");
                $('#sftp_key_ta').val(""); // empty the key
                $('#auth_selected').val(authtype);
            }
            if (authtype == 2) {
                sftp_pwd_in
                $('#sftp_pwd_input').hide();
                $('#sftp_key_input').show();
                $('#sftp_pwd_in').val(""); // empty the key
                $('#with_pwd').removeClass("error_input");
                $('#with_key').removeClass("error_input");
                $('#auth_selected').val(authtype);
            }
        }

        function add_sftp_config () {
            // Check some fields
            var errfound = false;
            if ( !$('#sftp_name_in').val()) {
                errfound = true;
                $('#sftp_name_in').addClass("error_input");
            }
            if ( !$('#sftp_host_in').val()) {
                errfound = true;
                $('#sftp_host_in').addClass("error_input");
            }
            if ( !$('#sftp_port_in').val()) {
                errfound = true;
                $('#sftp_port_in').addClass("error_input");
            }
            if ( !$('#sftp_uname_in').val()) {
                errfound = true;
                $('#sftp_uname_in').addClass("error_input");
            }
            if ( !$('#auth_selected').val() ) {
                errfound = true;
                $('#with_pwd').addClass("error_input");
                $('#with_key').addClass("error_input");
            }
            else {
                if ( $('#auth_selected').val() == 1 ) {
                    if ( !$('#sftp_pwd_in').val()) {
                        errfound = true;
                        $('#sftp_pwd_in').addClass("error_input");
                    }
                }
                if ( $('#auth_selected').val() == 2 ) {
                    if ( !$('#sftp_key_ta').val()) {
                        errfound = true;
                        $('#sftp_key_ta').addClass("error_input");
                    }
                }
            }

            if ( !errfound ) {
                // Post to the service
                $("#sftp_result_text").html("");
                $("#sftp_result").css("display","none");

                $.post( "<?php print $module->getUrl('mgb_setup_sftp_ajax.php'); ?>", {
                    type: 'sftp',
                    name: $('#sftp_name_in').val().trim(),
                    host: $('#sftp_host_in').val().trim(),
                    port: $('#sftp_port_in').val().trim(),
                    uname: $('#sftp_uname_in').val().trim(),
                    auth: $('#auth_selected').val().trim(),
                    pwd: $('#sftp_pwd_in').val().trim(),
                    key: $('#sftp_key_ta').val().trim()
                }, function ( data ) {
                    $( "#sftp_config_settings_div" ).accordion( "option", "active", false );
                    sftp_dt_table.ajax.reload();

                    $("#sftp_conf_result_text").html("Config OK");
                    $("#sftp_conf_result").css("display","block");
                });
            }
            else {
                $("#sftp_conf_result_text").html("Config OK");
                $("#sftp_result").css("display","block");
            }
        }

        function delete_sft_config ( num ) {
            $.post( "<?php print $module->getUrl('mgb_setup_sftp_rm_ajax.php'); ?>", {
                cfg: num
            }, function ( data ) {
                $("#sftp_conf_result_text").html("Config Deleted");
                $("#sftp_conf_result").css("display","block");

                sftp_dt_table.ajax.reload();
            });
        }

        function select_sftp_remote_location( num ) {
            $('#sftp_folder_cfg_'+num).prop("disabled",true);
            $("#sftp_conf_result").css("display","none");
            $('#sftp_folder_cfg_'+num).html("Loading ... ");
            $.post("<?php print $module->getUrl('mgb_setup_sftp_location_ajax.php'); ?>", {
                cfg: num
            },function(data){
                var json_data = jQuery.parseJSON(data);
                if (json_data.length < 1) {
                    alert(woops);
                    return false;
                }
                $('#sftp_folder_cfg_'+num).html("Set Target Folder");
                simpleDialog(json_data.content,json_data.title,"set_folder_location",400,"$('#sftp_folder_cfg_'+cfgnum).prop(\"disabled\",false);","Cancel","savepathdata( $('#selected_path').val(), cfgnum );","Save");
            });
        }

        function savepathdata( path, num ) {
            $('#sftp_folder_cfg_'+num).prop("disabled",false);
            $.post("<?php print $module->getUrl('mgb_setup_sftp_location_ajax.php'); ?>", {
                cfg: num,
                rmt_path: path
            },function(data){
                $("#sftp_conf_result_text").html("Target Folder Set OK!");
                $("#sftp_conf_result").css("display","block");

                sftp_dt_table.ajax.reload();
            });
        }

        function add_aws_s3_config( ) {
            // Check some fields
            var errfound = false;
            if ( !$('#aws_s3_name_in').val()) {
                errfound = true;
                $('#aws_s3_name_in').addClass("error_input");
            }
            if ( !$('#aws_s3_bucket_in').val()) {
                errfound = true;
                $('#aws_s3_bucket_in').addClass("error_input");
            }
            if ( !$('#aws_s3_key_in').val()) {
                errfound = true;
                $('#aws_s3_key_in').addClass("error_input");
            }
            if ( !$('#aws_s3_secret_in').val()) {
                errfound = true;
                $('#aws_s3_secret_in').addClass("error_input");
            }
            if ( !$('#aws_s3_region_in').val()) {
                errfound = true;
                $('#aws_s3_region_in').addClass("error_input");
            }

            if ( !errfound ) {
                // Post to the service
                $("#aws_s3_result").html("");
                $("#aws_s3_result").css("display","none");

                $.post( "<?php print $module->getUrl('mgb_setup_sftp_ajax.php'); ?>", {
                    type: 's3',
                    name: $('#aws_s3_name_in').val().trim(),
                    bucket: $('#aws_s3_bucket_in').val().trim(),
                    pwd: $('#aws_s3_key_in').val().trim(),
                    key: $('#aws_s3_secret_in').val().trim(),
                    region: $('#aws_s3_region_in').val().trim(),
                }, function ( data ) {
                    $( "#sftp_config_settings_div" ).accordion( "option", "active", false );
                    sftp_dt_table.ajax.reload();
                    htmltext = "<table>";
                    htmltext += "<tr><td colspan='2' class='logt' style='padding: 15px; font-size: 26px; text-align: center;'>S3 Config OK</td></tr>";
                    htmltext += "</table>";

                    $("#aws_s3_result").html(escapeHtml(htmltext));
                    $("#aws_s3_result").css("display","block");
                });
            }
            else {
                htmltext = "<table>";
                htmltext += "<tr><td colspan='2' style='padding: 15px; font-size: 26px; text-align: center; border: 1px #da963b solid;'>Please Check the S3 Bucket values provided!</td></tr>";
                htmltext += "</table>";

                $("#aws_s3_result").html(escapeHtml(htmltext));
                $("#aws_s3_result").css("display","block");
            }
        }

        function add_local_storage_config () {
            // Check some fields
            var errfound = false;
            if ( !$('#local_name_in').val()) {
                errfound = true;
                $('#local_name_in').addClass("error_input");
            }
            if ( !$('#local_path_in').val()) {
                errfound = true;
                $('#local_path_in').addClass("error_input");
            }

            if ( !errfound ) {
                // Post to the service
                $("#aws_s3_result").html("");
                $("#aws_s3_result").css("display","none");

                $.post( "<?php print $module->getUrl('mgb_setup_sftp_ajax.php'); ?>", {
                    type: 'local',
                    name: $('#local_name_in').val().trim(),
                    path: $('#local_path_in').val().trim(),
                }, function ( data ) {
                    $( "#sftp_config_settings_div" ).accordion( "option", "active", false );
                    sftp_dt_table.ajax.reload();
                    htmltext = "<table>";
                    htmltext += "<tr><td colspan='2' class='logt' style='padding: 15px; font-size: 26px; text-align: center;'>S3 Config OK</td></tr>";
                    htmltext += "</table>";

                    $("#local_storage_result").html(escapeHtml(htmltext));
                    $("#local_storage_result").css("display","block");
                });
            }
            else {
                htmltext = "<table>";
                htmltext += "<tr><td colspan='2' style='padding: 15px; font-size: 26px; text-align: center; border: 1px #da963b solid;'>Please Check the S3 Bucket values provided!</td></tr>";
                htmltext += "</table>";

                $("#local_storage_result").html(escapeHtml(htmltext));
                $("#local_storage_result").css("display","block");
            }
        }
        
        function escapeHtml(unsafe) {
            return unsafe;
            //    .replace(/&/g, "&amp;")
            //    .replace(/</g, "&lt;")
            //    .replace(/>/g, "&gt;")
            //    .replace(/"/g, "&quot;")
            //    .replace(/'/g, "&#039;");
        }
            
    </script>
<style>
    .auth_set{
        border: 1px #5f7f3e solid;
        padding: 20px;
    }
    .sftp_hdr {
        padding: 10px;
    }
    .error_input {
        outline: 3px dashed red;
    }
</style>

<?php
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';