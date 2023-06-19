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
    $postprocess = new PostProcess($mysqli, $feed);

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
        if (!$processlist = $postprocess->get($session['userid'])) {
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
        $result = $processlist_long;
    }

    // -------------------------------------------------------------------------
    // CREATE OR UPDATE PROCESS
    // -------------------------------------------------------------------------
    if (($route->action == "create" || $route->action == "edit") && $session['write']) {
        $route->format = "json";

        // this is the process name
        $process = get('process', true);
        // if we are editing, we need the process id
        if ($route->action == "edit")
            $processid = (int) get('processid', true);
        // process parameters in the post body
        $params = json_decode(file_get_contents('php://input'));
        
        // validate parameters, check valid feeds etc
        $result = $postprocess->validate_params($session['userid'],$process,$params);
        if (!$result['success']) return $result;

        // process_mode and process_start are not included in the process description
        // so we need to add them here if they are not set
        if (!isset($params->process_mode))
            $params->process_mode = "recent";
        if (!isset($params->process_start))
            $params->process_start = 0;

        $params->process = $process;

        // If we got this far the input parameters are valid.
        if (!$processlist = $postprocess->get($session['userid'])) {
            $processlist = array();
        }
        if ($route->action == "edit") {
            if (isset($processlist[$processid])) {
                $processlist[$processid] = $params;
            }
        } else {
            $processlist[] = $params;
        }
        $postprocess->set($session['userid'], $processlist);
        $redis->lpush("postprocessqueue", json_encode($params));

        // -----------------------------------------------------------------
        // Run postprocessor script using the emonpi service-runner
        // -----------------------------------------------------------------
        $update_script = "$linked_modules_dir/postprocess/postprocess.sh";
        $update_logfile = $settings['log']['location'] . "/postprocess.log";
        $redis->rpush("service-runner", "$update_script>$update_logfile");
        $result = "service-runner trigger sent";
        // -----------------------------------------------------------------
        return array('success' => true, 'message' => "process created");
    }

    if ($route->action == "run") {
        $route->format = "json";
        $processid = (int) get('processid', true);
        if (!$processlist = $postprocess->get($session['userid'])) {
            return array('success' => false, 'message' => "no processes");
        }
        if (!isset($processlist[$processid])) {
            return array('success' => false, 'message' => "process does not exist");
        }
        $redis->lpush("postprocessqueue", json_encode($processlist[$processid]));
        // -----------------------------------------------------------------
        // Run postprocessor script using the emonpi service-runner
        // -----------------------------------------------------------------
        $update_script = "$linked_modules_dir/postprocess/postprocess.sh";
        $update_logfile = $settings['log']['location'] . "/postprocess.log";
        $redis->rpush("service-runner", "$update_script>$update_logfile");
        $result = "service-runner trigger sent";
        // -----------------------------------------------------------------
        return array('success' => true, 'message' => "process added to queue");
    }

    if ($route->action == "remove" && $session['write']) {
        $route->format = "json";
        $processid = (int) get('processid', true);
        $processlist = $postprocess->get($session['userid']);
        if (isset($processlist[$processid])) {
            array_splice($processlist, $processid, 1);
        } else {
            return array("success" => false, "message" => "process does not exist");
        }
        $postprocess->set($session['userid'], $processlist);
        return array("success" => true, "message" => "process removed");
    }

    if ($route->action == 'logpath') {
        return $settings['log']['location'] . "/postprocess.log";
    }

    if ($route->action == 'getlog') {
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
