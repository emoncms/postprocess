<?php

// no direct access
defined('EMONCMS_EXEC') or die('Restricted access');

function postprocess_controller()
{
    global $linked_modules_dir, $session, $route, $mysqli, $redis, $settings;

    // Write access required to use this module
    if (!$session['write']) {
        if ($route->format=='html') {
            // Empty response returns to login
            return ''; 
        } else {
            return array("succes"=>false, "message"=>"Invalid permission");
        }
    }

    // If we are at this point the user has write access level

    $log = new EmonLogger(__FILE__);

    include "Modules/feed/feed_model.php";
    $feed = new Feed($mysqli, $redis, $settings['feed']);

    include "Modules/postprocess/postprocess_model.php";
    $postprocess = new PostProcess($mysqli, $redis, $feed);

    // Load available processes descriptions
    $processes = $postprocess->get_processes("$linked_modules_dir/postprocess");

    // VIEW
    $route->format = "html";

    if ($route->action == "") {
        return view("Modules/postprocess/view.php", array("processes" => $processes));
    }

    // JSON API
    $route->format = "json";

    if ($route->action == "processes") return $processes;
    
    if ($route->action == "list") {
        return $postprocess->get_list($session['userid']);
    }

    if ($route->action == "create") {
        $params = json_decode(file_get_contents('php://input'));
        return $postprocess->add($session['userid'], $params);
    }

    if ($route->action == 'edit') {
        $processid = (int) get('processid', true);       
        $params = json_decode(file_get_contents('php://input'));
        return $postprocess->update($session['userid'], $processid, $params);
    }

    if ($route->action == "run") {
        $processid = (int) get('processid', true);
        $postprocess->update_status($session['userid'], $processid, "queued");
        return $postprocess->trigger_service_runner();
    }

    if ($route->action == "remove") {
        $processid = (int) get('processid', true);
        return $postprocess->remove($session['userid'], $processid);
    }

    // Plain/text API
    if ($route->action == 'logpath' && $session['admin']) {
        $route->format = "text";
        return $settings['log']['location'] . "/postprocess.log";
    }

    if ($route->action == 'getlog' && $session['admin']) {
        $route->format = "text";
        $log_filename = $settings['log']['location'] . "/postprocess.log";
        if (file_exists($log_filename)) {
            ob_start();
            passthru("tail -30 $log_filename");
            return trim(ob_get_clean());
        } else return "no logging yet available";
    }
    return EMPTY_ROUTE;
}
