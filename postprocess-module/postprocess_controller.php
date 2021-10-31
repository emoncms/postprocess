<?php

// no direct access
defined('EMONCMS_EXEC') or die('Restricted access');

function postprocess_controller()
{
    global $linked_modules_dir,$session,$route,$mysqli,$redis,$settings;

    $result = false;
    $route->format = "text";

    $log = new EmonLogger(__FILE__);

    include "Modules/postprocess/postprocess_model.php";
    $postprocess = new PostProcess($mysqli);

    include "Modules/feed/feed_model.php";
    $feed = new Feed($mysqli,$redis,$settings['feed']);

    $bfdescription="Enter your formula as a symbolic expression - allows brackets and the max function <br>
                  Examples : <br>
                  f1+2*f2-f3/12 if you work on feeds 1,2,3 <br>
                  1162.5*5.19*max(f7-f11,0) <br>
                  1162.5*f10*(f7-11) <br>
                  <br>
                  <font color=red>Caution : (f12-f13)*(f7-f11) will not be recognized !!</font><br>
                  <font color=green>check you feeds numbers before</font><br>";

    $processes = array(
        "powertokwh"=>array(
            "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:")
        ),
        "average"=>array(
            "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:"),
            "interval"=>array("type"=>"value", "short"=>"Interval of output feed (seconds):")
        ),
        "accumulator"=>array(
            "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:")
        ),
        "importcalc"=>array(
            "consumption"=>array("type"=>"feed", "engine"=>5, "short"=>"Select consumption power feed:"),
            "generation"=>array("type"=>"feed", "engine"=>5, "short"=>"Select solar generation power feed:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter import feed name:", "nameappend"=>"")
        ),
        "exportcalc"=>array(
            "generation"=>array("type"=>"feed", "engine"=>5, "short"=>"Select solar generation power feed:"),
            "consumption"=>array("type"=>"feed", "engine"=>5, "short"=>"Select consumption power feed:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter export feed name:", "nameappend"=>"")
        ),
        "addfeeds"=>array(
            "feedA"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed A:"),
            "feedB"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed B:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:", "nameappend"=>"")
        ),
        "scalefeed"=>array(
            "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed to scale:"),
            "scale"=>array("type"=>"value", "short"=>"Scale by:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:", "nameappend"=>"")
        ),
        "allowpositive"=>array(
            "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:", "nameappend"=>"")
        ),
        "offsetfeed"=>array(
            "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed to apply offset:"),
            "offset"=>array("type"=>"value", "short"=>"Offset by:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:", "nameappend"=>"")
        ),
        "mergefeeds"=>array(
            "feedA"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed A:"),
            "feedB"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed B:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:", "nameappend"=>"")
        ),
        "trimfeedstart"=>array(
            "feedid"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed to trim:"),
            "trimtime"=>array("type"=>"value", "short"=>"Enter start time to trim from:")
        ),
        "removeresets"=>array(
            "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed:"),
            "maxrate"=>array("type"=>"value", "short"=>"Max accumulation rate:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:")
        ),
        "removenan"=>array(
            "feedid"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed to remove nan values:"),
        ),
        "liquidorairflow_tokwh"=>array(
            "vhc"=>array("type"=>"value", "short"=>"volumetric heat capacity in Wh/m3/K"),
            "flow"=>array("type"=>"feed", "engine"=>5, "short"=>"flow in m3/h"),
            "tint"=>array("type"=>"feed", "engine"=>5, "short"=>"Internal temperature feed / start temperature feed :"),
            "text"=>array("type"=>"feed", "engine"=>5, "short"=>"External temperature feed / return temperature feed :"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output energy feed name (kWh) :")
        ),
        "constantflow_tokwh"=>array(
            "vhc"=>array("type"=>"value", "short"=>"volumetric heat capacity in Wh/m3/K"),
            "flow"=>array("type"=>"value", "short"=>"constant flow in m3/h"),
            "tint"=>array("type"=>"feed", "engine"=>5, "short"=>"Internal temperature feed / start temperature feed :"),
            "text"=>array("type"=>"feed", "engine"=>5, "short"=>"External temperature feed / return temperature feed :"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output energy feed name (kWh) :")
        ),
        "batterysimulator"=>array(
            "solar"=>array("type"=>"feed", "engine"=>5, "short"=>"Select solar feed:"),
            "consumption"=>array("type"=>"feed", "engine"=>5, "short"=>"Select consumption feed:"),
            "capacity"=>array("type"=>"value", "default"=>4.4, "short"=>"Usable battery capacity in kWh"),
            "max_charge_rate"=>array("type"=>"value", "default"=>2500.0, "short"=>"Max charge rate in Watts"),
            "max_discharge_rate"=>array("type"=>"value", "default"=>2500.0, "short"=>"Max discharge rate in Watts"),
            "round_trip_efficiency"=>array("type"=>"value", "default"=>0.9, "short"=>"Round trip efficiency 0.9 = 90%"),
            "timezone"=>array("type"=>"timezone", "default"=>"Europe/London", "short"=>"Timezone for offpeak charging"),
            "offpeak_soc_target"=>array("type"=>"value", "default"=>0, "short"=>"Offpeak charging SOC target in % (0 = turn off)"),
            "offpeak_start"=>array("type"=>"value", "default"=>3, "short"=>"Offpeak charging start time"),
            "charge"=>array("type"=>"newfeed", "default"=>"battery_charge", "engine"=>5, "short"=>"Enter battery charge feed name:"),
            "discharge"=>array("type"=>"newfeed", "default"=>"battery_discharge", "engine"=>5, "short"=>"Enter battery discharge feed name:"),
            "soc"=>array("type"=>"newfeed", "default"=>"battery_soc", "engine"=>5, "short"=>"Enter battery SOC feed name:"),
            "import"=>array("type"=>"newfeed", "default"=>"import", "engine"=>5, "short"=>"Enter grid import feed name:"),
            "charge_kwh"=>array("type"=>"newfeed", "default"=>"battery_charge_kwh", "engine"=>5, "short"=>"Enter battery charge kWh feed name:"),
            "discharge_kwh"=>array("type"=>"newfeed", "default"=>"battery_discharge_kwh", "engine"=>5, "short"=>"Enter battery discharge kWh feed name:"),
            "import_kwh"=>array("type"=>"newfeed", "default"=>"import_kwh", "engine"=>5, "short"=>"Enter grid import kWh feed name:"),
            "solar_direct_kwh"=>array("type"=>"newfeed", "default"=>"solar_direct_kwh", "engine"=>5, "short"=>"Enter solar direct kwh feed name:")
        ),
        "basic_formula"=>array(
            "formula"=>array("type"=>"formula", "short"=>$bfdescription),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name :")
        )
    );

    // -------------------------------------------------------------------------
    // VIEW
    // -------------------------------------------------------------------------
    if ($route->action == "" && $session['write']) {
        $result = view("Modules/postprocess/view.php",array());
        $route->format = "html";
        return array('content'=>$result);
    }

    if ($route->action == "processes" && $session['write']) {
        $route->format = "json";
        return array('content'=>$processes);
    }

    // -------------------------------------------------------------------------
    // PROCESS LIST
    // -------------------------------------------------------------------------
    if ($route->action == "list" && $session['write']) {

        $userid = $session['userid'];

        $processlist = $postprocess->get($userid);

        if ($processlist==null) $processlist = array();
        $processlist_long = array();
        $processlist_valid = array();

        for ($i=0; $i<count($processlist); $i++) {
            $valid = true;

            $item = json_decode(json_encode($processlist[$i]));

            $process = $item->process;
            if (isset($processes[$process])) {
                foreach ($processes[$process] as $key=>$option)
                {
                    if ($option['type']=="feed" || $option['type']=="newfeed") {
                        $id = $processlist[$i]->$key;
                        if ($feed->exist((int)$id)) {
                            $f = $feed->get($id);
                            if ($f['userid']!=$session['userid']) return false;
                            if ($meta = $feed->get_meta($id)) {
                                $f['start_time'] = $meta->start_time;
                                $f['interval'] = $meta->interval;
                                $f['npoints'] = $meta->npoints;
                                $f['id'] = (int) $f['id'];
                                $timevalue = $feed->get_timevalue($id);
                                $f['time'] = $timevalue["time"];
                            } else {
                                // $valid = false;
                                // $log->error("Invalid meta: ".json_encode($meta));
                            }
                            $item->$key = $f;
                        } else {
                            $valid = false;
                            $log->error("Feed $id does not exist");
                        }
                    }

                    if ($option['type']=="formula"){
                        $formula=$processlist[$i]->$key;
                        $f=array();
                        $f['expression']=$formula;
                        //we catch feed numbers in the formula
                        $feed_ids=array();
                        while(preg_match("/(f\d+)/",$formula,$b)){
                            $feed_ids[]=substr($b[0],1,strlen($b[0])-1);
                            $formula=str_replace($b[0],"",$formula);
                        }
                        $all_intervals=array();
                        $all_start_times=array();
                        $all_ending_times=array();
                        //we check feeds existence and stores all usefull metas
                        foreach($feed_ids as $id) {
                            if ($feed->exist((int)$id)){
                                $m=$feed->get_meta($id);
                                $all_intervals[]=$m->interval;
                                $all_start_times[]=$m->start_time;
                                $timevalue = $feed->get_timevalue($id);
                                $all_ending_times[] = $timevalue["time"];
                            } else {
                                $valid = false;
                                $log->error("Feed $id does not exist");
                            }
                        }
                        if ($valid){
                            $f['interval'] = max($all_intervals);
                            $f['start_time']= max($all_start_times);
                            $f['time']= min($all_ending_times);

                            $item->$key = $f;
                        }
                    }
                }
            } else {
                $valid = false;
                $log->error("$process does not exist");
            }

            if ($valid) {
                $processlist_long[] = $item;
                $processlist_valid[] = $processlist[$i];
            }
        }

        $postprocess->set($userid,$processlist_valid);

        $result = $processlist_long;

        $route->format = "json";
    }

    // -------------------------------------------------------------------------
    // CREATE NEW
    // -------------------------------------------------------------------------
    if ($route->action == "create" && $session['write']) {
        $route->format = "text";

        if (!isset($_GET['process']))
            return array('content'=>"expecting parameter process");

        $process = $_GET['process'];
        $params = json_decode(file_get_contents('php://input'));

        foreach ($processes[$process] as $key=>$option) {
           if (!isset($params->$key))
               return array('content'=>"missing option $key");

           if ($option['type']=="feed") {
               $feedid = (int) $params->$key;
               if ($feedid<1)
                   return array('content'=>"feed id must be numeric and more than 0");
               if (!$feed->exist($feedid))
                   return array('content'=>"feed does not exist");
               $f = $feed->get($feedid);
               if ($f['userid']!=$session['userid'])
                   return array('content'=>"invalid feed");
               if ($f['engine']!=$option['engine'])
                   return array('content'=>"incorrect feed engine");

               $params->$key = $feedid;
           }

           if ($option['type']=="newfeed") {
               $newfeedname = preg_replace('/[^\w\s\-:]/','',$params->$key);
               if ($params->$key=="")
                   return array('content'=>"new feed name is blank");
               if ($newfeedname!=$params->$key)
                   return array('content'=>"new feed name contains invalid characters");
                if ($feed->get_id($session['userid'],$newfeedname))
                   return array('content'=>"feed already exists with name $newfeedname");

                // New feed creation: note interval is 3600 this will be changed by the process to match input feeds..
                $c = $feed->create($session['userid'],"",$newfeedname,Engine::PHPFINA,json_decode('{"interval":3600}'));
                if (!$c['success'])
                    return array('content'=>"feed could not be created");

                // replace new feed name with its id if successfully created
                $params->$key = $c['feedid'];
           }

           if ($option['type']=="value") {
               $value = (float) 1*$params->$key;
               if ($value!=$params->$key)
                   return array('content'=>"invalid value");
           }

           if ($option['type']=="timezone") {
               if (!$datetimezone = new DateTimeZone($params->$key))
                   return array('content'=>"invalid timezone");
           }
        }

        // If we got this far the input parameters where valid.

        $userid = $session['userid'];
        $processlist = $postprocess->get($userid);
        if ($processlist==null) $processlist = array();

        $params->process = $process;
        $processlist[] = $params;

        $postprocess->set($userid,$processlist);
        $redis->lpush("postprocessqueue",json_encode($params));

         // -----------------------------------------------------------------
        // Run postprocessor script using the emonpi service-runner
        // -----------------------------------------------------------------
        $update_script = "$linked_modules_dir/postprocess/postprocess.sh";
        $update_logfile = $settings['log']['location']."/postprocess.log";
        $redis->rpush("service-runner","$update_script>$update_logfile");
        $result = "service-runner trigger sent";
        // -----------------------------------------------------------------

        $route->format = "json";
        return array('content'=>$params);
    }

    // -------------------------------------------------------------------------
    // UPDATE
    // -------------------------------------------------------------------------
    if ($route->action == "update" && $session['write']) {
        $route->format = "text";

        if (!isset($_GET['process']))
            return array('content'=>"expecting parameter process");

        $process = $_GET['process'];
        $params = json_decode(file_get_contents('php://input'));

        foreach ($processes[$process] as $key=>$option) {
           if (!isset($params->$key))
               return array('content'=>"missing option $key");

           if ($option['type']=="feed" || $option['type']=="newfeed") {
               $feedid = (int) $params->$key;
               if ($feedid<1)
                   return array('content'=>"feed id must be numeric and more than 0");
               if (!$feed->exist($feedid))
                   return array('content'=>"feed does not exist");
               $f = $feed->get($feedid);
               if ($f['userid']!=$session['userid'])
                   return array('content'=>"invalid feed");
               if ($f['engine']!=$option['engine'])
                   return array('content'=>"incorrect feed engine");
               $params->$key = $feedid;
           }

           if ($option['type']=="value") {
               $value = (float) $params->$key;
               if ($value!=$params->$key)
                   return array('content'=>"invalid value");
           }
           
           if ($option['type']=="timezone") {
               if (!$datetimezone = new DateTimeZone($params->$key))
                   return array('content'=>"invalid timezone");
           }
        }

        // If we got this far the input parameters where valid.

        $userid = $session['userid'];
        $processlist = $postprocess->get($userid);
        
        if ($processlist==null) $processlist = array();

        $params->process = $process;
        // Check to see if the process has already been registered
        $valid = false;
        for ($i=0; $i<count($processlist); $i++) {
            $tmp = $processlist[$i];
            //print "prm:".json_encode($params)."\n";
            //print "tmp:".json_encode($tmp)."\n";
            if (json_encode($tmp)==json_encode($params)) $valid = true;
        }
        if (!$valid)
            return array('content'=>"process does not exist, please create");

        // Add process to queue
        $redis->lpush("postprocessqueue",json_encode($params));

        // -----------------------------------------------------------------
        // Run postprocessor script using the emonpi service-runner
        // -----------------------------------------------------------------

        $update_script = "$linked_modules_dir/postprocess/postprocess.sh";
        $update_logfile = $settings['log']['location']."/postprocess.log";
        $redis->rpush("service-runner","$update_script>$update_logfile");
        $result = "service-runner trigger sent";
        // -----------------------------------------------------------------

        $route->format = "json";
        return array('content'=>$params);
    }
    
    if ($route->action == "remove" && $session['write']) {
        $route->format = "text";
        
        if (!isset($_GET['processid'])) return "missing processid parameter";
        $processid = (int) $_GET['processid'];
        
        $processlist = $postprocess->get($session['userid']);
        
        if (isset($processlist[$processid])) {
            array_splice($processlist,$processid,1);
        } else {
            return "process does not exist";
        }
        $postprocess->set($session['userid'],$processlist);
        return "process removed";
    }

    if ($route->action == 'logpath') {
        return $settings['log']['location']."/postprocess.log";
    }

    if ($route->action == 'getlog') {
        $route->format = "text";
        $log_filename = $settings['log']['location']."/postprocess.log";
        if (file_exists($log_filename)) {
          ob_start();
          passthru("tail -30 $log_filename");
          $result = trim(ob_get_clean());
        } else $result="no logging yet available";
    }

    return array('content'=>$result, 'fullwidth'=>false);
}
