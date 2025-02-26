<?php
namespace MGB\MGBSFTPReportExporter;
/**
 * Manage the module configs
 * Set the remote folder location
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

// Get the config number that we want to nix
if ( !isset($_POST['cfg']) || !is_numeric($_POST['cfg']) ) exit('[]');
$cfg = trim(strip_tags(html_entity_decode($_POST['cfg'], ENT_QUOTES)));

$cfg = trim(strip_tags(html_entity_decode($_POST['cfg'], ENT_QUOTES)));

$target_sites       = $module->getProjectSetting('sftp-sites');
$target_names       = $module->getProjectSetting('sftp-site-name');
$target_hosts       = $module->getProjectSetting('sftp-site-host');
$target_ports       = $module->getProjectSetting('sftp-site-port');
$target_users       = $module->getProjectSetting('sftp-site-user');
$target_pwds        = $module->getProjectSetting('sftp-site-pwd');
$target_pkis        = $module->getProjectSetting('sftp-site-pk');
$target_auth        = $module->getProjectSetting('sftp-site-auth-method'); // 1=PWD, 2=Key
$target_folders     = $module->getProjectSetting('sftp-site-folder');

if ( isset($_POST['rmt_path']) && !is_null($_POST['rmt_path']) ) {
    $rl = trim(strip_tags(html_entity_decode($_POST['rmt_path'], ENT_QUOTES)));

    // This is a postback to save the location
    $target_folders[$cfg] = $rl;
    $module->setProjectSetting('sftp-site-folder', $target_folders);

    $result = [
        'status' => 'OK',
        'status_message' => 'Remote location setting saved!',
    ];
    print json_encode($result);
    exit();
}
else {
    // Login and get a folder listing
    $config = array(
        'report_name'   => "",
        'conf_name'     => $target_names[$cfg],
        'auth_method'   => $target_auth[$cfg] == 1 ? 'basic' : 'key',
        'host'          => $target_hosts[$cfg],
        'port'          => $target_ports[$cfg],
        'user'          => $target_users[$cfg],
        'pwd'           => (isset($target_pwds[$cfg]) && strlen($target_pwds[$cfg])>0) ? $target_pwds[$cfg] : "",
        // This is the private key!!!
        'key'           => (isset($target_pkis[$cfg]) && strlen($target_pkis[$cfg])>0) ? $target_pkis[$cfg] : "",
    );

    $listing_result = $module->sftp_get_folder_listing( $config );
    $list = "";

    if ( $listing_result['status'] == "OK") {
        $list = $listing_result['listing'];
    }
    
    if (!$list) {
        print json_encode('');  // we have no list and feeding back empty is better than falling through here
        exit();
    }

    // This means that we want to return the form with all folders listed
    $content = "<input type='hidden' id='selected_path'/><div id=\"jstree_sftp_div_$cfg\"></div>";
    //$content.= "<link rel=\"stylesheet\" type=\"text/css\" href=\"".$module->getUrl('lib/jstree/themes/default/style.min.css')."\" />";
    //$content.= "<script type=\"text/javascript\" src=\"".$module->getUrl('lib/jstree/jstree.min.js')."\"></script>";
    $content.= "<script type=\"text/javascript\" >";
    $content.= "var cfgnum=".$cfg.";";
    $content.= " $(function() {";
    $content.= "$('#jstree_sftp_div_$cfg').on('changed.jstree', function (e, data) { $('#selected_path').val(data.selected[0]);}).jstree({ 
        'core' : {
            'data' : ".$list."
        }
    });";
    $content.="} );";
    $content.="</script>";

    $result = [
        'status'    => 'OK',
        'content'   => $content,
        'title'     => "Folder Listing for ".trim(strip_tags(html_entity_decode($target_names[$cfg], ENT_QUOTES))),
        'num'       => $cfg,
    ];
    print json_encode($this->escape($result));
    //print $this->escape(json_encode($result)); // TODO: Psalm wants to do this.  Is it safe to do so?
    exit();
}