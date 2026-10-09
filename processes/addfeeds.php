<?php

class PostProcess_addfeeds extends CombineProcess
{
    protected $inputs = array("feedA", "feedB");

    public function description() {
        return array(
            "name"=>"Add feeds",
            "group"=>"Feeds",
            "description"=>"Add two feeds together",
            "settings"=>array(
                "feedA"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed A:"),
                "feedB"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed B:"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:", "nameappend"=>"")
            )
        );
    }

    // Sum of both feeds, or whichever feed has data
    protected function row($a, $b)
    {
        if (is_nan($a)) return $b;
        if (is_nan($b)) return $a;
        return $a + $b;
    }
}
