<?php

class PostProcess_scalefeed extends PostProcess_common
{
    public function description() {
        return array(
            "name"=>"Scale feed",
            "group"=>"Calibration",
            "description"=>"Multiply a feed by a constant value",
            "order"=>1,
            "settings"=>array(
                "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed to scale:"),
                "scale"=>array("type"=>"value", "short"=>"Scale by:"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:", "nameappend"=>"")
            )
        );
    }

    public function process($params)
    {
        $result = $this->validate($params);
        if (!$result["success"]) return $result;

        $input_meta = getmeta($this->dir,$params->input);
        $output_meta = getmeta($this->dir,$params->output);

        // Check that output feed is empty or has same start time and interval
        if ($output_meta->npoints>0) {
            if ($input_meta->start_time != $output_meta->start_time) {
                return array("success"=>false, "message"=>"start time mismatch");
            }
            if ($input_meta->interval != $output_meta->interval) {
                return array("success"=>false, "message"=>"interval mismatch");
            }
        } else {
            // Copies over start_time to output meta file
            createmeta($this->dir,$params->output,$input_meta);
        }

        // If recent mode, check that input feed has more points than output feed
        if ($params->process_mode=='recent' && $output_meta->npoints >= $input_meta->npoints) {
            return array("success"=>true, "message"=>"output feed already up to date");
        }
        
        if (!$if = @fopen($this->dir.$params->input.".dat", 'rb')) {
            return array("success"=>false, "message"=>"could not open input feed");
        }
        
        if (!$of = @fopen($this->dir.$params->output.".dat", 'c+')) {
            return array("success"=>false, "message"=>"could not open output feed");
        }
        
        $buffer = "";
        $start_pos = 0;

        if ($params->process_mode=='from' && $output_meta->npoints>0) {
            $start_pos = floor(($params->process_start - $input_meta->start_time) / $input_meta->interval);
            if ($start_pos<0) $start_pos = 0;
            if ($start_pos>$input_meta->npoints) {
                return array("success"=>false, "message"=>"start time is after end of input feed");
            }
        }

        if ($params->process_mode=='recent' && $output_meta->npoints>0) {
            $start_pos = $output_meta->npoints;
        }

        if ($start_pos>0) {
            fseek($if,$start_pos*4);
            fseek($of,$start_pos*4);
        }
        
        for ($n=$start_pos; $n<$input_meta->npoints; $n++) {
            $tmp = unpack("f",fread($if,4));
            
            $value = NAN;
            if (!is_nan($tmp[1])) {
                $value = 1*$tmp[1]*$params->scale;
            }
            $buffer .= pack("f",$value);
        }
        
        fwrite($of,$buffer);
        fclose($of);
        fclose($if);
        
        $time = $input_meta->start_time + ($input_meta->npoints * $input_meta->interval);
        
        $byteswritten = strlen($buffer);
        if ($byteswritten>0) {
            updatetimevalue($params->output,$time,$value);
        }
        return array("success"=>true, "message"=>"bytes written: ".$byteswritten.", last time value: ".$time." ".$value);    
    }
}