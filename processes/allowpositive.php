<?php

class PostProcess_allowpositive extends MapProcess
{
    public function description() {
        return array(
            "name"=>"Allow positive",
            "group"=>"Limits",
            "description"=>"Allow only positive values",
            "order"=>4,
            "settings"=>array(
                "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed:"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:", "nameappend"=>"")
            )
        );
    }

    protected function value($value)
    {
        return $value < 0 ? 0 : $value;
    }
}
