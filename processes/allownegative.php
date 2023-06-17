<?php

class PostProcess_allownegative extends PostProcess_common
{
    public function description() {
        return array(
            "name"=>"allownegative",
            "group"=>"Limits",
            "description"=>"Allow only negative values",
            "settings"=>array(
                "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed:"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:", "nameappend"=>"")
            )
        );
    }

    public function process($processitem)
    {
        if (!$this->validate($processitem)) return false;

        $dir = $this->dir;
        $input = $processitem->input;
        $output = $processitem->output;

        $input_meta = getmeta($dir,$input);
        
        createmeta($dir,$output,$input_meta);
        $output_meta = getmeta($dir,$output);
        // if ($om->npoints >= $im->npoints) {
        //   print "output feed already up to date\n";
        //   return false;
        // }

        if (!$input_fh = @fopen($dir.$input.".dat", 'rb')) {
            echo "ERROR: could not open $dir $input.dat\n";
            return false;
        }
        
        if (!$output_fh = @fopen($dir.$output.".dat", 'c+')) {
            echo "ERROR: could not open $dir $output.dat\n";
            return false;
        }
        
        // get start position
        $start_pos = $output_meta->npoints;
        
        // get end position
        $end_pos = $input_meta->npoints;
        
        $buffer = "";
        
        fseek($input_fh,$start_pos*4);
        fseek($output_fh,$start_pos*4);
        
        for ($n=($start_pos+1); $n<=$end_pos; $n++) {
            $input_tmp = unpack("f",fread($input_fh,4));
            
            $value = $input_tmp[1];
            if (!is_nan($value)) {
                if ($value>0) $value = 0;
            }
            $buffer .= pack("f",$value);
        }
        
        fwrite($output_fh,$buffer);
        
        $byteswritten = strlen($buffer);
        print "bytes written: ".$byteswritten."\n";
        fclose($output_fh);
        fclose($input_fh);
        
        $time = $input_meta->start_time + ($input_meta->npoints * $input_meta->interval);
        
        if ($byteswritten>0) {
            print "last time value: ".$time." ".$value."\n";
            updatetimevalue($output,$time,$value);
        }
        return true;
    }
}