<?php

function downsample($dir,$processitem)
{
    if (!isset($processitem->input)) return false;
    if (!isset($processitem->interval)) return false;
    if (!isset($processitem->output)) return false;
    
    $input = $processitem->input;
    $interval = $processitem->interval;
    $output = $processitem->output;
    // --------------------------------------------------
    
    if (!file_exists($dir.$input.".meta")) {
        print "input file $input.meta does not exist\n";
        return false;
    }
    
    if (!file_exists($dir.$output.".meta")) {
        print "output file $output.meta does not exist\n";
        return false;
    }
    
    copy($dir.$input.".meta",$dir.$output.".meta");
    copy($dir.$input.".dat",$dir.$output.".dat");

    $input_meta = getmeta($dir,$input);
    // unlink
    $output_meta = json_decode(json_encode($input_meta));
    
    $output_meta->interval = (int) $processitem->interval;
    if ($output_meta->interval<10) $output_meta->interval = 10;
    
    print "output meta interval: ".$output_meta->interval."\n";

    if (!$input_fh = @fopen($dir.$input.".dat", 'rb')) {
        echo "ERROR: could not open $dir $input.dat\n";
        return false;
    }
        
    $start_time = $input_meta->start_time;
    $end_time = $start_time + ($input_meta->npoints*$input_meta->interval);
    
    print "start_time:$start_time, end_time:$end_time\n";
    print "input_interval: ".$input_meta->interval."\n";
    print "output_interval: ".$output_meta->interval."\n";
    
    $buffer = "";
    
    $dp_to_read = $interval / $input_meta->interval;
    if (round($dp_to_read)!=$dp_to_read) {
        echo "Output interval is not an integer number of input interval\n";
        return false;
    }
    
    $mean = 0;
    
    for ($time=$start_time; $time<$end_time; $time+=$output_meta->interval) {
    
        $input_pos = floor(($time - $input_meta->start_time) / $input_meta->interval);
        fseek($input_fh,$input_pos*4);
        
        $sum = 0; $n2 = 0;
        for ($n=0; $n<$dp_to_read; $n++) {
            $input_tmp = unpack("f",fread($input_fh,4));
            if (!is_nan($input_tmp[1])) {
                $sum += 1*$input_tmp[1];
                $n2 ++;
            }
        }
        $mean = NAN;
        if ($n2>0) $mean = $sum / $n2;
        
        $buffer .= pack("f",$mean);
    }
    fclose($input_fh);
        
    createmeta($dir,$input,$output_meta);
    if (!$output_fh = @fopen($dir.$input.".dat", 'wb')) {
        echo "ERROR: could not open $dir $input.dat\n";
        return false;
    }
    fwrite($output_fh,$buffer);
    fclose($output_fh);
    
    $byteswritten = strlen($buffer);
    print "bytes written: ".$byteswritten."\n";
    
    if ($byteswritten>0) {
        print "last time value: ".$time." ".$mean."\n";
        updatetimevalue($output,$time,$mean);
    }
    return true;
}
