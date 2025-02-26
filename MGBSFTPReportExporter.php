<?php
namespace MGB\MGBSFTPReportExporter;
include "vendor/autoload.php";
require_once APP_PATH_DOCROOT."Config/init_functions.php"; // just in case

use REDCap;
use Logging;
use DataExport;
use Project;
use DateTime;
use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\Hash;
use \Aws\Credentials\Credentials;
use \Aws\S3\S3Client;
use function PartnersMGB\DataRollup\array_add;
use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use HtmlPage;

class MGBSFTPReportExporter extends \ExternalModules\AbstractExternalModule
{
	const VIEW_CMD_PROJECT = 'project';
	const VIEW_CMD_CONTROL = 'control';
	const VIEW_CMD_SYSTEM  = 'system';
	const VIEW_CMD_DEFAULT = '';
	const CRON_METHOD_NAME = 'extmod_sftp_report_exporter';
	const SIZE_TOLERANCE = 30;
	const SIZE_SIGNALTYPE = true;  // true: use boolean flag, false: use number indicator
    
    private static $con_timeout = 30; // timeout
    private static $export_formats = array (
        "csvraw"  => "CSV (Raw)",
        "csvlabels"  => "CSV (Labels)",
        //"spss"  => "SPSS", // This creates more than one file
        //"sas"  => "SAS", // This creates more than one file
        //"r"  => "R", // This creates more than one file
        //"stata"  => "STATA", // This creates more than one file
        "odmraw"  => "ODM / XML (Raw)",
        "odmlabels"  => "ODM / XML (Labels)",
        "jsonraw"  => "JSON (Raw)",
        "jsonlabels"  => "JSON (Labels)"
    );

    private static $export_types = array (
        "flat"  => "Flat",
        "eav"   => "EAV"
    );

    private static $allowed_remote_types = array (
        's3'    => "AWS S3 Bucket",
        'sftp'  => "SFTP",
        'local' => "Local Storage"
    );
    
    public $remoteFileSize = 0;
    private $sftpErrorsList = array();
    private $sftpErrorLast = '';

    /**
     * @return string[]
     */
    public static function getAllowedRemoteTypes(): array
    {
        return self::$allowed_remote_types;
    }

    /**
     * @return string[]
     */
    public static function getExportFormats(): array
    {
        return self::$export_formats;
    }

    /**
     * @return string[]
     */
    public static function getExportTypes(): array
    {
        return self::$export_types;
    }

    /**
     * use the every page top to hide the module's config button
     * @param $project_id
     * @return void
     */
    function redcap_every_page_top($project_id){
        // Hide yourself from the user
        if ( strpos(strtolower(PAGE), strtolower("manager/project.php") ) !== false ) {
            $this->hide_module_config_from_user( );
        }
    }

