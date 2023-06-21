<?php

class PostProcess_exportcalc extends PostProcess_common
{
    public function description() {
        return array(
            "name"=>"Export calculation",
            "group"=>"Solar",
            "description"=>"Calculate grid export from consumption and generation",
            "settings"=>array(
                "generation"=>array("type"=>"feed", "engine"=>5, "short"=>"Select solar generation power feed:"),
                "consumption"=>array("type"=>"feed", "engine"=>5, "short"=>"Select consumption power feed:"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter export feed name:", "nameappend"=>"")
            )
        );
    }

    public function process($params)
    {
        $result = $this->validate($params);
        if (!$result["success"]) return $result;
        
        $use_meta = getmeta($this->dir,$params->use);
        $gen_meta = getmeta($this->dir,$params->gen);

        // Find longest interval
        $interval = $use_meta->interval;
        if ($gen_meta->interval>$interval) $interval = $gen_meta->interval;
        // Find latest start time
        $start_time = $use_meta->start_time;
        if ($gen_meta->start_time>$start_time) $start_time = $gen_meta->start_time;
        // Round start time down to nearest interval
        $start_time = floor($start_time / $interval) * $interval;
        
        // Create output feed meta file
        $out_meta = new stdClass();
        $out_meta->start_time = $start_time;
        $out_meta->interval = $interval;
        createmeta($this->dir,$params->out,$out_meta);
        $out_meta = getmeta($this->dir,$params->out);

        // Find end time of input feeds
        $use_end_time = $use_meta->start_time + ($use_meta->interval * $use_meta->npoints);
        $gen_end_time = $gen_meta->start_time + ($gen_meta->interval * $gen_meta->npoints);
        
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
        $end_time = $use_end_time;
        if ($gen_end_time<$end_time) $end_time = $gen_end_time;

        if ($start_time>=$end_time) {
            return array("success"=>true, "message"=>"no new data to process");
        }
        
        // Open input and output feeds
        if (!$use_fh = @fopen($this->dir.$params->use.".dat", 'rb')) {
            return array("success"=>false, "message"=>"could not open consumption feed");
        }
        if (!$gen_fh = @fopen($this->dir.$params->gen.".dat", 'rb')) {
            return array("success"=>false, "message"=>"could not open generation feed");
        }
        if (!$out_fh = @fopen($this->dir.$params->out.".dat", 'ab')) {
            return array("success"=>false, "message"=>"could not open output feed");
        }

        $buffer = "";
        for ($time=$start_time; $time<$end_time; $time+=$interval) 
        {
            $pos_use = floor(($time - $use_meta->start_time) / $use_meta->interval);
            $pos_gen = floor(($time - $gen_meta->start_time) / $gen_meta->interval);
        
            $useval = NAN;
            $genval = NAN;
        
            if ($pos_use>=0 && $pos_use<$use_meta->npoints) {
                fseek($use_fh,$pos_use*4);
                $tmp = unpack("f",fread($use_fh,4));
                $useval = $tmp[1];
            }

            if ($pos_gen>=0 && $pos_gen<$gen_meta->npoints) {
                fseek($gen_fh,$pos_gen*4);
                $tmp = unpack("f",fread($gen_fh,4));
                $genval = $tmp[1];
            }

            $exportval = NAN;
            if (!is_nan($genval)) $exportval = $genval;
            if (!is_nan($genval) && !is_nan($useval)) $exportval = $genval - $useval;
            if ($exportval<0) $exportval = 0;
            
            $buffer .= pack("f",$exportval*1.0);
        }
        
        fwrite($out_fh,$buffer);
        fclose($out_fh);
        fclose($use_fh);
        fclose($gen_fh);

        $byteswritten = strlen($buffer);
        if ($byteswritten>0) {
            updatetimevalue($params->out,$time,$exportval);
        }
        return array("success"=>true, "message"=>"bytes written: ".$byteswritten.", last time value: ".$time." ".$exportval);
    }
}