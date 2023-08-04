<?php

class PostProcess_downsample extends PostProcess_common
{
    public function description()
    {
        return array(
            "name" => "Downsample",
            "group" => "Feeds",
            "description" => "Downsample a feed",
            "settings" => array(
                "feed" => array("type" => "feed", "engine" => 5, "short" => "Select feed to downsample:"),
                "new_interval" => array("type" => "value", "short" => "New feed interval:"),
                "backup" => array("type" => "newfeed", "engine" => 5, "short" => "Enter backup feed name:", "nameappend" => "", "optional" => true)
            )
        );
    }

    public function process($processitem)
    {
        $result = $this->validate($processitem);
        if (!$result["success"]) return $result;

        $dir = $this->dir;
        $feed = $processitem->feed;
        $new_interval = $processitem->new_interval;
        $backup = $processitem->backup;

        $input_meta = getmeta($dir, $feed);

        if ($input_meta->interval >= $new_interval) {
            return array("success" => false, "message" => "feed interval must be less than new interval");
        }

        $allowed_intervals = array(10, 15, 20, 30, 60, 120, 180, 300, 600, 900, 1800, 3600, 7200, 86400);
        if (!in_array($new_interval, $allowed_intervals)) {
            return array("success" => false, "message" => "invalid interval");
        }

        $output_meta = new stdClass();
        $output_meta->interval = $new_interval;
        $output_meta->start_time = floor($input_meta->start_time / $new_interval) * $new_interval;

        if ($backup) {
            copy($dir . $feed . ".meta", $dir . $backup . ".meta");
            copy($dir . $feed . ".dat", $dir . $backup . ".dat");
        }
        
        if (!$input_fh = @fopen($dir . $feed . ".dat", 'rb')) {
            return array("success" => false, "message" => "could not open input feed");
        }

        // get start position
        $start_pos = 0;

        // get end position
        $end_pos = $input_meta->npoints;

        $buffer = "";

        $last_interval = floor($input_meta->start_time / $new_interval) * $new_interval;

        $interval_sum = 0;
        $interval_n = 0;

        for ($n = $start_pos; $n < $end_pos; $n++) {
            $input_tmp = unpack("f", fread($input_fh, 4));

            $time = $input_meta->start_time + ($n * $input_meta->interval);
            $time_interval = floor($time / $new_interval) * $new_interval;

            if ($time_interval != $last_interval) {
                $mean = NAN;
                if ($interval_sum !== 0 && $interval_n > 0) {
                    $mean = $interval_sum / $interval_n;
                }
                $buffer .= pack("f", $mean);
                $interval_sum = 0;
                $interval_n = 0;
            }

            if (!is_nan($input_tmp[1])) {
                $interval_sum += 1 * $input_tmp[1];
                $interval_n++;
            }

            $last_interval = $time_interval;
        }
        fclose($input_fh);

        if (!$output_fh = @fopen($dir . $feed . ".dat", 'w')) {
            return array("success" => false, "message" => "could not open output feed");
        }
        fwrite($output_fh, $buffer);
        fclose($output_fh);

        $byteswritten = strlen($buffer);

        createmeta($dir, $feed, $output_meta);

        $time = $input_meta->start_time + ($input_meta->npoints * $input_meta->interval);

        if ($byteswritten > 0) {
            if (!is_nan($mean)) {
                print "last time value: " . $time . " " . $mean . "\n";
                updatetimevalue($feed, $time, $mean);
            }
        }
        return array("success"=>true, "message"=>"bytes written: ".$byteswritten.", last time value: ".$time." ".$mean);
    }
}