    private function hide_module_config_from_user ( ) {
        print "<script type='text/javascript'>
              $(document).ready(function() {
                $(\"[data-module = '$this->PREFIX']\").find(\".external-modules-configure-button\").hide();      
              });
        </script>";
    }

    /**
     * @param $file_name
     * @param $file_name_with_path
     * @param $destination_system_config
     * @return bool|string
     */
    public function upload_file_to_sftp ( $file_name, $file_name_with_path, $destination_system_config ) {
        global $Proj;
        try {
            $result = array();

            // Initiate the SFTP
            $sftp = new SFTP($destination_system_config['host'], $destination_system_config['port'], $this::$con_timeout);

            if ($destination_system_config['auth_method'] == 'basic' ){
                // Login
                //$login_success = $sftp->login($destination_system_config['user'], $destination_system_config['pwd']); // Plain Text
                $login_success = $sftp->login($destination_system_config['user'], $this->decrypt_config_string($destination_system_config['pwd'], $Proj->project_id)); // Encrypted
                if (!$login_success) {
                    $result = [
                        'status' => 'ERROR',
                        'status_message' => "Unable to authenticate - please check module config!"
                    ];

                    Logging::logEvent(NULL, "", "OTHER", "", "SFTP Report Exporter - ERROR - Unable to authenticate for ".$destination_system_config['conf_name'], "SFTP Report Exporter", "", "", "", true, null, null, false);

                    return $result;
                }
            }
            else {
                // Public Key Login
                //$key = PublicKeyLoader::load($destination_system_config['key'], $password = false); // Plain Text
                $key = PublicKeyLoader::load($this->decrypt_config_string($destination_system_config['key'], $Proj->project_id), $password = false); // Encrypted
                $login_success = $sftp->login($destination_system_config['user'], $key);
                if (!$login_success) {
                    $result = [
                        'status' => 'ERROR',
                        'status_message' => "Unable to authenticate - please check module config!",
                    ];

                    Logging::logEvent(NULL, "", "OTHER", "", "SFTP Report Exporter - ERROR - Unable to authenticate for ".$destination_system_config['conf_name'], "SFTP Report Exporter", "", "", "", true, null, null, false);

                    return $result;
                }
            }

            // If there is a remote path, we need to navigate to it
            if ( isset($destination_system_config['remote_path']) && strlen($destination_system_config['remote_path']) >= 1 ) {
                $path_arr = explode("/",$destination_system_config['remote_path']);
                foreach ( $path_arr as $p ) {
                    if ( strlen(trim($p))>0 ) {
                        $sftp->chdir(trim($p)); // drill down to the folder
                    }
                }
            }

            // Put the file up on the server
            //$upload_response = $sftp->put($destination_system_config['remote_path'].$file_name, $file_name_with_path, SFTP::SOURCE_LOCAL_FILE);
            $upload_response = $sftp->put($file_name, $file_name_with_path, SFTP::SOURCE_LOCAL_FILE);

            if ( $upload_response ) {
                // File exists, but let's check again anyways
                //$file_exists = $sftp->file_exists($destination_system_config['remote_path'].$file_name) ? true : false;
                $file_exists = $sftp->file_exists($file_name) ? true : false;
                
                $file_size = 0;
                
                if ( $file_exists ) {
                    
                    // get the destination file size
                    $debug_cron = $this->getSystemSetting('sftp-cron-debug');
                    if ( $debug_cron ) {
                        $file_size = $sftp->filesize($file_name);
                        $this->remoteFileSize = $file_size;
                        $this->log('SFTP Remote File Size Target: (' . $file_size .')');
                    }
                    
                    $result = [
                        'status' => 'OK',
                        'status_message' => "UPLOAD COMPLETE - OK!",
                        'filesize' => $file_size
                    ];

                    Logging::logEvent(NULL, "", "OTHER", "",
                        "SFTP Report Exporter - Successfully uploaded report ".trim(strip_tags(html_entity_decode($destination_system_config['report_name'], ENT_QUOTES)))." to ".$destination_system_config['conf_name']." (".$destination_system_config['host'].")",
                        "SFTP Report Exporter", "", "", "", true, null, null, false);
                }
                else {
                    $result = [
                        'status' => 'ERROR',
                        'status_message' => "UPLOAD FAIL - Could not confirm that the file was uploaded!",
                        'filesize' => -1
                    ];

                    Logging::logEvent(NULL, "", "OTHER", "",
                        "SFTP Report Exporter - WARNING Could not confirm that report ".trim(strip_tags(html_entity_decode($destination_system_config['report_name'], ENT_QUOTES)))." was successfully uploaded to ".$destination_system_config['conf_name']." (".$destination_system_config['host'].")",
                        "SFTP Report Exporter", "", "", "", true, null, null, false);

                    if ( $debug_cron ) {
                        $errors    = $sftp->getErrors();  // an array
                        $errorLast = $sftp->getLastError();
                        $this->sftpErrorsList = $errors;
                        $this->sftpErrorLast  = $errorLast;
                        
                        $msg = '';
                        $msg .= 'FAIL file not exists: ';
                        $msg .= ' LAST ERROR: ';
                        $msg .= $errorLast;
                        $msg .= 'ERRORS: ';
                        $msg .= print_r($errors, true);
        
                        $this->log($msg);
                    }
                    
                }
            }
            else {
                $result = [
                    'status' => 'ERROR',
                    'status_message' => "UPLOAD FAIL - Could not upload file!",
                    'filesize' => -1
                ];

                Logging::logEvent(NULL, "", "OTHER", "",
                    "SFTP Report Exporter - ERROR Failed uploading report ".trim(strip_tags(html_entity_decode($destination_system_config['report_name'], ENT_QUOTES)))." to ".$destination_system_config['conf_name']." (".$destination_system_config['host'].")",
                    "SFTP Report Exporter", "", "", "", true, null, null, false);

                if ( $debug_cron ) {
                    $errors    = $sftp->getErrors();  // an array
                    $errorLast = $sftp->getLastError();
                    $this->sftpErrorsList = $errors;
                    $this->sftpErrorLast  = $errorLast;
                    
                    $msg = '';
                    $msg .= 'FAIL upload response: ';
                    $msg .= ' LAST ERROR: ';
                    $msg .= $errorLast;
                    $msg .= 'ERRORS: ';
                    $msg .= print_r($errors, true);
    
                    $this->log($msg);
                }

            }

            $sftp->disconnect();
            return $result;
        }
        catch ( Exception $ee ) {
            
            if ( $debug_cron ) {
                $errors    = $sftp->getErrors();  // an array
                $errorLast = $sftp->getLastError();
                $this->sftpErrorsList = $errors;
                $this->sftpErrorLast  = $errorLast;
                
                $msg = '';
                $msg .= 'FAIL exception: ';
                $msg .= ' LAST ERROR: ';
                $msg .= $errorLast;
                $msg .= 'ERRORS: ';
                $msg .= print_r($errors, true);

                $this->log($msg);
            }

            return [
                'status' => 'ERROR',
                'status_message' => "Something went wrong! Please try again or contact the site administrator!",
                'filesize' => -1
            ];

            Logging::logEvent(NULL, "", "OTHER", "",
                "SFTP Report Exporter - ERROR Exception encountered for upload - ".trim(strip_tags(html_entity_decode($destination_system_config['report_name'], ENT_QUOTES)))." to ".$destination_system_config['conf_name']." (".$destination_system_config['host'].")",
                "SFTP Report Exporter", "", "", "", true, null, null, false);
        }
    }

    /**
     * Get a listing of the folders on the remote location
     * @param $destination_system_config
     */
    public function sftp_get_folder_listing ( $destination_system_config ) {
        global $Proj;

        try {
            $result = array();

            // Initiate the SFTP
            $sftp = new SFTP($destination_system_config['host'], $destination_system_config['port'], $this::$con_timeout);

            if ($destination_system_config['auth_method'] == 'basic' ){
                // Login
                //$login_success = $sftp->login($destination_system_config['user'], $destination_system_config['pwd']); // Plain Text
                $login_success = $sftp->login($destination_system_config['user'], $this->decrypt_config_string($destination_system_config['pwd'], $Proj->project_id)); // Encrypted
                if (!$login_success) {
                    $result = [
                        'status' => 'ERROR',
                        'status_message' => "Unable to authenticate - please check module config!"
                    ];

                    Logging::logEvent(NULL, "", "OTHER", "", "SFTP Report Exporter - ERROR - Unable to authenticate for ".$destination_system_config['conf_name'], "SFTP Report Exporter", "", "", "", true, null, null, false);

                    return $result;
                }
            }
            else {
                // Public Key Login
                //$key = PublicKeyLoader::load($destination_system_config['key'], $password = false); // Plain Text
                $key = PublicKeyLoader::load($this->decrypt_config_string($destination_system_config['key'], $Proj->project_id), $password = false); // Encrypted
                $login_success = $sftp->login($destination_system_config['user'], $key);
                if (!$login_success) {
                    $result = [
                        'status' => 'ERROR',
                        'status_message' => "Unable to authenticate - please check module config!"
                    ];

                    Logging::logEvent(NULL, "", "OTHER", "", "SFTP Report Exporter - ERROR - Unable to authenticate for ".$destination_system_config['conf_name'], "SFTP Report Exporter", "", "", "", true, null, null, false);

                    return $result;
                }
            }


            //$folder_listing = $sftp->rawlist(null,true);


            $final_list = array();
            $this->get_all_folders( $sftp, "", $final_list);

            if ( $final_list ) {
                $result = [
                    'status' => 'OK',
                    'status_message' => "",
                    'listing'   => json_encode($final_list),
                ];

            }
            else {
                $result = [
                    'status' => 'ERROR',
                    'status_message' => "Unable to get folder listing on remote location"
                ];
            }

            return $result;
        }
        catch ( Exception $ee ) {
            return [
                'status' => 'ERROR',
                'status_message' => "Something went wrong! Please try again or contact the site administrator!"
            ];
        }
    }

    private function get_all_folders( $sftp, $folder, &$listing ) {
        if ( strlen($folder)>0 ) {
            $list = $sftp->rawlist($folder,false);
        }
        else {
            $list = $sftp->rawlist();
        }

        if ( $list ) {
            foreach ( $list as $item => $item_detail ) {
                if ( substr($item, 0, 1) == "." ) continue;
                if ( $item_detail['type'] == 2 ) {
                    $listing[] = array (
                        "id" => strlen($folder)>0 ? $folder."/".$item : $item,
                        "parent" => strlen($folder)>0 ? $folder : "#",
                        "text"  => $item_detail['filename']
                    );

                    $this->get_all_folders($sftp, strlen($folder)>0 ? $folder."/".$item : $item, $listing);
                }
            }
        }
    }

    /**
     * @param $file_name
     * @param $destination_system_config
     * @return array|false|int[]|mixed|string
     */
    public function stat_sftp_file ( $file_name, $destination_system_config ) {
        global $Proj;
        try {
            // Initiate the SFTP
            $sftp = new SFTP($destination_system_config['host'], $destination_system_config['port'], $this::$con_timeout);

            if ($destination_system_config['auth_method'] == 'basic' ){
                // Login
                //$sftp->login($destination_system_config['user'], $destination_system_config['pwd']); // Plain Text
                $sftp->login($destination_system_config['user'], $this->decrypt_config_string($destination_system_config['pwd'], $Proj->project_id)); // Plain Text
            }
            else {
                // Public Key Login
                //$key = PublicKeyLoader::load($destination_system_config['key'], $password = false); // Plain Text
                $key = PublicKeyLoader::load($this->decrypt_config_string($destination_system_config['key'], $Proj->project_id), $password = false); // Encrypted
                $sftp->login($destination_system_config['user'], $key);
            }

            // Stat the file
            $stat = $sftp->stat($file_name);
            return $stat;
        }
        catch ( Exception $ee ) {
            return "EXCEPTION !! Try again later or contact administrator";
        }
    }

    /**
     * Generate a random string of lenght N
     * @param int $n
     * @return string
     */
    public function get_random_string ( $n = 6 ) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $randomString = '';

        for ($i = 0; $i < $n; $i++) {
            $index = rand(0, strlen($characters) - 1);
            $randomString .= $characters[$index];
        }

        return $randomString;
    }

