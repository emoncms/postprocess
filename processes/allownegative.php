<?php

class PostProcess_allownegative extends MapProcess
{
    public function description() {
        return array(
            "name"=>"Allow negative",
            "group"=>"Limits",
            "description"=>"Allow only negative values",
            "order"=>5,
            "settings"=>array(
                "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed:"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:", "nameappend"=>"")
            )
        );
    }

    protected function value($value)
    {
        return $value > 0 ? 0 : $value;
    }
}
