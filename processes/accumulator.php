<?php

class PostProcess_accumulator extends CombineProcess
{
    protected $inputs = array("input");
    private $total;
    private $value;

    public function description() {
        return array(
            "name"=>"Accumulator",
            "group"=>"Misc",
            "description"=>"Accumulate the sum total of all the values in a feed.",
            "settings"=>array(
                "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed:"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:"),
                "max_value"=>array("type"=>"value", "default"=>1000000, "short"=>"Enter max value limit:"),
                "min_value"=>array("type"=>"value", "default"=>-1000000, "short"=>"Enter min value limit:")
           )
        );
    }

    protected function reset($previous)
    {
        $this->total = is_nan($previous) ? 0 : $previous;
        $this->value = 0;
    }

    // Missing values hold the last value. Values outside of the limits are treated
    // as spurious and are not added.
    protected function row($value)
    {
        if (!is_nan($value)) {
            if ($value < $this->params->min_value || $value >= $this->params->max_value) {
                return $this->total;
            }
            $this->value = $value;
        }
        $this->total += $this->value;
        return $this->total;
    }
}
