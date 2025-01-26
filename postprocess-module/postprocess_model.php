<?php

/*
  All Emoncms code is released under the GNU Affero General Public License.
  See COPYRIGHT.txt and LICENSE.txt.

  ---------------------------------------------------------------------
  Emoncms - open source energy visualisation
  Part of the OpenEnergyMonitor project:
  http://openenergymonitor.org
 */

// no direct access
defined('EMONCMS_EXEC') or die('Restricted access');

class PostProcess
{
    private $mysqli;
    private $redis;
    private $feed;
    private $processes = array();
    private $process_classes = array();
    public $datadir = "/var/opt/emoncms/phpfina/";

    public function __construct($mysqli,$redis,$feed) 
    {
        $this->mysqli = $mysqli;
        $this->redis = $redis;
        $this->feed = $feed;
    }

    public function add($userid,$params)
    {
        $userid = (int) $userid;
        if (!isset($params->process)) {
            return array('success'=>false,'message'=>'Process not specified');
        }
        if (!isset($this->processes[$params->process])) {
            return array('success'=>false,'message'=>'Process does not exist');
        }
        $result = $this->validate_params($userid,$params);
        if (!$result['success']) return $result;

        // process_mode and process_start are not included in the process description
        // so we need to add them here if they are not set
        if (!isset($params->process_mode))
            $params->process_mode = "recent";
        if (!isset($params->process_start))
            $params->process_start = 0;

        $params = json_encode($params);
        $status = "queued";
        $status_updated = time();
        $status_message = "";

        // Insert into using prepared statement
        $stmt = $this->mysqli->prepare("INSERT INTO postprocess ( userid, status, status_updated, status_message, params ) VALUES (?,?,?,?,?)");
        $stmt->bind_param("isiss", $userid, $status, $status_updated, $status_message, $params);
        if ($stmt->execute()) {
            return $this->trigger_service_runner();
        } else {
            return array('success'=>false,'message'=>'SQL error');
        }
    }

    public function update($userid,$processid,$params)
    {
        $userid = (int) $userid;
        $processid = (int) $processid;

        $result = $this->validate_params($userid,$params);
        if (!$result['success']) return $result;
        
        $params = json_encode($params);
        $status = "queued";
        $status_updated = time();
        $status_message = "";

        $stmt = $this->mysqli->prepare("UPDATE postprocess SET params=?, status=?, status_updated=?, status_message=? WHERE userid=? AND processid=?");
        $stmt->bind_param("ssisii", $params, $status, $status_updated, $status_message, $userid, $processid);
        if ($stmt->execute()) {
            $affected_rows = $stmt->affected_rows;
            $stmt->close();
            if ($affected_rows==0) {
                return array('success'=>false,'message'=>'Process does not exist');
            } else {
                // return array('success'=>true, 'message'=>'Process updated');
                return $this->trigger_service_runner();
            }
        } else {
            return array('success'=>false,'message'=>'SQL error');
        }
    }

    public function update_status($userid,$processid,$status,$status_message="")
    {
        $userid = (int) $userid;
        $processid = (int) $processid;
        $status = preg_replace("/[^a-zA-Z0-9]+/", "", $status);
        $status_message = preg_replace("/[^a-zA-Z0-9\s_\-.,:;]+/", "", $status_message);
        
        $status_updated = time();

        // Only update if status or status_message is different
        $result = $this->mysqli->query("SELECT status, status_message FROM postprocess WHERE userid='$userid' AND processid='$processid'");
        $row = $result->fetch_object();
        if ($row->status==$status && $row->status_message==$status_message) {
            return array('success'=>true, 'message'=>'Process status updated');
        }

        $stmt = $this->mysqli->prepare("UPDATE postprocess SET status=?, status_updated=?, status_message=? WHERE userid=? AND processid=?");
        $stmt->bind_param("sisii", $status, $status_updated, $status_message, $userid, $processid);
        if ($stmt->execute()) {
            $affected_rows = $stmt->affected_rows;
            $stmt->close();
            if ($affected_rows==0) {
                return array('success'=>false,'message'=>'Process does not exist');
            } else {
                return array('success'=>true, 'message'=>'Process status updated');
            }
        } else {
            return array('success'=>false,'message'=>'SQL error');
        }
    }

