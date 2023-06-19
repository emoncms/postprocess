<?php

class PostProcess_solardirectcalc extends PostProcess_common
{
    public function description() {
        return array(
            "name"=>"solardirectcalc",
            "group"=>"Solar",
            "description"=>"Calculate direct use of solar",
            "settings"=>array(
                "consumption"=>array("type"=>"feed", "engine"=>5, "short"=>"Select consumption power feed:"),
                "generation"=>array("type"=>"feed", "engine"=>5, "short"=>"Select solar generation power feed:"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter solar direct feed name:", "nameappend"=>"")
            )
        );
    }

    public function process($processitem)
    {
        $result = $this->validate($processitem);
        if (!$result["success"]) return $result;
        
        $dir = $this->dir;
        
        $feedA = $processitem->consumption;
        $feedB = $processitem->generation;
        $output = $processitem->output;

        $feedA_meta = getmeta($dir,$feedA);
        $feedB_meta = getmeta($dir,$feedB);
        
        if ($feedA_meta->interval != $feedB_meta->interval) {
            print "NOTICE: interval of feeds do not match, feedA:$feedA_meta->interval, feedB:$feedB_meta->interval\n";
        }
        
        print "feedA start_time=$feedA_meta->start_time interval=$feedA_meta->interval\n";
        print "feedB start_time=$feedB_meta->start_time interval=$feedB_meta->interval\n";
        
        $feedA_interval_selected = false;
        $feedB_interval_selected = false;
        if ($feedA_meta->interval==$feedB_meta->interval) $out_interval = $feedA_meta->interval;
        if ($feedA_meta->interval>$feedB_meta->interval) { $out_interval = $feedA_meta->interval; $feedA_interval_selected = true; } 
        if ($feedA_meta->interval<$feedB_meta->interval) { $out_interval = $feedB_meta->interval; $feedB_interval_selected = true; } 
        
        $out_start_time = 0;
        if ($feedA_meta->start_time==$feedB_meta->start_time) $out_start_time = (int) $feedA_meta->start_time;
        if ($feedA_meta->start_time<$feedB_meta->start_time) $out_start_time = (int) $feedA_meta->start_time;
        if ($feedA_meta->start_time>$feedB_meta->start_time) $out_start_time = (int) $feedB_meta->start_time;
        
        $out_start_time = floor($out_start_time / $out_interval) * $out_interval;
        
        $out_meta = new stdClass();
        $out_meta->start_time = $out_start_time;
        $out_meta->interval = $out_interval;
        
        print "OUT start_time=$out_start_time interval=$out_interval\n";
        
        createmeta($dir,$output,$out_meta);
        
        $output_meta = getmeta($dir,$output);

        if (!$feedA_fh = @fopen($dir.$feedA.".dat", 'rb')) {
            echo "ERROR: could not open $dir $feedA.dat\n";
            return false;
        }
        
        if (!$feedB_fh = @fopen($dir.$feedB.".dat", 'rb')) {
            echo "ERROR: could not open $dir $feedB.dat\n";
            return false;
        }
        
        if (!$output_fh = @fopen($dir.$output.".dat", 'ab')) {
            echo "ERROR: could not open $dir $output.dat\n";
            return false;
        }
        
        // Work out start and end time of merged feeds:
        $feedA_end_time = $feedA_meta->start_time + ($feedA_meta->interval * $feedA_meta->npoints);
        $feedB_end_time = $feedB_meta->start_time + ($feedB_meta->interval * $feedB_meta->npoints);
        
        $start_time = $output_meta->start_time + ($output_meta->npoints * $output_meta->interval);
        $end_time = $feedA_end_time;
        if ($feedB_end_time>$feedA_end_time) $end_time = $feedB_end_time;
        
        $interval = $output_meta->interval;
        
        $buffer = "";
        for ($time=$start_time; $time<$end_time; $time+=$interval) 
        {
            $posA = floor(($time - $feedA_meta->start_time) / $feedA_meta->interval);
            $posB = floor(($time - $feedB_meta->start_time) / $feedB_meta->interval);
        
            $useval = NAN;
            $genval = NAN;
        
            if ($posA>=0 && $posA<$feedA_meta->npoints) {
                fseek($feedA_fh,$posA*4);
                $feedA_tmp = unpack("f",fread($feedA_fh,4));
                $useval = $feedA_tmp[1];
            }

            if ($posB>=0 && $posB<$feedB_meta->npoints) {
                fseek($feedB_fh,$posB*4);
                $feedB_tmp = unpack("f",fread($feedB_fh,4));
                $genval = $feedB_tmp[1];
            }
            
            $directval = NAN;
            if (!is_nan($useval) && !is_nan($genval)) {
                if ($genval>$useval) $genval = $useval;
                $directval = $genval;
            }
            $buffer .= pack("f",$directval*1.0);
        }
        
        fwrite($output_fh,$buffer);
        
        $byteswritten = strlen($buffer);
        print "bytes written: ".$byteswritten."\n";
        fclose($output_fh);
        fclose($feedA_fh);
        fclose($feedB_fh);
        
        if ($byteswritten>0) {
            print "last time value: ".$time." ".$directval."\n";
            updatetimevalue($output,$time,$directval);
        }
        return array("success"=>true);
    }
}