<?php

class PostProcess_removenan_lastvalue extends InPlaceProcess
{
    public function description() {
        return array(
            "name"=>"Replace missing values with last value",
            "group"=>"Data cleanup",
            "description"=>"Remove missing data points from a feed by replacing with last value",
            "settings"=>array(
                "feedid"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed to remove nan values:"),
            )
        );
    }

    private $last_value = NAN;

    protected function reset()
    {
        $this->last_value = NAN;
    }

    // Replace NAN with the last value, the last value carries over between blocks
    protected function values($values)
    {
        foreach ($values as $i => $value) {
            if (is_nan($value)) $values[$i] = $this->last_value;
            else $this->last_value = $value;
        }
        return $values;
    }
}
