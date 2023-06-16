<?php

class PostProcess_to_signed extends PostProcess_common
{
    public function description() {
        return array(
            "name"=>"to_signed",
            "description"=>"Convert unsigned int to signed int",
            "settings"=>array(
                "feedid"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed to convert:"),
            )
        );
    }

    public function process($processitem)
    {
        if (!$this->validate($processitem)) return false;

        $dir = $this->dir;
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
        $dp_modified = 0;
        
        $stime = microtime(true);
        while ($dplefttoread>0)
        {
            fseek($fh,$fpos*4);

            // Read in a block of data 100,000 datapoints at a time
            // this is quite a bit faster than reading in one datapoint at a time
            $values = unpack("f*",fread($fh,4*$blocksize));
            $count = count($values);
            if ($count==0) break;
            for ($i=1; $i<=$count; $i++)
            {
                $dpos = $fpos + ($i-1);

                if (!is_nan($values[$i])) {
                    // convert to signed int 16
                    if ($values[$i] > 32767) {
                        $fixed_value = $values[$i] - 65536;
                        fseek($fh,$dpos*4);
                        fwrite($fh,pack("f",$fixed_value));
                    }
                }
                // if ((int)$dpos%((int)($npoints/10))==0) echo ".";
            }
            $dplefttoread -= $count;
            $fpos += $count;
        }
        fclose($fh);
        
        echo "\n";
        
        echo "to_signed: ".$dp_modified." datapoints, ".round(($dp_modified/$npoints)*100)."%\n";
        echo "time: ".(microtime(true)-$stime)."\n";
        return true;
    }
}