    public function remove($userid,$processid) {
        $userid = (int) $userid;
        $processid = (int) $processid;
        $stmt = $this->mysqli->prepare("DELETE FROM postprocess WHERE userid=? AND processid=?");
        $stmt->bind_param("ii", $userid, $processid);
        if ($stmt->execute()) {
            $affected_rows = $stmt->affected_rows;
            $stmt->close();
            if ($affected_rows==0) {
                return array('success'=>false,'message'=>'Process does not exist');
            } else {
                return array('success'=>true, 'message'=>'Process removed');
            }
        } else {
            return array('success'=>false,'message'=>'SQL error');
        }
    }

    // Return a list of processes for a given user, used for generating the process list in the UI
    public function get_list($userid) {
        $userid = (int) $userid;
        $result = $this->mysqli->query("SELECT processid,status,status_updated,status_message,params FROM postprocess WHERE userid='$userid' ORDER BY processid ASC");
        $processes = array();
        while ($row = $result->fetch_object()) {
            if ($row->params) {
                $row->params = json_decode($row->params);
                $row->processid = (int) $row->processid;
                $row->status_updated = (int) $row->status_updated;
                $processes[] = $row;
            }
        }

        // validate each process and remove if invalid
        $valid_processes = array();
        foreach ($processes as $process) {
            $result = $this->validate_params($userid,$process->params);
            if ($result['success']) {
                $valid_processes[] = $process;
            } else {
                $this->remove($userid,$process->processid);
            }
        }

        return $valid_processes;
    }

    // Get a single process
    public function get_process($userid,$processid) {
        $userid = (int) $userid;
        $processid = (int) $processid;
        $result = $this->mysqli->query("SELECT * FROM postprocess WHERE userid='$userid' AND processid='$processid'");
        $row = $result->fetch_object();
        return $row;
    }

    // Get all processes
    public function get_processes($dir) {

        require_once($dir."/common.php");

        $processes = array();
        $this->process_classes = array();
    
        $dir = $dir."/processes";
        $files = scandir($dir);
        for ($i=2; $i<count($files); $i++)
        {
            if (substr($files[$i],-4)==".php" && is_file($dir."/".$files[$i]) && !is_dir($dir."/".$files[$i])) {
                $filename = $files[$i];
                require_once($dir."/".$filename);

                $process_key = str_replace(".php","",$filename);

                if (class_exists("PostProcess_".$process_key)) {
                    $process_class = "PostProcess_".$process_key;
                    $process = new $process_class($this->datadir);
                    $this->process_classes[$process_key] = $process;

                    if (method_exists($process,"description")) {
                        $process_description = $process->description();
                        if (isset($process_description['settings'])) {
                            $process_description['key'] = $process_key;

                            if (!isset($process_description['name'])) {
                                $process_description['name'] = $process_key;
                            }
                            if (!isset($process_description['order'])) {
                                $process_description['order'] = 99;
                            } 
                            $processes[] = $process_description;
                        }
                    }
                }
            }
        }

        usort($processes, function($a, $b) {
            return $a['order'] <=> $b['order'];
        });
        $ordered_processes = array();
        foreach ($processes as $process) {
            $ordered_processes[$process['key']] = $process;
        }

        $this->processes = $ordered_processes;
        return $ordered_processes;
    }

    public function get_process_classes() {
        return $this->process_classes;
    }

