<?php

function powertokwh($dir,$processitem)
{
    if (!isset($processitem->input)) return false;
    if (!isset($processitem->output)) return false;
    
    $input = $processitem->input;
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

    $im = getmeta($dir,$input);
    $om = getmeta($dir,$output);

    /*
    if ($im->interval != $om->interval) {
        print "feed intervals do not match\n";
        return false;
    }*/
    
    if ($om->npoints >= $im->npoints) {
        print "output feed already up to date\n";
        return false;
    }
    
    // Copies over start_time to output meta file
    createmeta($dir,$output,$im);

    if (!$if = @fopen($dir.$input.".dat", 'rb')) {
        echo "ERROR: could not open $dir $input.dat\n";
        return false;
    }
    
    if (!$of = @fopen($dir.$output.".dat", 'c+')) {
        echo "ERROR: could not open $dir $output.dat\n";
        return false;
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
    
    for ($n=$om->npoints; $n<$im->npoints; $n++) {
        $tmp = unpack("f",fread($if,4));
        
        if (!is_nan($tmp[1])) $power = 1*$tmp[1];
        
        // $joules += $power * $im->interval;
        // $wh += floor($joules / 3600.0);
        // $joules = $joules % 3600;
        
        $wh += ($power * $im->interval) / 3600.0;
        
        $buffer .= pack("f",$wh*0.001);
    }
    
    fwrite($of,$buffer);
    
    print "bytes written: ".strlen($buffer)."\n";
    fclose($of);
    fclose($if);
    
    $time = $im->start_time + ($im->npoints * $im->interval);
    $value = $wh * 0.001;
    
    print "last time value: ".$time." ".$value."\n";
    updatetimevalue($output,$time,$value);
    
    return true;
}
