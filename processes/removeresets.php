<?php

// these functions could ultimately be integrated into a class

function removeresets_description() {
    return array(
        "name"=>"removeresets",
        "description"=>"Remove resets from a feed",
        "settings"=>array(
            "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed:"),
            "maxrate"=>array("type"=>"value", "short"=>"Max accumulation rate:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:")
        )
    );
}

function removeresets($dir,$processitem)
{
    if (!isset($processitem->input)) return false;
    if (!isset($processitem->maxrate)) return false;
    if (!isset($processitem->output)) return false;
    
    $input = $processitem->input;
    $maxrate = $processitem->maxrate;
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
    $total = 0;
    
    fseek($if,$om->npoints*4);
    if ($om->npoints>0) fseek($of,($om->npoints-1)*4);
    
    $tmp = unpack("f",fread($of,4));
    $value = 1*$tmp[1];
    
    for ($n=$om->npoints; $n<$im->npoints; $n++) {
        $tmp = unpack("f",fread($if,4));
        
        $last_value = $value;
        if (!is_nan($tmp[1])) $value = 1*$tmp[1];
        
        $diff = $value - $last_value;
        
        $rate = $diff / $im->interval;
        
        if ($diff>0 && $rate<$maxrate) $total += $diff;
        $buffer .= pack("f",$total);
    }
    
    fwrite($of,$buffer);
    
    print "bytes written: ".strlen($buffer)."\n";
    fclose($of);
    fclose($if);

    $time = $im->start_time + ($im->npoints * $im->interval);
    print "last time value: ".$time." ".$total."\n";
    updatetimevalue($output,$time,$total);
    
    return true;
}
