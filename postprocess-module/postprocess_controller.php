<?php

// no direct access
defined('EMONCMS_EXEC') or die('Restricted access');

function postprocess_controller()
{
    global $linked_modules_dir, $session, $route, $mysqli, $redis, $settings;

    $result = false;
    $route->format = "text";

    $log = new EmonLogger(__FILE__);

    include "Modules/feed/feed_model.php";
    $feed = new Feed($mysqli, $redis, $settings['feed']);

    include "Modules/postprocess/postprocess_model.php";
    $postprocess = new PostProcess($mysqli, $redis, $feed);

    // Load available processes descriptions
    $processes = $postprocess->get_processes("$linked_modules_dir/postprocess");

    // -------------------------------------------------------------------------
    // VIEW
    // -------------------------------------------------------------------------
    if ($route->action == "" && $session['write']) {
        $result = view("Modules/postprocess/view.php", array("processes" => $processes));
        $route->format = "html";
        return array('content' => $result);
    }

    if ($route->action == "processes" && $session['write']) {
        $route->format = "json";
        return array('content' => $processes);
    }

    // -------------------------------------------------------------------------
    // PROCESS LIST
    // -------------------------------------------------------------------------
    if ($route->action == "list" && $session['write']) {
        $route->format = "json";
        return $postprocess->get_list($session['userid']);
        /*
        if (!)) {
            $processlist = array();
        }
        $processlist_long = array();
        $processlist_valid = array();
        // validate each process in the list
        for ($i = 0; $i < count($processlist); $i++) {
            $params = json_decode(json_encode($processlist[$i]));
            // Check if process exists
            $valid = true;
            if (!isset($processes[$params->process])) {
                $valid = false;
            }
            // Check if process parameters are valid
            $result = $postprocess->validate_params($session['userid'],$params->process,$params);
            if (!$result['success']) $valid = false;
            // If valid add to output
            if ($valid) {
                $processlist_long[] = $processlist[$i];
                $processlist_valid[] = $processlist[$i];
            }
        }
        $postprocess->set($session['userid'], $processlist_valid);
        $result = $processlist_long;*/
    }

    if ($route->action == "create" && $session['write']) {
        $route->format = "json";
        $params = json_decode(file_get_contents('php://input'));
        return $postprocess->add($session['userid'], $params);
    }

    if ($route->action == 'edit' && $session['write']) {
        $route->format = "json";
        $processid = (int) get('processid', true);       
        $params = json_decode(file_get_contents('php://input'));
        return $postprocess->update($session['userid'], $processid, $params);
    }

    if ($route->action == "run" && $session['write']) {
        $route->format = "json";
        $processid = (int) get('processid', true);
        return $postprocess->update_status($session['userid'], $processid, "queued");
    }

    if ($route->action == "remove" && $session['write']) {
        $route->format = "json";
        $processid = (int) get('processid', true);
        return $postprocess->remove($session['userid'], $processid);
    }

    if ($route->action == 'logpath' && $session['admin']) {
        return $settings['log']['location'] . "/postprocess.log";
    }

    if ($route->action == 'getlog' && $session['admin']) {
        $route->format = "text";
        $log_filename = $settings['log']['location'] . "/postprocess.log";
        if (file_exists($log_filename)) {
            ob_start();
            passthru("tail -30 $log_filename");
            $result = trim(ob_get_clean());
        } else $result = "no logging yet available";
    }

    return array('content' => $result, 'fullwidth' => false);
}
