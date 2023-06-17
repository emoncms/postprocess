<?php

class PostProcess_accumulator extends PostProcess_common
{
    public function description() {
        return array(
            "name"=>"accumulator",
            "group"=>"Misc",
            "description"=>"Accumulate a feed",
            "settings"=>array(
                "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed:"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:")
            )
        );
    }

    public function process($processitem)
    {
        $result = $this->validate($processitem);
        if (!$result["success"]) return $result;

        $dir = $this->dir;
        $input = $processitem->input;
        $output = $processitem->output;

        $im = getmeta($dir,$input);
        $om = getmeta($dir,$output);
        
        if ($om->npoints >= $im->npoints) {
            return array("success"=>true, "message"=>"output feed already up to date");
        }
        
        // Copies over start_time to output meta file
        createmeta($dir,$output,$im);

        if (!$if = @fopen($dir.$input.".dat", 'rb')) {
            return array("success"=>false, "message"=>"could not open input feed");
        }
        
        if (!$of = @fopen($dir.$output.".dat", 'c+')) {
            return array("success"=>false, "message"=>"could not open output feed");
        }
        
        $buffer = "";
        
        $total = 0;
        fseek($if,$om->npoints*4);
        if ($om->npoints>0) fseek($of,($om->npoints-1)*4);
        
        $tmp = unpack("f",fread($of,4));
        $total = $tmp[1];
        
        for ($n=$om->npoints; $n<$im->npoints; $n++) {
            $tmp = unpack("f",fread($if,4));
            
            if (!is_nan($tmp[1])) $value = 1*$tmp[1];
            
            $total += $value;
            
            $buffer .= pack("f",$total);
        }
        
        fwrite($of,$buffer);
        
        print "bytes written: ".strlen($buffer)."\n";
        fclose($of);
        fclose($if);
        
        $time = $im->start_time + ($im->npoints * $im->interval);
        
        print "last time value: ".$time." ".$total."\n";
        updatetimevalue($output,$time,$total);
        
        return array("success"=>true);
    }
}