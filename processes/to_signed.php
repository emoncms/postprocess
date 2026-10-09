<?php

class PostProcess_to_signed extends InPlaceProcess
{
    public function description() {
        return array(
            "name"=>"To signed",
            "group"=>"Misc",
            "description"=>"Convert unsigned int to signed int",
            "settings"=>array(
                "feedid"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed to convert:"),
            )
        );
    }

    // Convert unsigned int 16 to signed int 16
    protected function value($value)
    {
        return $value > 32767 ? $value - 65536 : $value;
    }
}
