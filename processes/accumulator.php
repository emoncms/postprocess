<?php

class PostProcess_accumulator extends PostProcess_common
{
    public function description() {
        return array(
            "name"=>"Accumulator",
            "group"=>"Misc",
            "description"=>"Accumulate the sum total of all the values in a feed.",
            "settings"=>array(
                "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed:"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:"),
                "max_value"=>array("type"=>"value", "default"=>1000000, "short"=>"Enter max value limit:"),
                "min_value"=>array("type"=>"value", "default"=>-1000000, "short"=>"Enter min value limit:")
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
        $total = 0;
        $value = 0;
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
            fseek($of,($start_pos-1)*4);
            $tmp = unpack("f",fread($of,4));
            $total = $tmp[1]*1;
        }
        
        $filtered_count = 0;
        
        for ($n=$start_pos; $n<$input_meta->npoints; $n++) {
            $tmp = unpack("f",fread($if,4));
            
            if (!is_nan($tmp[1])) $value = 1*$tmp[1];
            
            // filter spurious values +-1M
            if ($value>=$params->min_value && $value<$params->max_value) {
                $total += $value;
                $buffer .= pack("f",$total);
            } else {
                $filtered_count++;
            }
        }
        
        fwrite($of,$buffer);
        
        if ($filtered_count>0) {
            print "Filtered count: $filtered_count\n";
        }
        fclose($of);
        fclose($if);
        
        $time = $input_meta->start_time + ($input_meta->npoints * $input_meta->interval);
        
        $byteswritten = strlen($buffer);
        if ($byteswritten>0) {
            updatetimevalue($params->output,$time,$total);
        }
        return array("success"=>true, "message"=>"bytes written: ".$byteswritten.", last time value: ".$time." ".$total);

    }
}