    /**
     * Encrypt the data and return it
     * @param $string_to_encrypt
     * @param $project_id
     * @return false|string
     */
    public function encrypt_config_string ( $string_to_encrypt, $project_id ) {
        $salt = $this->get_module_salt(); // get the salt
        $scrambled = encrypt($string_to_encrypt, $project_id.$salt);
        return $scrambled;
    }

    /**
     * Decrypt
     * @param $string_to_decrypt
     * @param $project_id
     * @return mixed
     */
    public function decrypt_config_string ( $string_to_decrypt, $project_id ) {
        $salt = $this->get_module_salt(); // get the salt
        $unscrambled = decrypt($string_to_decrypt, $project_id.$salt);
        return $unscrambled;
    }

    public function get_module_salt() {
        $em_salt = $this->getSystemSetting('sftp-salty-salt');
        if ( !isset($em_salt) || is_null($em_salt) || strlen(trim($em_salt))<1 ) {
            // need to set a new salt
            $em_salt = $this::generate_crypto_hash();
            $this->setSystemSetting('sftp-salty-salt',$em_salt);
        }
        return $em_salt;
    }

    public function set_module_salt() {
        $em_salt = $this->getSystemSetting('sftp-salty-salt');
        if ( !isset($em_salt) || is_null($em_salt) || strlen(trim($em_salt))<1 ) {
            // need to set a new salt
            $em_salt = $this::generate_crypto_hash();
            $this->setSystemSetting('sftp-salty-salt',$em_salt);
        }
        return true;
    }

    public function generate_crypto_hash ( ) {
        return hash("sha256", $this->get_random_string(32));
    }

    public function get_export_formats_dropdown ( $id, $selected = "none", $elementid = "export_format", $js = "" ) {
        $html = "<select name='$elementid"."_".$id."' id='$elementid"."_".$id."' ".($js == "" ? "" : "onchange=\"$js\"").">";
        foreach ( $this::$export_formats as $val => $label ) {
            $html .= "<option value='".trim(strip_tags(html_entity_decode($val, ENT_QUOTES)))."' ".($val == $selected ? "selected" : "").">"
                .trim(strip_tags(html_entity_decode($label, ENT_QUOTES)))."</option>";
        }
        $html .= "</select>";
        return $html;
    }

    /**
     * Return a dropdown of the export types - EAV (tall) and Flat (wide)
     * NOTE: EAV CAN ONLY work if the project's primary key is part of the report!!!! Because I don't feel like re-structuring the report output to add it in! And we need it!
     * @param $id
     * @param string $selected
     * @return string
     */
    public function get_export_types_dropdown ( $id, $selected = "none", $elementid = "export_type", $js = "" ) {
        global $Proj;

        // Get the report details and see if one of the selected fields is the project PK
        if ( $id == 0) {
            $allow_eav = true; // this is All Data
        }
        else {
            $primaryKey = $Proj->table_pk;
            $report_details = DataExport::getReports($id);
            $allow_eav = in_array($primaryKey, $report_details['fields']);
        }
        $help_message = "<a href=\"javascript:;\" class=\"help\" 
        onclick=\"simpleDialog('<h4>Export Types</h4><br><ul><li>Flat - output as one record per row [default]</li><li>EAV - output as one data point per row<ul><li>Non-longitudinal: Will have the fields - record*, field_name, value</li><li>Longitudinal: Will have the fields - record*, field_name, value, redcap_event_name</li></ul></li></ul>* <u><i>record</u></i> refers to the record ID for the project<br>**<b><u>If your report does not include the RECORD ID as one of the fields, then EAV format cannot be produced!</u></b>','<i class=\'fas fa-question\'></i>  Export Types Formats',null,600);\">?</a>";
        
        $html = $help_message." ".
            "<select name='$elementid"."_".$id."' id='$elementid"."_".$id."' ".($js == "" ? "" : "onchange=\"$js\"").">";
        foreach ( $this::$export_types as $val => $label ) {
            if ( !$allow_eav && $val == "eav") {
                $selected = "flat"; // force Flat
                continue;
            }
            $html .= "<option value='".trim(strip_tags(html_entity_decode($val, ENT_QUOTES)))."' ".($val == $selected ? "selected" : "").">"
                .trim(strip_tags(html_entity_decode($label, ENT_QUOTES)))."</option>";
        }
        $html .= "</select>";
        return $html;
    }

    /**
     * re-format the data as EAV
     * @param $output_type
     */
    public function reformat_data_as_EAV ( $data, $output_type ) {
        global $Proj;

        $result = array();

        // Does project have repeating forms or events?
        $hasRepeatingFormsEvents = $Proj->hasRepeatingFormsEvents(); // Do we have repeating forms/events
        $longitudinal = $Proj->longitudinal; // Is the project longitudinal
        $eventNames = $Proj->getUniqueEventNames();
        $primaryKey = $Proj->table_pk;
	    //$hasRepeatingForms = $Proj->hasRepeatingForms();

        foreach ( $data as $record => $event_details ) {
            foreach ( $event_details as $eid => $eid_data ) {
                if ( $eid == 'repeat_instances') {
                    // This is the repeat instances loop
                    foreach ( $eid_data as $repeat_eid => $repeat_eid_data ) {
                        foreach ( $repeat_eid_data as $repeat_instrument_name => $repeat_instance_data ) {
                            foreach ( $repeat_instance_data as $repeat_instance_number => $repeat_instance_fields ) {
                                foreach ( $repeat_instance_fields as $f => $v ) {
                                    if ( $f == $primaryKey ) continue; // skip the PK since that is just called "record"

                                    self::add_row_to_eav_array(
                                        $result,
                                        $record,
                                        ($longitudinal ? $eventNames[$repeat_eid] : NULL),
                                        $repeat_instrument_name,
                                        $repeat_instance_number,
                                        $f,
                                        $v
                                    );
                                }
                            }
                        }
                    }
                }
                else {
                    foreach ( $eid_data as $ef => $ev ) {
                        if ( $ef == $primaryKey ) continue; // skip the PK since that is just called "record"

                        self::add_row_to_eav_array(
                            $result,
                            $record,
                            ($longitudinal ? $eventNames[$eid] : NULL),
                            ($hasRepeatingFormsEvents ? "" : NULL), // these need to be in the export, but empty for this since this is not an instance
                            ($hasRepeatingFormsEvents ? "" : NULL), // these need to be in the export, but empty for this since this is not an instance
                            $ef,
                            $ev
                        );
                    }
                }
            }
        }

        switch($output_type)
        {
            case 'json':
                $result = self::eav_to_json($result);
                break;
            case 'xml':
                $result = self::eav_to_xml($result);
                break;
            case 'csv':
                $result = self::eav_to_csv($result);
                break;
        }

        return $result;
    }

