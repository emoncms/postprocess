<?php

class PostProcess_removenan_lastvalue extends PostProcess_common
{
    public function description() {
        return array(
            "name"=>"Replace missing values with last value",
            "group"=>"Data cleanup",
            "description"=>"Remove missing data points from a feed by replacing with last value",
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

        $value = NAN;
        
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
                    fseek($fh,($dpos)*4);
                    fwrite($fh,pack("f",$value));
                    $nanfix++;
                } else {
                    $value = $values[$i];
                }
            }
            $dplefttoread -= $count;
            $fpos += $count;
        }
        fclose($fh);
        echo "time: ".(microtime(true)-$stime)."\n";
        return array("success" => true, "message"=>"nanfix: ".$nanfix." datapoints, ".round(($nanfix/$npoints)*100)."%\n");
    }
}