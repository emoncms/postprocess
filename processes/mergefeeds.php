<?php

class PostProcess_mergefeeds extends CombineProcess
{
    protected $inputs = array("feedA", "feedB");

    public function description() {
        return array(
            "name"=>"Merge feeds",
            "group"=>"Feeds",
            "description"=>"Merge two feeds together. If missing data is found in one feed, the other feed is used to fill in the gaps. If data is available for both feeds, the average is taken.",
            "settings"=>array(
                "feedA"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed A:"),
                "feedB"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed B:"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:", "nameappend"=>"")
            )
        );
    }

    // Average of both feeds, or whichever feed has data
    protected function row($a, $b)
    {
        if (is_nan($a)) return $b;
        if (is_nan($b)) return $a;
        return ($a + $b) * 0.5;
    }
}