    public function reformat_json_as_EAV ( $json_data, $output_type ) {
        global $Proj;

        $array_data = json_decode($json_data, true); // Force into array format

        $result = array();

        // Does project have repeating forms or events?
        $hasRepeatingFormsEvents = $Proj->hasRepeatingFormsEvents(); // Do we have repeating forms/events
        $longitudinal = $Proj->longitudinal; // Is the project longitudinal
        //$eventNames = $Proj->getUniqueEventNames();
        $primaryKey = $Proj->table_pk;
        //$hasRepeatingForms = $Proj->hasRepeatingForms();

        foreach ( $array_data as $row => $row_data ) {
            $rid    = $row_data[$primaryKey];
            $eid    = $longitudinal ? $row_data['redcap_event_name'] : NULL;
            $repf   = ($hasRepeatingFormsEvents ? $row_data['redcap_repeat_instrument'] : NULL);
            $repi   = ($hasRepeatingFormsEvents ? $row_data['redcap_repeat_instance'] : NULL);

            foreach ( $row_data as $f => $v ) {
                if ( $f == $primaryKey ) continue; // skip the PK since that is just called "record"
                if ( $f == 'redcap_event_name') continue; // skip event name
                if ( $f == 'redcap_repeat_instrument') continue; // skip repeat instrument
                if ( $f == 'redcap_repeat_instance') continue; // skip repeat instance

                self::add_row_to_eav_array(
                    $result,
                    $rid,
                    ($longitudinal ? $eid : NULL),
                    ($hasRepeatingFormsEvents ? $repf : NULL), // these need to be in the export, but empty for this since this is not an instance
                    ($hasRepeatingFormsEvents ? $repi : NULL), // these need to be in the export, but empty for this since this is not an instance
                    $f,
                    $v
                );
            }
        }

        switch($output_type)
        {
            case 'json':
                $result = self::eav_to_json($result);
                break;
            case 'xml':
                $result = self::eav_to_xml($result);
                break;
            case 'csv':
                $result = self::eav_to_csv($result);
                break;
        }

        return $result;
    }

    /**
     * Add the data in an EAV array
     * @param $final_eav_array
     * @param $record
     * @param $event
     * @param $repeat_instrument
     * @param $repeat_instance
     * @param $field
     * @param $value
     */
    public function add_row_to_eav_array( &$final_eav_array, $record, $event, $repeat_instrument, $repeat_instance, $field, $value ) {
        global $Proj;

        $temp_array = array();
        $temp_array['record']   = $record;

        if ( !is_null($event) ) {
            $temp_array['redcap_event_name']    = $event;
        }

        if ( !is_null($repeat_instrument) ) {
            $temp_array['redcap_repeat_instrument'] = $repeat_instrument;
            $temp_array['redcap_repeat_instance']   = $repeat_instance;
        }

        // Add to the end of the array
        $temp_array['field_name']   = $field;
        $temp_array['value']        = $value;

        $final_eav_array[] = $temp_array;
        unset($temp_array);
    }

    /**
     * Return the data in JSON format
     * @param $data
     * @return false|string
     */
    public function eav_to_json ( $data ) {
       return json_encode( $data );
    }

    /**
     * Return the data in XML format (this function is borrowed from api/export.php)
     * @param $data
     * @return string
     */
    public function eav_to_xml ( $data ) {
        global $Proj;

        $output = '<?xml version="1.0" encoding="UTF-8" ?>';
        $output .= "\n<records>\n";
        foreach ($data as $row)
        {
            $output .= '<item>';
            $output .= '<record>'. $row['record'] .'</record>';
            if ($Proj->longitudinal) {
                $output .= '<redcap_event_name>'. $row['redcap_event_name'] .'</redcap_event_name>';
            }
            if ($Proj->hasRepeatingFormsEvents()) {
                // If ]]> is found inside this redcap_repeat_instrument, then "escape" it (cannot really escape it but can do clever replace with "]]]]><![CDATA[>")
                if (strpos($row['redcap_repeat_instrument'], "]]>") !== false) {
                    $row['redcap_repeat_instrument'] = '<![CDATA['.str_replace("]]>", "]]]]><![CDATA[>", $row['redcap_repeat_instrument']).']]>';
                }
                $output .= '<redcap_repeat_instrument>'. $row['redcap_repeat_instrument'] .'</redcap_repeat_instrument>';
                $output .= '<redcap_repeat_instance>'.$row['redcap_repeat_instance'].'</redcap_repeat_instance>';
            }
            $output .= '<field_name>'. $row['field_name'] .'</field_name>';
            if ($row['value'] != "") {
                $row['value'] = html_entity_decode($row['value'], ENT_QUOTES);
                // If ]]> is found inside this value, then "escape" it (cannot really escape it but can do clever replace with "]]]]><![CDATA[>")
                if (strpos($row['value'], "]]>") !== false) {
                    $row['value'] = str_replace("]]>", "]]]]><![CDATA[>", $row['value']);
                }
                $output .= '<value><![CDATA['. $row['value'] .']]></value>';
            } else {
                $output .= '<value></value>';
            }
            $output .= "</item>\n";
        }
        $output .= "</records>\n";

        return $output;
    }

    /**
     * Establish an S3 connection
     * @param $destination_system_config
     * @return false|Aws\S3\S3Client
     */
    public function getS3Client( $destination_system_config ) {
        global $Proj;
        try {
            $credentials = new \Aws\Credentials\Credentials(
                $this->decrypt_config_string($destination_system_config['aws_s3_key'], $Proj->project_id),
                $this->decrypt_config_string($destination_system_config['aws_s3_secret'], $Proj->project_id));
            $s3 = new \Aws\S3\S3Client(
                array(
                    'version'=>'latest',
                    'region'=>isset($destination_system_config['aws_s3_region']) ? $destination_system_config['aws_s3_region'] : 'us-east-1',
                    'credentials'=>$credentials
                )
            );
            return $s3;
        } catch (\Aws\S3\Exception\S3Exception $e) {
            // Failed
            return false;
        }
    }

