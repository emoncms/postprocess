<?php

class PostProcess_backfillfeed extends PostProcess_common
{
    public function description() {
        return array(
            "name"=>"Backfill feed",
            "group"=>"Feeds",
            "description"=>"Extend a feed that has stopped recording using data from another feed recording the same quantity (e.g. a redundant sensor). Data is only appended after the current end of the target feed, existing data is never modified. Where the source feed has a shorter interval the source values are averaged into each target interval.",
            "settings"=>array(
                "feed"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed to backfill:"),
                "source"=>array("type"=>"feed", "engine"=>5, "short"=>"Select source feed:")
            )
        );
    }

    public function process($params)
    {
        $result = $this->validate($params);
        if (!$result["success"]) return $result;

        if ((int) $params->feed == (int) $params->source) {
            return array("success"=>false, "message"=>"feed and source must be different feeds");
        }

        $target_meta = getmeta($this->dir,$params->feed);
        $source_meta = getmeta($this->dir,$params->source);
        $interval = $target_meta->interval;

        // Always start from the current end of the target feed, regardless of process mode,
        // so that existing data in the target feed is never modified
        $start_time = $target_meta->end_time;

        // Only write target intervals that are fully covered by the source feed,
        // so that a partially recorded interval is completed on the next run
        $end_time = $start_time + floor(($source_meta->end_time - $start_time) / $interval) * $interval;

        if ($start_time>=$end_time) {
            return array("success"=>true, "message"=>"no new data to process");
        }

        if (!$source_fh = @fopen($this->dir.$params->source.".dat", 'rb')) {
            return array("success"=>false, "message"=>"could not open source feed");
        }
        if (!$target_fh = @fopen($this->dir.$params->feed.".dat", 'c+')) {
            return array("success"=>false, "message"=>"could not open target feed");
        }
        fseek($target_fh, $target_meta->npoints * 4);

        $buffer = "";
        $byteswritten = 0;
        $last_time = false;
        $last_value = NAN;

        for ($time=$start_time; $time<$end_time; $time+=$interval)
        {
            // Source datapoints that fall within this target interval
            $pos_start = ceil(($time - $source_meta->start_time) / $source_meta->interval);
            $pos_end = ceil(($time + $interval - $source_meta->start_time) / $source_meta->interval);
            // Source interval longer than target interval: use the datapoint covering this interval
            if ($pos_end<=$pos_start) {
                $pos_start = floor(($time - $source_meta->start_time) / $source_meta->interval);
                $pos_end = $pos_start + 1;
            }
            if ($pos_start<0) $pos_start = 0;
            if ($pos_end>$source_meta->npoints) $pos_end = $source_meta->npoints;

            $sum = 0;
            $n = 0;
            if ($pos_end>$pos_start) {
                fseek($source_fh,$pos_start*4);
                $values = unpack("f*",fread($source_fh,($pos_end-$pos_start)*4));
                foreach ($values as $value) {
                    if (!is_nan($value)) {
                        $sum += $value;
                        $n++;
                    }
                }
            }

            $outval = NAN;
            if ($n>0) {
                $outval = $sum / $n;
                $last_time = $time;
                $last_value = $outval;
            }
            $buffer .= pack("f",$outval);

            if (strlen($buffer)>=400000) {
                $byteswritten += fwrite($target_fh,$buffer);
                $buffer = "";
            }
        }

        $byteswritten += fwrite($target_fh,$buffer);
        fclose($target_fh);
        fclose($source_fh);

        if ($last_time!==false) {
            updatetimevalue($params->feed,$last_time,$last_value);
        }
        return array("success"=>true, "message"=>"bytes written: ".$byteswritten.", last time value: ".$last_time." ".$last_value);
    }
}
