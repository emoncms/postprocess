<?php

// ---------------------------------------------------------------------------------------
// Produce a feed that is a fixed interval average of the source feed
// - Can be used to downsample feeds that are of too high resolution
// - Ideal for coverting 5s data feeds to 10s, or 10s data to half hourly averages
// - Does not work with daily or monthly intervals that are timezone dependent
// ---------------------------------------------------------------------------------------
function average($dir,$processitem)
{
    if (!isset($processitem->input)) return false;
    if (!isset($processitem->output)) return false;
    if (!isset($processitem->interval)) return false;
    
    // Input and output feed ids
    $input = $processitem->input;
    $output = $processitem->output;
    $output_interval = $processitem->interval;
    
    // Check if feeds exist
    if (!file_exists($dir.$input.".meta")) {
        print "input file $input.meta does not exist\n";
        return false;
    }

    if (!file_exists($dir.$output.".meta")) {
        print "output file $output.meta does not exist\n";
        return false;
    }

    // Load feed meta data
    $im = getmeta($dir,$input);
    print "input meta: ".json_encode($im)."\n";
    
    $om = getmeta($dir,$output);

    // Set output meta interval
    if ($output_interval<20) $output_interval = 20;
    $om->interval = round($output_interval/10)*10;
    
    // Calculate starting time of output feed
    $om->start_time = floor($im->start_time / $om->interval) * $om->interval;
    
    // Copies over start_time to output meta file
    createmeta($dir,$output,$om);
    
    print "output meta: ".json_encode($om)."\n";
    
    // Calculate the number of datapoints in the source feed that we will be averaging
    $dp_to_average = $om->interval / $im->interval;
    
    // Input feed must be a multiple of the input feed interval
    if (round($dp_to_average)!=$dp_to_average) {
        echo "The output feed interval is not a multiple of the input feed interval\n";
        return false;
    }
    // Must have at least 2 datapoints to average
    if ($dp_to_average<2) {
        echo "Averaged feed must have at least 2 datapoints in the input feed to average\n";
        return false;
    }
    
    // Open input feed to read binary
    if (!$if = @fopen($dir.$input.".dat", 'rb')) {
        echo "ERROR: could not open $dir $input.dat\n";
        return false;
    }
    
    $buffer = "";
    
    $sum = 0;
    $count = 0;
    $average = 0;
    
    $av_time = floor($im->start_time/$om->interval)*$om->interval;
    $last_av_time = $av_time;
    
    for ($n=0; $n<$im->npoints; $n++) {

        // Calculate current datapoint time
        $time = $im->start_time + ($n * $im->interval);   
        
        // Calculate output average datapoint time
        $last_av_time = $av_time;
        $av_time = floor($time/$om->interval)*$om->interval;
       
        // If we cross to next time interval then add average to output feed bugger
        if ($av_time!=$last_av_time) {
            $average = NAN;
            if ($count!=0) $average = $sum / $count;
            $sum = 0;
            $count = 0;
            $buffer .= pack("f",$average);
        }
        
        // Read in data point from input feed
        // Add to sum and increase count if a valid number
        $tmp = unpack("f",fread($if,4));
        if (!is_nan($tmp[1])) {
            $value = 1*$tmp[1];
            $sum += $value;
            $count++;
        }
    }
    fclose($if);
    
    // Open output feed to write
    if (!$of = @fopen($dir.$output.".dat", 'wb')) {
        echo "ERROR: could not open $dir $output.dat\n";
        return false;
    }
    fwrite($of,$buffer);
    fclose($of);
    print "bytes written: ".strlen($buffer)."\n";
    
    print "last time value: ".$last_av_time." ".$average."\n";
    // updatetimevalue($output,$last_av_time,$average);
    
    return true;
}