    /**
     * Upload a file to an S3 Bucket
     * @param $file_name
     * @param $file_name_with_path
     * @param $destination_system_config
     * @return string[]
     */
    public function upload_file_to_s3_bucket( $file_name, $file_name_with_path, $destination_system_config ) {
        global $Proj;
        try {
            $result = array();

            // Initiate the S3
            $s3 = $this->getS3Client($destination_system_config);

            if (!$s3) {
                $result = [
                    'status' => 'ERROR',
                    'status_message' => "Unable to authenticate - please check module config!",
                ];

                Logging::logEvent(NULL, "", "OTHER", "", "SFTP Report Exporter - ERROR - Unable to authenticate for ".$destination_system_config['conf_name'], "SFTP Report Exporter", "", "", "", true, null, null, false);

                return $result;
            }
            else {
                $upload_response = $s3->putObject(
                    array(
                        'Bucket'=>$destination_system_config['aws_s3_bucket'],
                        'Key'=>$file_name,
                        'Body'=>file_get_contents($file_name_with_path),
                        'ACL'=>'private'
                    )
                );

                if ( $upload_response ) {
                    // This is an AWS\Result object
                    $aws_result = $upload_response->get('@metadata');
                    if ( $aws_result['statusCode'] == 200 ) {
                        // OK
                        $result = [
                            'status' => 'OK',
                            'status_message' => "UPLOAD COMPLETE - OK!",
                        ];

                        Logging::logEvent(NULL, "", "OTHER", "",
                            "SFTP Report Exporter - Successfully uploaded report ".trim(strip_tags(html_entity_decode($destination_system_config['report_name'], ENT_QUOTES)))." to ".$destination_system_config['conf_name']." (".$destination_system_config['host'].")",
                            "SFTP Report Exporter", "", "", "", true, null, null, false);
                    }
                    else {
                        // not OK
                        $result = [
                            'status' => 'ERROR',
                            'status_message' => "UPLOAD FAIL - Could not confirm that the file was uploaded!"
                        ];

                        Logging::logEvent(NULL, "", "OTHER", "",
                            "SFTP Report Exporter - WARNING Could not confirm that report ".trim(strip_tags(html_entity_decode($destination_system_config['report_name'], ENT_QUOTES)))." was successfully uploaded to ".$destination_system_config['conf_name']." (".$destination_system_config['host'].")",
                            "SFTP Report Exporter", "", "", "", true, null, null, false);
                    }
                }
                else {
                    // Upload fail
                    $result = [
                        'status' => 'ERROR',
                        'status_message' => "UPLOAD FAIL - Could not upload file"
                    ];

                    Logging::logEvent(NULL, "", "OTHER", "",
                        "SFTP Report Exporter - ERROR Failed uploading report ".trim(strip_tags(html_entity_decode($destination_system_config['report_name'], ENT_QUOTES)))." to ".$destination_system_config['conf_name']." (".$destination_system_config['host'].")",
                        "SFTP Report Exporter", "", "", "", true, null, null, false);
                }
            }

            return $result;
        }
        catch ( Exception $ee ) {
            return [
                'status' => 'ERROR',
                'status_message' => "Something went wrong! Please try again or contact the site administrator!"
            ];

            Logging::logEvent(NULL, "", "OTHER", "",
                "SFTP Report Exporter - ERROR Exception encountered for upload - ".trim(strip_tags(html_entity_decode($destination_system_config['report_name'], ENT_QUOTES)))." to ".$destination_system_config['conf_name']." (".$destination_system_config['host'].")",
                "SFTP Report Exporter", "", "", "", true, null, null, false);
        }
    }

