<?php

// these functions could ultimately be integrated into a class

function remove_morethan_lessthan_description() {
    return array(
        "name"=>"remove_morethan_lessthan",
        "description"=>"Remove values more than or less than a certain value",
        "settings"=>array(
            "feedid"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed to remove nan values:"),
            "morethan"=>array("type"=>"value", "short"=>"Remove values above this limit:"),
            "lessthan"=>array("type"=>"value", "short"=>"Remote values below this limit:")
        )
    );
}

function remove_morethan_lessthan($dir,$processitem)
{
    if (!isset($processitem->feedid)) return false;
    $id = $processitem->feedid;
    
    if (!isset($processitem->morethan)) return false;
    $morethan = $processitem->morethan;
    
    if (!isset($processitem->lessthan)) return false;
    $lessthan = $processitem->lessthan;
   
    if (!$fh = @fopen($dir.$id.".dat", 'c+')) {
        echo "ERROR: could not open $dir $id.dat\n";
        return false;
    }
    $npoints = floor(filesize($dir.$id.".dat") / 4.0);
    if ($npoints==0) {
        echo "ERROR: npoints is zero\n";
        return false;
    }
    $fpos = 0;
    $dplefttoread = $npoints;
    $blocksize = 100000;
    $in_nan_period = 0;
    $startval = 0;
    $startpos = 0;
    
    $fix_count = 0;
    
    $stime = microtime(true);
    while ($dplefttoread>0)
    {
        fseek($fh,$fpos*4);
        $values = unpack("f*",fread($fh,4*$blocksize));
        $count = count($values);
        if ($count==0) break;
        for ($i=1; $i<=$count; $i++)
        {
            $dpos = $fpos + ($i-1);
            
            if (!is_nan($values[$i])) {
                if ($values[$i]>$morethan) {
                    fseek($fh,$dpos*4);
                    fwrite($fh,pack("f",NAN));
                    $fix_count ++;
                }
                else if ($values[$i]<$lessthan) {
                    fseek($fh,$dpos*4);
                    fwrite($fh,pack("f",NAN));
                    $fix_count ++;
                }
            }
            
            if ($dpos%($npoints/10)==0) echo ".";
        }
        $dplefttoread -= $count;
        $fpos += $count;
    }
    fclose($fh);
    
    echo "\n";
    
    echo "nanfix: ".$fix_count." datapoints, ".round(($fix_count/$npoints)*100)."%\n";
    echo "time: ".(microtime(true)-$stime)."\n";
    return true;
}
