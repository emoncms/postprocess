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

        $target = new FeedWriter($this->dir,$params->feed);
        $source = new FeedReader($this->dir,$params->source);
        $interval = $target->meta->interval;

        if ($target->meta->npoints==0) {
            return array("success"=>false, "message"=>"feed to backfill is empty");
        }

        // Always start from the current end of the target feed, regardless of process mode,
        // so that existing data in the target feed is never modified
        $start_time = $target->meta->end_time;

        // Only write target intervals that are fully covered by the source feed,
        // so that a partially recorded interval is completed on the next run
        $end_time = $start_time + floor(($source->meta->end_time - $start_time) / $interval) * $interval;

        if ($start_time>=$end_time) {
            return array("success"=>true, "message"=>"no new data to process");
        }

        // Source values are averaged into each target interval
        $target->seek($start_time);
        while (($time = $target->time()) < $end_time) {
            $n = (int) min(POSTPROCESS_BLOCKSIZE, ($end_time - $time) / $interval);
            $target->write_block($source->read_time($time,$interval,$n));
        }
        $byteswritten = $target->close();

        return array("success"=>true, "message"=>written_message($byteswritten, $target));
    }
}