    /**
     * CRON
     * @param $cronInfo
     * @return void
     */
    public function run_sftp_cron ($cronInfo)
    {
        // Sleep for a random 5 to 20 seconds
        $rand_sec = random_int(5,30);
        sleep($rand_sec); // sleep for a random 5 to 20 seconds

        $my_hash = $this->get_random_string(8); // Generate a random hash
        $run_start_date = new DateTime();

        // When you wake up, check to see if there is another instance running
        // Get the hash of the cron that may be currently running
        $currently_running_cron = $this->get_currently_running_cron_info();

        $running_cron_hash = $currently_running_cron['sftp-cron-hash'];
        $last_cron_run_timestamp = $currently_running_cron['sftp-cron-last-finish'];
        $seconds_since_last_run = abs($run_start_date->getTimestamp() - (int)$last_cron_run_timestamp);

        $debug_cron = $this->getSystemSetting('sftp-cron-debug');
        if ( !isset($debug_cron) || is_null($debug_cron) || strlen(trim($debug_cron))<1 ) {
            $this->setSystemSetting('sftp-cron-debug',0); // Initiate the setting to 0 - debug disabled
        }
        else {
            if ( $debug_cron == 1 || $debug_cron == '1')
                $debug_cron = true;
            else
                $debug_cron = false;
        }

        if ( $running_cron_hash !== "" ) {
            if ( $running_cron_hash == 'NONE' || is_null($running_cron_hash) ){
                if ( abs($run_start_date->getTimestamp() - (int)$last_cron_run_timestamp) <= 120 ) {
                    // Did the last script complete within the last 2 minutes?
                    // Use case here - if there are no reports to be sent, and two CRONs were running, cron 1 can complete so quickly that cron 2 thinks It's OK to run
                    if ( $debug_cron ) {
                        $this->log('SFTP CRON for hash ' . $my_hash . ' execution terminated (on purpose) - other cron completed too recently - less than 2 minutes ago!');
                    }
                    return "External Module SFTP CRON for hash $my_hash execution terminated (on purpose) - other cron completed too recently!";
                }
                // Otherwise
                if ( $debug_cron ) {
                    $this->log('SFTP CRON - setting currently running cron to '.$my_hash);
                }
                $this->update_current_running_cron_hash($my_hash, false); // we got here first - claim it!
                //return "I'm running $my_hash!";
            }
            elseif( $running_cron_hash == $my_hash ) {
                // It's me! Don't know how, but it's me! So no action needed here
                //return "impossible! $my_hash!";
            }
            else {
                // There is a cron running and it's not us
                // Check to see if we have stalled out somehow
                if ( $last_cron_run_timestamp == "" ) {
                    // This is the first time we're seeing this, but there's another cron already running!
                    if ( $debug_cron ) {
                        $this->log('SFTP CRON for hash ' . $my_hash . ' is a duplicate! Execution terminated (on purpose)! last_cron_run_timestamp empty.');
                    }
                    return "External Module SFTP CRON for hash $my_hash is a duplicate! Execution terminated (on purpose)!";
                }
                elseif ( abs($run_start_date->getTimestamp() - (int)$last_cron_run_timestamp) > 2*3600 ) {
                    // The running hash is NOT MY Hash, but its been more than 2 cycles since last run - this means a cron stalled out
                    // If we got here, then claim the cron and start working
                    if ( $debug_cron ) {
                        $this->log('SFTP CRON - setting currently running cron to '.$my_hash);
                    }
                    $this->update_current_running_cron_hash($my_hash, false); // we got here first - claim it!
                    //return "Stalled out $my_hash!";
                }
                elseif ( abs($run_start_date->getTimestamp() - (int)$last_cron_run_timestamp) <= 120 ) {
                    // Did the last script complete within the last 2 minutes?
                    // Use case here - if there are no reports to be sent, and two CRONs were running, cron 1 can complete so quickly that cron 2 thinks It's OK to run
                    if ( $debug_cron ) {
                        $this->log('SFTP CRON for hash ' . $my_hash . ' execution terminated (on purpose) - other cron completed too recently!');
                    }
                    return "External Module SFTP CRON for hash $my_hash execution terminated (on purpose) - other cron completed too recently! last_cron_run_timestamp: [" . $last_cron_run_timestamp . ']';
                }
                else {
                    if ( $debug_cron ) {
                        $this->log('SFTP CRON for hash ' . $my_hash . ' is a duplicate! Execution terminated (on purpose)!');
                    }
                    return "External Module SFTP CRON for hash $my_hash is a duplicate! Execution terminated (on purpose)!";
                }
            }
        }
        else {
            // this is the very first time we're seeing this variable
            $this->update_current_running_cron_hash($my_hash, true); $this->setSystemSetting('sftp-cron-hash',$my_hash); // we got here first - claim it!
            //return "First time! $my_hash!";
        }

        $framework = \ExternalModules\ExternalModules::getFrameworkInstance($this->PREFIX);
        $projects = $framework->getProjectsWithModuleEnabled();

        if (count($projects) > 0) {
            foreach ($projects as $project_id) {
                try {
                    $Proj = new Project($project_id);
                    if ( $debug_cron ) {
                        $this->log('SFTP CRON '.$my_hash.' Dispatching START command for PID: '.$project_id);
                    }
                    // Get the URL for the cron listener
                    $module_cron_url = \ExternalModules\ExternalModules::getUrl($this->PREFIX, 'mgb_sftp_cron.php', $Proj->project_id, true, false);

                    // do a GET to the curl with a very short timeout
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $module_cron_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_VERBOSE, 0);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_AUTOREFERER, true);
                    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
                    //curl_setopt($ch, CURLOPT_SSLVERSION, 6); // This is TLS 1.2
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                    curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Set a small timeout
                    $output = curl_exec($ch);
                    curl_close($ch);


                } catch (Exception $ee) {
                    \REDCap::logEvent($this->PREFIX . " exception: " . $ee->getMessage(), '', '', null, null, $project_id);
                }
            }
        }

        // Reset the cron hash to NONE
        $this->update_current_running_cron_hash("NONE", false); // reset it to NONE
        $run_end_date = new DateTime();
        $this->update_current_running_cron_time($run_end_date->getTimestamp(), ($last_cron_run_timestamp == "" ? true : false)); // set the timestamp of the last run
        
        return "Cron $my_hash ran successfully ".var_export($currently_running_cron,true)." and secs ".$seconds_since_last_run;
    }


    /**
     * stuckCheck - check the anomoly of being in a bad state with a HASH and no Last Cron Run time. if so, then reset those values.
     * - sftp-cron-last-finish
     * - sftp-cron-hash
     * @return string[]|void
     */
    private function stuckCheck() {
        $flagStuck = false;
        
        $currently_running_cron = $this->get_currently_running_cron_info();

        $running_cron_hash       = $currently_running_cron['sftp-cron-hash']; //$this->getSystemSetting('sftp-cron-hash');
        $last_cron_run_timestamp = $currently_running_cron['sftp-cron-last-finish']; //$this->getSystemSetting('sftp-cron-last-finish');

        //if ( $last_cron_run_timestamp == '' && $running_cron_hash !== '') { // last cron is blank and cron hash has a value
        //}
        if ( $running_cron_hash == 'NONE' || is_null($running_cron_hash) ){
        }elseif( $running_cron_hash == $my_hash ) {  // this is not possible. my hash is just created and running is what was previous stored and retrieved.
        } else {
            // this is where we end up with the issue
            if ( $last_cron_run_timestamp == '') { // last cron run is blank and cron hash has a hash value
                // this point we are stuck
                
                // so unstick it.
                $this->resetTheCronHashAndLastCronRunTime();
                $flagStuck = true; // indicate was stuck and had to unstick it
            }
        }
        
        return $flagStuck;
    }
    
    /**
     * resetTheCronHashAndLastCronRunTime - Reset the cron hash to empty
     * - sftp-cron-last-finish
     * - sftp-cron-hash
     * @return string[]|void
     */
    private function resetTheCronHashAndLastCronRunTime() {

        // Reset the cron hash to NONE
        $this->update_current_running_cron_hash("NONE", false); // reset it to NONE
        
        $run_end_date = new DateTime();
        $this->update_current_running_cron_time($run_end_date->getTimestamp(), true ); // set the timestamp of the last run
    }
    
    /**
     * Get the project settings directly from the database for the following parameters
     * - sftp-cron-last-finish
     * - sftp-cron-hash
     * @return string[]|void
     */
    private function get_currently_running_cron_info () {
        try {
            // sftp-cron-hash and sftp-cron-last-finish
            $lookup_sql = "SELECT directory_prefix,`key`,`value` from redcap_external_modules,redcap_external_module_settings ".
                "where ".
                "redcap_external_modules.external_module_id = redcap_external_module_settings.external_module_id ".
                "and directory_prefix=\"".$this->PREFIX."\" ".
                "and project_id is null ".
                "and `key` in ('sftp-cron-hash','sftp-cron-last-finish');";
            $lookup_q = db_query($lookup_sql);
            $return_data = array (
                'sftp-cron-hash'        => "",
                'sftp-cron-last-finish' => ""
            );
            while ($row = db_fetch_assoc($lookup_q)) {
                if ( $row['key'] == 'sftp-cron-hash') {
                    $return_data['sftp-cron-hash'] = $row['value'];
                }
                if ( $row['key'] == 'sftp-cron-last-finish') {
                    $return_data['sftp-cron-last-finish'] = $row['value'];
                }
            }
            return $return_data;
        }
        catch ( Exception $e ) {
            return array (
                'sftp-cron-hash'        => "",
                'sftp-cron-last-finish' => ""
            );
        }
    }

    /**
     * Set the currently running cron hash in the settings table directly
     * @param $cron_hash
     * @return void
     */
    private function update_current_running_cron_hash( $cron_hash, $insert = false ) {
        try{
            $sql = "UPDATE redcap_external_modules a, redcap_external_module_settings b ".
                "set b.value= \"".db_escape($cron_hash)."\" ".
                "WHERE ".
                "a.external_module_id = b.external_module_id ".
                "AND a.directory_prefix=\"mgb_sftp_report_exporter\"".
                "AND b.project_id is null ".
                "AND b.key='sftp-cron-hash'";
            if ( $insert ) {
                $sql = "INSERT INTO redcap_external_module_settings " .
                    "(external_module_id, project_id,`key`,`type`,`value`) ".
                    "values ( ".
	                    "(select external_module_id from redcap_external_modules where directory_prefix=\"".$this->PREFIX."\"), ".
                        "NULL, ".
                        "\"sftp-cron-hash\", ".
	                    "\"string\",".
                        "\"".db_escape($cron_hash)."\", ".
                        ");";
            }
            $q = db_query($sql);
            if ( !$q ) {
                return false;
            }
            return true;
        }
        catch ( Exception $ee ) {
            return false;
        }
    }

    /**
     * Update the value of sftp-cron-last-finish in the DB
     * @param $cron_time
     * @param $insert
     * @return bool
     */
    private function update_current_running_cron_time( $cron_time, $insert = false ) {
        try{
            $sql = "UPDATE redcap_external_modules a, redcap_external_module_settings b ".
                "set b.value= \"".db_escape($cron_time)."\" ".
                "WHERE ".
                "a.external_module_id = b.external_module_id ".
                "AND a.directory_prefix=\"mgb_sftp_report_exporter\"".
                "AND b.project_id is null ".
                "AND b.key='sftp-cron-last-finish'";
            if ( $insert ) {
                $sql = "INSERT INTO redcap_external_module_settings " .
                    "(external_module_id, project_id,`key`,`type`,`value`) ".
                    "values ( ".
                    "(select external_module_id from redcap_external_modules where directory_prefix=\"".$this->PREFIX."\"), ".
                    "NULL, ".
                    "\"sftp-cron-last-finish\", ".
                    "\"string\",".
                    "\"".db_escape($cron_time)."\", ".
                    ");";
            }
            $q = db_query($sql);
            if ( !$q ) {
                return false;
            }
            return true;
        }
        catch ( Exception $ee ) {
            return false;
        }
    }

    public function get_report_filename ( $rep_id, $module_obj, $report_format ) {
        global $Proj;

        // 1. Get the configuration
        $all_report_configurations = $module_obj->getProjectSetting('project_report_configurations');

        // 2. Get the report name
        $report_name = "";
        $report_file_identifier = "";
        $report_filename = "";
        $report_valid = false;

        // Initialize some stuff
        if ( $rep_id == 'ALL' || $rep_id == 0) {
            $report_name = "ALL DATA";
            $report_file_identifier = "ALL_DATA";
            $report_valid = true;
        }
        else {
            // Get a list of the reports that this user/project has access to and make sure the provided ID is one of them
            $reports = DataExport::getReportNames(null, $applyUserAccess = true);
            foreach ($reports as $rep) {
                if ($rep['report_id'] == $rep_id) {
                    $report_name = $rep['title'];
                    $report_file_identifier = substr(str_replace(" ", "", ucwords(preg_replace("/[^a-zA-Z0-9 ]/", "", html_entity_decode($report_name, ENT_QUOTES)))), 0, 20);
                    $report_valid = true;
                    break;
                }
            }
        }

        // Default
        $today = date('Y_m_d_h_i');
        $rand_str = $module_obj->get_random_string(4); // generate a random string for uniqueness
        if ( $report_format == 'csv' || $report_format == 'csvraw' || $report_format == 'csvlabels' ) {
            $report_filename = "Report_".$report_file_identifier."_".$Proj->project_id."_".$rand_str."_".$today.".csv";
        }
        elseif ( $report_format == 'odm' || $report_format == 'odmraw' || $report_format == 'odmlabels' ) {
            $report_filename = "Report_".$report_file_identifier."_".$Proj->project_id."_".$rand_str."_".$today.".xml";
        }
        elseif ( $report_format == "json" || $report_format == 'jsonraw' || $report_format == 'jsonlabels' ) {
            $report_filename = "Report_".$report_file_identifier."_".$Proj->project_id."_".$rand_str."_".$today.".json";
        }

        if ( isset($all_report_configurations)
            && isset($all_report_configurations['EXPORTCFG_'.$rep_id]) && is_array($all_report_configurations['EXPORTCFG_'.$rep_id]) ) {
            $rep_cfg = $all_report_configurations['EXPORTCFG_'.$rep_id];

            if ( $rep_cfg['filename'] == 'custom') {
                $repcuststring = isset($rep_cfg['filename_string']) ? htmlspecialchars(trim(strip_tags(html_entity_decode($rep_cfg['filename_string'], ENT_QUOTES))), ENT_QUOTES, 'UTF-8') : "";
                if ( strlen($repcuststring) > 0 ) {
                    $report_file_identifier = substr(str_replace(" ", "", ucwords(preg_replace("/[^a-zA-Z0-9 ]/", "", $repcuststring))), 0, 20);
                    $report_filename = $report_file_identifier;
                }
                else {
                    $report_filename = "Report_".$report_file_identifier;
                }

                // Do we need to append the PID?
                if ( isset($rep_cfg['filename_append_pid']) && $rep_cfg['filename_append_pid'] == 1 ) {
                    $report_filename .= "_".$Proj->project_id;
                }

                // DO we need to append custom string
                if ( isset($rep_cfg['filename_append_rand']) && $rep_cfg['filename_append_rand'] == 1 ) {
                    $report_filename .= "_".$rand_str;
                }

                // Do we need to append a date
                if ( isset($rep_cfg['filename_append_date'])
                        && ($rep_cfg['filename_append_date'] == 1 || $rep_cfg['filename_append_date'] == 2)) {
                    $d = "";
                    if ( $rep_cfg['filename_append_date'] == 1 )
                        $d = date('Y_m_d');
                    if ( $rep_cfg['filename_append_date'] == 2 )
                        $d = date('Y_m_d_h_i');
                    $report_filename .= "_".$d;
                }

                // Append the file extension
                if ( $report_format == 'csv' || $report_format == 'csvraw' || $report_format == 'csvlabels' ) {
                    $report_filename .= ".csv";
                }
                elseif ( $report_format == 'odm' || $report_format == 'odmraw' || $report_format == 'odmlabels' ) {
                    $report_filename .= ".xml";
                }
                elseif ( $report_format == "json" || $report_format == 'jsonraw' || $report_format == 'jsonlabels' ) {
                    $report_filename .= ".json";
                }
            }

            // Otherwise default
        }

        if ( $report_valid ) {
            return $report_filename;
        }
        else {
            return false; // the report is not valid
        }
    }

    /**
     * return the data in CSV format
     * @param $data
     * @return mixed
     */
    public function eav_to_csv ( $data ) {
        return arrayToCsv($data, true, ","); // this function is in init_functions.php .. in theory
    }

    public function get_all_data_report_for_sftp ( $outputFormat='array', $exportAsLabels=false, $exportCsvHeadersAsLabels=false ) {
        $outputType = 'export';
        $outputCheckboxLabel = false;
        $outputDags = false;
        $outputSurveyFields = false;
        $dateShiftDates = false;
        $dateShiftSurveyTimestamps = false;
        $selectedInstruments = array();
        $selectedEvents = array();
        $returnIncludeRecordEventArray = false;
        $outputCheckboxLabel = false;
        $includeOdmMetadata = false;
        $storeInFileRepository = false;
        $replaceFileUploadDocId = true;
        $liveFilterLogic = '';
        $liveFilterGroupId = '';
        $liveFilterEventId = '';
        $isDeveloper = true;

        return DataExport::doReport(
            'ALL',
            $outputType,
            $outputFormat,
            $exportAsLabels,
            $exportCsvHeadersAsLabels,
            $outputDags,
            $outputSurveyFields,
            false, // TODO it all depends on the user rights.. but if it's a cron..whose users rights?
            false, // TODO it all depends on the user rights.. but if it's a cron..whose users rights?
            false, // TODO it all depends on the user rights.. but if it's a cron..whose users rights?
            false, // TODO it all depends on the user rights.. but if it's a cron..whose users rights?
            false, // TODO it all depends on the user rights.. but if it's a cron..whose users rights?
            $dateShiftDates,
            $dateShiftSurveyTimestamps,
            $selectedInstruments,
            $selectedEvents,
            $returnIncludeRecordEventArray,
            $outputCheckboxLabel,
            $includeOdmMetadata,
            $storeInFileRepository,
            $replaceFileUploadDocId,
            $liveFilterLogic,
            $liveFilterGroupId,
            $liveFilterEventId,
            $isDeveloper
        );
    }
    
    /**
     * manualRun - manual run of some system cron
     */
    public function manualRun() 
    {
        $thisModuleId = $this->getProcessExternalModuleId();
        
        $thisModuleJobName = self::CRON_METHOD_NAME; // 'extmod_sftp_report_exporter';

        $redcapCronJobReturnMsg = \ExternalModules\ExternalModules::callCronMethod($thisModuleId, $thisModuleJobName);
        
        $this->viewHtml($redcapCronJobReturnMsg, self::VIEW_CMD_CONTROL);
    }
    
    /**
     * getProcessExternalModuleId - get the external_module_id by knowing what the cron_name being used ( see config.json under crons )
     */
    public function getProcessExternalModuleId() 
    {
        $external_module_id = 0;
                
        $sql = "SELECT cron_id, cron_name, external_module_id FROM redcap_crons WHERE cron_name = ?";

        $queryResult = $this->sqlQueryAndExecute($sql, [self::CRON_METHOD_NAME]);  // ['extmod_sftp_report_exporter']
        
        if ($queryResult) {
            while ($row = $queryResult->fetch_assoc()) {
                $external_module_id = $row['external_module_id'];
                break;
            }
        }        
    
        return $external_module_id;
    }
    
    /**
     * showId - show the external_module_id
     */
    public function showId() 
    {
        $external_module_id = 0;
                
        $external_module_id = $this->getProcessExternalModuleId(); 
        
        $this->viewHtml('external_module_id ID: [ ' . $external_module_id . ' ]', self::VIEW_CMD_CONTROL);
    }
    
    /**
    * sqlQueryAndExecute - encapsulate some of the repeative details.  pass one param or many params.
    */
    private function sqlQueryAndExecute($sql, $params = null)
    {
        $queryResult = null;
        
        try {
            $query = ExternalModules::createQuery();
            
            //$this->queryHandle = $query;
            
            if ($params == null) {
                $this->alwaysLogMsg('ERROR: NO Params: ' . $sql);
                return null;
            }
            
            $query->add($sql, $params);
            
            $queryResult = $query->execute();

        } catch (Throwable $e) {
            $this->alwaysLogMsg('ERROR: ' . $sql . ' err:' . $e->__toString());
        }
        
        return $queryResult;
    }    
    
    /**
    * viewHtml - the front end part, display what we have put together. This method has an added feature for use with the control center, includes all the REDCap navigation.
    */
    public function viewHtml($msg = 'view', $flag = self::VIEW_CMD_DEFAULT)
    {
        $HtmlPage = new HtmlPage(); 
        
        switch ($flag) {
            // project
            case self::VIEW_CMD_PROJECT:
                $HtmlPage->ProjectHeader();
                echo $msg;
                $HtmlPage->ProjectFooter();
            break;
            
            // control
            case self::VIEW_CMD_CONTROL:
                if (!SUPER_USER) {
                    redirect(APP_PATH_WEBROOT); 
                }
                
                global $lang;  // this is needed for these two to work properly
                include APP_PATH_DOCROOT . 'ControlCenter/header.php';
                echo $msg;
                include APP_PATH_DOCROOT . 'ControlCenter/footer.php';
            break;
            
            // system
            case self::VIEW_CMD_SYSTEM:
            default:
                $HtmlPage->setPageTitle($this->projectName);
                $HtmlPage->PrintHeaderExt();
                echo $msg;
                $HtmlPage->PrintFooterExt();
            break;
        }
    }    
    
    /**
    * alwaysLogMsg - .
    */
    public function alwaysLogMsg($debugmsg, $shortMsg = '')
    {
        $this->performLogging($debugmsg, ($shortMsg ? $shortMsg : $debugmsg));
    }
    
    /**
    * performLogging - .
    */
    public function performLogging($logDisplay, $logDescription = self::NAME_IDENTIFIER)
    {
        // $sql, $table, $event, $record, $display, $descrip="", $change_reason="",
        //									$userid_override="", $project_id_override="", $useNOW=true, $event_id_override=null, $instance=null
        $logSql         = '';
        $logTable       = '';
        $logEvent       = 'OTHER';  // 'event' what events can we have?  DATA_EXPORT, INSERT, UPDATE, MANAGE, OTHER
        $logRecord      = '';
        
        //$logDisplay     // 'data_values'  (table: redcap_log_event)
        //$logDescription // 'description' limit in size is 100 char (auto chops to size)
        
        Logging::logEvent($logSql, $logTable, $logEvent, $logRecord, $logDisplay, $logDescription);
    }

    /**
    * areFileSizesDifferent - Compare two size values with a tolerance range.
    * 
    * @param int $remoteSize:    Size of the remote file.
    * @param int $localSize:     Size of the local file.
    * @param bool $signalType:   True for boolean flag, false for number indicator.
    * @param int $tolerance:     Allowed range for size difference.
    * 
    * @return bool|int Returns a boolean flag or a numerical indicator based on the signalType.
    *   indicator : -1: remote is smaller, 0: remote is same within tolerance, 1: remote is larger
    *   flag      : false: sizes are same within tolerance, true: sizes are very different
    * 
    *  areFileSizesDifferent: true sizes are different, false sizes are okay
    * 
    */
    public function areFileSizesDifferent(int $remoteSize, int $localSize, bool $signalType = self::SIZE_SIGNALTYPE, int $tolerance = self::SIZE_TOLERANCE): bool|int 
    {       
        $difference = abs($remoteSize - $localSize);
        
        $flag = $difference > $tolerance;
        
        $indicator = $flag ? ($remoteSize > $localSize ? 1 : -1) : 0;

        return $signalType ? $flag : $indicator;
    }

} // ** END CLASS
