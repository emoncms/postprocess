<?php

class PostProcess_powertokwh extends PostProcess_common
{
    public function description() {
        return array(
            "name"=>"powertokwh",
            "group"=>"Main",
            "description"=>"Convert power feed to kWh feed",
            "settings"=>array(
                "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed:"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:"),
                "max_power"=>array("type"=>"value", "default"=>1000000, "short"=>"Enter max power limit (W):"),
                "min_power"=>array("type"=>"value", "default"=>-1000000, "short"=>"Enter min power limit (W):")
            )
        );
    }

    public function process($p)
    {
        $result = $this->validate($p);
        if (!$result["success"]) return $result;

        $im = getmeta($this->dir,$p->input);
        $om = getmeta($this->dir,$p->output);

        // Check that output feed is empty or has same start time and interval
        if ($om->npoints>0) {
            if ($im->start_time != $om->start_time) {
                return array("success"=>false, "message"=>"start time mismatch");
            }
            if ($im->interval != $om->interval) {
                return array("success"=>false, "message"=>"interval mismatch");
            }
        } else {
            // Copies over start_time to output meta file
            createmeta($this->dir,$p->output,$im);
        }

        // If recent mode, check that input feed has more points than output feed
        if ($p->process_mode=='recent' && $om->npoints >= $im->npoints) {
            return array("success"=>true, "message"=>"output feed already up to date");
        }
        
        if (!$if = @fopen($this->dir.$p->input.".dat", 'rb')) {
            return array("success"=>false, "message"=>"could not open input feed");
        }
        
        if (!$of = @fopen($this->dir.$p->output.".dat", 'c+')) {
            return array("success"=>false, "message"=>"could not open output feed");
        }
        
        $buffer = "";
        $wh = 0;
        $power = 0;
        $start_pos = 0;

        if ($p->process_mode=='from' && $om->npoints>0) {
            $start_pos = floor(($p->process_start - $im->start_time) / $im->interval);
            if ($start_pos<0) $start_pos = 0;
            if ($start_pos>$im->npoints) {
                return array("success"=>false, "message"=>"start time is after end of input feed");
            }
        }

        if ($p->process_mode=='recent' && $om->npoints>0) {
            $start_pos = $om->npoints;
        }

        if ($start_pos>0) {
            fseek($if,$start_pos*4);
            fseek($of,($start_pos-1)*4);
            $tmp = unpack("f",fread($of,4));
            $wh = $tmp[1]*1000.0;
        }
        
        $filtered_count = 0;
        
        for ($n=$start_pos; $n<$im->npoints; $n++) {
            $tmp = unpack("f",fread($if,4));
            
            if (!is_nan($tmp[1])) $power = 1*$tmp[1];
            
            // filter spurious power values +-1MW
            if ($power>=$p->min_power && $power<$p->max_power) {
                $wh += ($power * $im->interval) / 3600.0;
                $buffer .= pack("f",$wh*0.001);
            } else {
                $filtered_count++;
            }
        }
        
        fwrite($of,$buffer);
        
        if ($filtered_count>0) {
            print "Filtered count: $filtered_count\n";
        }
        
        print "bytes written: ".strlen($buffer)."\n";
        fclose($of);
        fclose($if);
        
        $time = $im->start_time + ($im->npoints * $im->interval);
        $value = $wh * 0.001;
        
        print "last time value: ".$time." ".$value."\n";
        updatetimevalue($p->output,$time,$value);
        
        return array("success"=>true);
    }
}