    // Validate process parameters
    public function validate_params($userid,$params) {

        if (!isset($params->process))
            return array('success'=>false, 'message'=>"missing process");

        $process = $params->process;

        foreach ($this->processes[$process]['settings'] as $key => $option) {
            if (!isset($params->$key))
                return array('success'=>false, 'message'=>"missing option $key");

            if ($option['type'] == "feed" || $option['type'] == "newfeed") {
                $feedid = (int) $params->$key;
                if ($feedid < 1)
                    return array('success'=>false, 'message'=>"feed id must be numeric and more than 0");
                if (!$this->feed->exist($feedid))
                    return array('success'=>false, 'message'=>"feed does not exist");
                $f = $this->feed->get($feedid);
                if ($f['userid'] != $userid)
                    return array('success'=>false, 'message'=>"invalid feed");
                if ($f['engine'] != $option['engine'])
                    return array('success'=>false, 'message'=>"incorrect feed engine");

                $params->$key = $feedid;
            }

            if ($option['type'] == "value") {
                $value = (float) 1 * $params->$key;
                if ($value != $params->$key)
                    return array('success'=>false, 'message'=>"invalid value");
            }

            if ($option['type'] == "timezone") {
                if (!$datetimezone = new DateTimeZone($params->$key))
                    return array('success'=>false, 'message'=>"invalid timezone");
            }

            if ($option['type'] == "formula") {
                $formula = $params->$key;
                // find all feed ids in the formula
                $feed_ids = array();
                while (preg_match("/(f\d+)/", $formula, $b)) {
                    $feed_ids[] = substr($b[0], 1, strlen($b[0]) - 1);
                    $formula = str_replace($b[0], "", $formula);
                }
                // check all feed ids exist and belong to the user
                foreach ($feed_ids as $id) {
                    if (!$this->feed->exist((int)$id))
                        return array('success'=>false, 'message'=>"feed f$id does not exist");
                    $f = $this->feed->get($id);
                    if ($f['userid'] != $userid && !$f['public'])
                        return array('success'=>false, 'message'=>"invalid feed access");
                    if ($f['engine'] != $option['engine'])
                        return array('success'=>false, 'message'=>"incorrect feed engine");
                }
            }
        }

        return array('success'=>true);
    }

    public function check_service_runner() {
        if (file_exists("/.dockerenv")) {
            return true;
        }
        $service_running = false;
        @exec("systemctl show service-runner | grep State", $output);
        foreach ($output as $line) {
            if (strpos($line, "ActiveState=active") !== false) {
                $service_running = true;
            }
        }
        return $service_running;
    }

    public function trigger_service_runner() {
        // Check if post_processor is being ran by cron
        if (isset($settings['postprocess']) && isset($settings['postprocess']['cron_enabled'])) {
            if ($settings['postprocess']['cron_enabled']) {
                return array('success' => true, 'message' => "Process added to queue");
            }
        }

        if (!$this->redis) {
            return array('success' => false, 'message' => "Redis not connected");
        }

        // Check if service-runner.service is running
        if ($this->check_service_runner()) {
            global $settings, $linked_modules_dir;
            // Ask service-runner to run postprocess script
            $update_script = "$linked_modules_dir/postprocess/postprocess.sh";
            $update_logfile = $settings['log']['location'] . "/postprocess.log";
            $this->redis->rpush("service-runner", "$update_script>$update_logfile");

            return array('success' => true, 'message' => "Process added to queue");
        } else {
            return array('success' => true, 'message' => "Process added to queue but service-runner not running. Please run postprocess_run.php manually or install service-runner");
        }
    }

    // Return number of processes in queue
    public function get_process_queue_count() {
        $result = $this->mysqli->query("SELECT COUNT(*) AS count FROM postprocess WHERE status='queued' OR status='running'");
        $row = $result->fetch_object();
        return (int) $row->count;
    }

    // Pop process from queue
    public function pop_process_queue() {
        $result = $this->mysqli->query("SELECT userid,processid,status,status_updated,params FROM postprocess WHERE status='queued' OR status='running' ORDER BY status_updated ASC LIMIT 1");
        $process = $result->fetch_object();
        if ($process) {
            $process->params = json_decode($process->params);
            $process->userid = (int) $process->userid;
            $process->processid = (int) $process->processid;
            $process->status_updated = (int) $process->status_updated;
            $this->mysqli->query("UPDATE postprocess SET status='running',status_updated=UNIX_TIMESTAMP() WHERE processid=".$process->processid);
        } else {
            $process = false;
        }
        return $process;
    }
}
