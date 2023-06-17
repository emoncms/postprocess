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
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:")
            )
        );
    }

    public function process($p)
    {
        $result = $this->validate($p);
        if (!$result["success"]) return $result;

        $im = getmeta($this->dir,$p->input);
        $om = getmeta($this->dir,$p->output);
        
        if ($om->npoints >= $im->npoints) {
            return array("success"=>true, "message"=>"output feed already up to date");
        }
        
        // Copies over start_time to output meta file
        createmeta($this->dir,$p->output,$im);

        if (!$if = @fopen($this->dir.$p->input.".dat", 'rb')) {
            return array("success"=>false, "message"=>"could not open input feed");
        }
        
        if (!$of = @fopen($this->dir.$p->output.".dat", 'c+')) {
            return array("success"=>false, "message"=>"could not open output feed");
        }
        
        $buffer = "";
        
        $wh = 0;
        $joules = 0;
        $power = 0;
        fseek($if,$om->npoints*4);
        if ($om->npoints>0) {
            fseek($of,($om->npoints-1)*4);
            $tmp = unpack("f",fread($of,4));
            $wh = $tmp[1]*1000.0;
        }
        
        $spurious_value_count = 0;
        
        for ($n=$om->npoints; $n<$im->npoints; $n++) {
            $tmp = unpack("f",fread($if,4));
            
            if (!is_nan($tmp[1])) $power = 1*$tmp[1];
            
            // filter spurious power values +-1MW
            if ($power>-1000000.0 && $power<1000000.0) { 
                
                // $joules += $power * $im->interval;
                // $wh += floor($joules / 3600.0);
                // $joules = $joules % 3600;
                
                $wh += ($power * $im->interval) / 3600.0;
                $buffer .= pack("f",$wh*0.001);
            } else {
                $spurious_value_count++;
            }
        }
        
        fwrite($of,$buffer);
        
        if ($spurious_value_count>0) {
            print "-------------------------------------------------------";
            print "Found and filtered $spurious_value_count values +-1MW\n";
            print "-------------------------------------------------------";
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
