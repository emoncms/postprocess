<?php

function removenan($dir,$processitem)
{
    if (!isset($processitem->feedid)) return false;
    $id = $processitem->feedid;
   
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
    $nanfix = 0;
    
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
            if (is_nan($values[$i])) {
                $in_nan_period = 1;
            } else {
                $endval = $values[$i];
                if ($in_nan_period==1) {
                    $npoints2 = $dpos - $startpos;
                    $diff = ($endval - $startval) / $npoints2;
                    for ($p=1; $p<$npoints2; $p++)
                    {
                        fseek($fh,($startpos+$p)*4);
                        fwrite($fh,pack("f",$startval+($p*$diff)));
                        $nanfix++;
                    }
                }
                $startval = $endval;
                $startpos = $dpos;
                $in_nan_period = 0;
            }
            
            if ($dpos%($npoints/10)==0) echo ".";
        }
        $dplefttoread -= $count;
        $fpos += $count;
    }
    fclose($fh);
    
    echo "\n";
    
    echo "nanfix: ".$nanfix." datapoints, ".round(($nanfix/$npoints)*100)."%\n";
    echo "time: ".(microtime(true)-$stime)."\n";
    return true;
}
