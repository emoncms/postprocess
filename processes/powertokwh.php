<?php

class PostProcess_powertokwh extends CombineProcess
{
    protected $inputs = array("input");
    private $wh;
    private $power;

    public function description() {
        return array(
            "name"=>"Power to kWh",
            "group"=>"Power & Energy",
            "description"=>"Create a cumulative kWh feed from a power feed",
            "order"=>3,
            "settings"=>array(
                "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input power feed (W):"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Create or select output kWh feed:"),
                "max_power"=>array("type"=>"value", "default"=>1000000, "short"=>"Enter max power limit (W):"),
                "min_power"=>array("type"=>"value", "default"=>-1000000, "short"=>"Enter min power limit (W):")
            )
        );
    }

    protected function reset($previous)
    {
        $this->wh = is_nan($previous) ? 0 : $previous * 1000;
        $this->power = 0;
    }

    // Missing power values hold the last power value. Power values outside of the
    // limits are treated as spurious and add no energy.
    protected function row($power)
    {
        if (!is_nan($power)) {
            if ($power < $this->params->min_power || $power >= $this->params->max_power) {
                return $this->wh * 0.001;
            }
            $this->power = $power;
        }
        $this->wh += ($this->power * $this->interval) / 3600.0;
        return $this->wh * 0.001;
    }
}
