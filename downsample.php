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

    $input_meta = getmeta($dir,$input);
    
    $output_meta = $input_meta;
    $output_meta->interval = (int) $processitem->interval;
    if ($output_meta->interval<10) $output_meta->interval = 10;
    createmeta($dir,$output,$output_meta);
    
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
        
    $start_time = $input_meta->start_time;
    $end_time = $start_time + ($input_meta->npoints*$input_meta->interval);
    
    $buffer = "";
    
    $dp_to_read = $interval / $input_meta->interval;
    if (round($dp_to_read)!=$dp_to_read) {
        echo "Output interval is not an integer number of input interval\n";
        return false;
    }
    
    $mean = 0;
    
    for ($time=$start_time; $time<$end_time; $time+=$interval) {
    
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
        $mean = $sum / $n2;
        
        $buffer .= pack("f",$mean);
    }
    
    fwrite($output_fh,$buffer);
    
    $byteswritten = strlen($buffer);
    print "bytes written: ".$byteswritten."\n";
    
    fclose($output_fh);
    fclose($input_fh);
    
    if ($byteswritten>0) {
        print "last time value: ".$time." ".$mean."\n";
        updatetimevalue($output,$time,$mean);
    }
    return true;
}
