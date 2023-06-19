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

    public function __construct($mysqli,$redis,$feed) 
    {
        $this->mysqli = $mysqli;
        $this->redis = $redis;
        $this->feed = $feed;
    }
        
    public function set($userid,$data)
    {
        $userid = (int) $userid;
        // $data = preg_replace('/[^\w\s\-.",:#{}\[\]]/','',$data);
        $data = json_encode($data);
        
        if ($this->get($userid)===false) {
            $stmt = $this->mysqli->prepare("INSERT INTO postprocess ( userid, data ) VALUES (?,?)");
            $stmt->bind_param("is", $userid, $data);
            if ($stmt->execute()) return true; 
        } else {
            $stmt = $this->mysqli->prepare("UPDATE postprocess SET `data`=? WHERE userid=?");
            $stmt->bind_param("si", $data, $userid);
            if ($stmt->execute()) return true;
        }
        return false;
    }
    
    public function get($userid)
    {
        $userid = (int) $userid;
        $result = $this->mysqli->query("SELECT data FROM postprocess WHERE `userid`='$userid'");
        if ($result->num_rows > 0) {
            if ($row = $result->fetch_object()) {
                $data = json_decode($row->data);
                if (!$data || $data==null) $data = array();
                return $data;
            }
        }
        return false;
    }
    
    public function clear_all($userid) 
    {
    
    }

    // Get all processes
    public function get_processes($dir) {

        require_once($dir."/common.php");

        $processes = array();
    
        $dir = $dir."/processes";
        $files = scandir($dir);
        for ($i=2; $i<count($files); $i++)
        {
            if (substr($files[$i],-4)==".php" && is_file($dir."/".$files[$i]) && !is_dir($dir."/".$files[$i])) {
                $filename = $files[$i];
                require_once($dir."/".$filename);

                $process_name = str_replace(".php","",$filename);

                if (class_exists("PostProcess_".$process_name)) {
                    $process_class = "PostProcess_".$process_name;
                    $process = new $process_class($dir);

                    if (method_exists($process,"description")) {
                        $process_description = $process->description();
                        if (isset($process_description['settings'])) {
                            $processes[$process_name] = $process_description;
                        }
                    }
                }
            }
        }
        $this->processes = $processes;
        return $processes;
    }

    // Validate process parameters
    public function validate_params($userid,$process,$params) {

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
        $service_running = false;
        @exec("systemctl show service-runner | grep State", $output);
        foreach ($output as $line) {
            if (strpos($line, "ActiveState=active") !== false) {
                $service_running = true;
            }
        }
        return $service_running;
    }

    public function add_process_to_queue($process) {
        if (!$this->redis) {
            return array('success' => false, 'message' => "Redis not connected");
        }
        $this->redis->lpush("postprocessqueue", json_encode($process));

        // Check if post_processor is being ran by cron
        if (isset($settings['postprocess']) && isset($settings['postprocess']['cron_enabled'])) {
            if ($settings['postprocess']['cron_enabled']) {
                return array('success' => true, 'message' => "Process added to queue");
            }
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
}
