<?php

// no direct access
defined('EMONCMS_EXEC') or die('Restricted access');

function postprocess_controller()
{
    global $session,$route,$mysqli,$redis,$feed_settings;
    global $postprocessauth;
    
    $result = false;
    $route->format = "text";

    include "Modules/feed/feed_model.php";
    $feed = new Feed($mysqli,$redis,$feed_settings);
    
    $processes = array(
        "powertokwh"=>array(
            "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:")
        ),
        "exportcalc"=>array(
            "generation"=>array("type"=>"feed", "engine"=>5, "short"=>"Select solar generation power feed:"),
            "consumption"=>array("type"=>"feed", "engine"=>5, "short"=>"Select consumption power feed:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter export feed name:", "nameappend"=>"")
        ),
        "trimfeedstart"=>array(
            "feedid"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed to trim:"),
            "trimtime"=>array("type"=>"value", "short"=>"Enter start time to trim from:")
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
        $processlist = json_decode($redis->get("postprocesslist:$userid"));
        if ($processlist==null) $processlist = array();
        
        $processlistout = array();
        
        for ($i=0; $i<count($processlist); $i++) {
            $valid = true;
            $process = $processlist[$i]->process;
            foreach ($processes[$process] as $key=>$option) 
            {
                if ($option['type']=="feed" || $option['type']=="newfeed") {
                    $id = $processlist[$i]->$key;
                    if ($feed->exist($id)) {
                        $f = $feed->get($id);
                        if ($f['userid']!=$session['userid']) return false;
                        $meta = $feed->get_meta($id);
                        $f['start_time'] = $meta->start_time;
                        $f['interval'] = $meta->interval;
                        $f['npoints'] = $meta->npoints;
                        $f['id'] = (int) $f['id'];
                        $processlist[$i]->$key = $f;
                    } else {
                        $valid = false;
                    }
                }
            }
            
            if ($valid) $processlistout[] = $processlist[$i];
        }
        
        if (json_encode($processlistout)!=json_encode($processlist)) {
            $redis->set("postprocesslist:$userid",json_encode($processlistout));
        }
        
        $result = $processlistout;
    
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
               $newfeedname = preg_replace('/[^\w\s-:]/','',$params->$key);
               if ($params->$key=="")
                   return array('content'=>"new feed name is blank");
               if ($newfeedname!=$params->$key)
                   return array('content'=>"new feed name contains invalid characters");
                if ($feed->get_id($session['userid'],$newfeedname)) 
                   return array('content'=>"feed already exists with name $newfeedname");
                   
                // New feed creation: note interval is 3600 this will be changed by the process to match input feeds..
                $c = $feed->create($session['userid'],$newfeedname,DataType::REALTIME,Engine::PHPFINA,json_decode('{"interval":3600}'));
                if (!$c['success'])
                    return array('content'=>"feed could not be created");
                    
                // replace new feed name with its id if successfully created
                $params->$key = $c['feedid'];
           }
           
           if ($option['type']=="value") {
               $value = (int) $params->$key;
               if ($value!=$params->$key)
                   return array('content'=>"invalid value");
           }
        }
        
        // If we got this far the input parameters where valid.
        
        $userid = $session['userid'];
        $processlist = json_decode($redis->get("postprocesslist:$userid"));
        if ($processlist==null) $processlist = array();
        
        $params->process = $process;
        $processlist[] = $params;
        
        $redis->set("postprocesslist:$userid",json_encode($processlist));
        $redis->lpush("postprocessqueue",json_encode($params));
        
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
           }
           
           if ($option['type']=="value") {
               $value = (int) $params->$key;
               if ($value!=$params->$key)
                   return array('content'=>"invalid value");
           }
        }
        
        // If we got this far the input parameters where valid.
        
        $userid = $session['userid'];
        $processlist = json_decode($redis->get("postprocesslist:$userid"));
        if ($processlist==null) $processlist = array();
        
        $params->process = $process;
        // Check to see if the process has already been registered
        $valid = false;
        for ($i=0; $i<count($processlist); $i++) {
            $tmp = $processlist[$i];
            // print "prm:".json_encode($params)."\n";
            // print "tmp:".json_encode($tmp)."\n";
            if (json_encode($tmp)==json_encode($params)) $valid = true;
        }
        if (!$valid) 
            return array('content'=>"process does not exist, please create");
        
        $redis->lpush("postprocessqueue",json_encode($params));
        
        $route->format = "json";
        return array('content'=>$params);
    }
    
    return array('content'=>$result, 'fullwidth'=>false);
}
