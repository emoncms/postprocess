<?php

class PostProcess_removenan extends PostProcess_common
{
    public function description() {
        return array(
            "name"=>"Remove missing values",
            "group"=>"Data cleanup",
            "description"=>"Remove missing data points from a feed by interpolating between values",
            "settings"=>array(
                "feedid"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed to remove nan values:"),
            )
        );
    }

    public function process($processitem)
    {
        $result = $this->validate($processitem);
        if (!$result["success"]) return $result;
        
        $dir = $this->dir;
        $id = $processitem->feedid;
        
        if (!$fh = @fopen($dir.$id.".dat", 'c+')) {
            return array("success"=>false, "message"=>"could not open input feed");
        }
        if (!$npoints = floor(filesize($dir.$id.".dat") / 4.0)) {
            return array("success"=>false, "message"=>"feed is empty");
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
                
                // if ($dpos%($npoints/10)==0) echo ".";
            }
            $dplefttoread -= $count;
            $fpos += $count;
        }
        fclose($fh);
        echo "time: ".(microtime(true)-$stime)."\n";
        return array("success" => true, "message"=>"nanfix: ".$nanfix." datapoints, ".round(($nanfix/$npoints)*100)."%\n");
    }
}