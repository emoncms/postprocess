<?php

class PostProcess_upsample extends PostProcess_common
{
    public function description()
    {
        return array(
            "name" => "Upsample",
            "group" => "Feeds",
            "description" => "Upsample a feed",
            "settings" => array(
                "feed" => array("type" => "feed", "engine" => 5, "short" => "Select feed to upsample:"),
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

        // Input feed e.g at 600s interval
        $input_meta = getmeta($dir, $feed);

        // Check if new interval is greater than input interval
        if ($input_meta->interval < $new_interval) {
            return array("success" => false, "message" => "feed interval must be greater than new interval");
        }

        // Allowed intervals
        $allowed_intervals = array(10, 15, 20, 30, 60, 120, 180, 300, 600, 900, 1800, 3600, 7200, 86400);
        if (!in_array($new_interval, $allowed_intervals)) {
            return array("success" => false, "message" => "invalid interval");
        }

        // Create new meta for output feed
        // - interval is the new interval
        // - start_time is the start time of the input feed rounded to the new interval
        $output_meta = new stdClass();
        $output_meta->interval = $new_interval;
        $output_meta->start_time = floor($input_meta->start_time / $new_interval) * $new_interval;

        // Create backup feed
        if ($backup) {
            copy($dir . $feed . ".meta", $dir . $backup . ".meta");
            copy($dir . $feed . ".dat", $dir . $backup . ".dat");
        }
        
        // Open input feed
        if (!$input_fh = @fopen($dir . $feed . ".dat", 'rb')) {
            return array("success" => false, "message" => "could not open input feed");
        }

        $buffer = "";

        $value = null;

        $out_pos = 0;

        // Itterate through the input feed
        for ($i=0; $i<$input_meta->npoints; $i++) {
            $time = $input_meta->start_time + ($i * $input_meta->interval);
            $value = unpack("f", fread($input_fh, 4))[1];

            $last_pos = $out_pos;
            $out_pos = floor($time - $output_meta->start_time) / $output_meta->interval;

            $padding = $out_pos - $last_pos - 1;
            if ($padding > 0) {
                // add last value to padding
                for ($j=0; $j<$padding; $j++) {
                    $buffer .= pack("f", $value);
                }
            }

            $buffer .= pack("f", $value);
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
            if (!is_nan($value)) {
                print "last time value: " . $time . " " . $value . "\n";
                updatetimevalue($feed, $time, $value);
            }
        }
        return array("success"=>true, "message"=>"bytes written: ".$byteswritten.", last time value: ".$time." ".$value);
    }
}
