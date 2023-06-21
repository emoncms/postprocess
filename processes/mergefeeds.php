<?php

class PostProcess_mergefeeds extends PostProcess_common
{
    public function description() {
        return array(
            "name"=>"Merge feeds",
            "group"=>"Feeds",
            "description"=>"Merge two feeds together. If missing data is found in one feed, the other feed is used to fill in the gaps. If data is available for both feeds, the average is taken.",
            "settings"=>array(
                "feedA"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed A:"),
                "feedB"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed B:"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:", "nameappend"=>"")
            )
        );
    }

    public function process($params)
    {
        $result = $this->validate($params);
        if (!$result["success"]) return $result;
        
        $feedA_meta = getmeta($this->dir,$params->feedA);
        $feedB_meta = getmeta($this->dir,$params->feedB);

        // Find longest interval
        $interval = $feedA_meta->interval;
        if ($feedB_meta->interval>$interval) $interval = $feedB_meta->interval;
        // Find latest start time
        $start_time = $feedA_meta->start_time;
        if ($feedB_meta->start_time>$start_time) $start_time = $feedB_meta->start_time;
        // Round start time down to nearest interval
        $start_time = floor($start_time / $interval) * $interval;
        
        // Create output feed meta file
        $out_meta = new stdClass();
        $out_meta->start_time = $start_time;
        $out_meta->interval = $interval;
        createmeta($this->dir,$params->output,$out_meta);
        $out_meta = getmeta($this->dir,$params->output);

        // Find end time of input feeds
        $feedA_end_time = $feedA_meta->start_time + ($feedA_meta->interval * $feedA_meta->npoints);
        $feedB_end_time = $feedB_meta->start_time + ($feedB_meta->interval * $feedB_meta->npoints);
        
        // Start time of this process
        if ($params->process_mode=='recent') {
            $start_time = $out_meta->start_time + ($out_meta->npoints * $out_meta->interval);
        } else if ($params->process_mode=='recent') {
            $start_time = $params->process_start;
            if ($start_time<$out_meta->start_time) $start_time = $out_meta->start_time;
        } else {
            $start_time = $out_meta->start_time;
        }

        // End time of this process
        $end_time = $feedA_end_time;
        if ($feedB_end_time<$end_time) $end_time = $feedB_end_time;

        if ($start_time>=$end_time) {
            return array("success"=>true, "message"=>"no new data to process");
        }
        
        // Open input and output feeds
        if (!$feedA_fh = @fopen($this->dir.$params->feedA.".dat", 'rb')) {
            return array("success"=>false, "message"=>"could not open feedA feed");
        }
        if (!$feedB_fh = @fopen($this->dir.$params->feedB.".dat", 'rb')) {
            return array("success"=>false, "message"=>"could not open feedB feed");
        }
        if (!$out_fh = @fopen($this->dir.$params->output.".dat", 'ab')) {
            return array("success"=>false, "message"=>"could not open output feed");
        }

        $buffer = "";
        for ($time=$start_time; $time<$end_time; $time+=$interval) 
        {
            $pos_feedA = floor(($time - $feedA_meta->start_time) / $feedA_meta->interval);
            $pos_feedB = floor(($time - $feedB_meta->start_time) / $feedB_meta->interval);
        
            $valueA = NAN;
            $valueB = NAN;
        
            if ($pos_feedA>=0 && $pos_feedA<$feedA_meta->npoints) {
                fseek($feedA_fh,$pos_feedA*4);
                $tmp = unpack("f",fread($feedA_fh,4));
                $valueA = $tmp[1];
            }

            if ($pos_feedB>=0 && $pos_feedB<$feedB_meta->npoints) {
                fseek($feedB_fh,$pos_feedB*4);
                $tmp = unpack("f",fread($feedB_fh,4));
                $valueB = $tmp[1];
            }

            $outval = NAN;
            if (!is_nan($valueA)) $outval = $valueA;
            if (!is_nan($valueB)) $outval = $valueB;
            if (!is_nan($valueA) && !is_nan($valueB)) $outval = ($valueB+$valueA)*0.5;
            $buffer .= pack("f",$outval*1.0);
        }
        
        fwrite($out_fh,$buffer);
        fclose($out_fh);
        fclose($feedA_fh);
        fclose($feedB_fh);
        
        $byteswritten = strlen($buffer);
        if ($byteswritten>0) {
            updatetimevalue($params->output,$time,$outval);
        }
        return array("success"=>true, "message"=>"bytes written: ".$byteswritten.", last time value: ".$time." ".$outval);
    }
}