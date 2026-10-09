# Post processing module for emoncms

Rather than process inputs as data arrives in emoncms such as calculating cumulative kWh data from power data with the power to kWh input process, this module can be used to do these kind of processing steps after having recorded base data such as power data for some time. This removes the reliance on setting up everything right in the first instance providing the flexibility to recalculate processed feeds at a later date.

![postprocessor24.png](files/postprocessor24.png)

### EmonPi, Emonbase install

Install the postprocess module in /opt/emoncms/modules:

    cd /opt/emoncms/modules
    git clone https://github.com/emoncms/postprocess.git

Symlink the web part of the postprocess module into emoncms/Modules:

    ln -s /opt/emoncms/modules/postprocess/postprocess-module /var/www/emoncms/Modules/postprocess

The postprocess background script is designed to be ran in the background using service-runner.service.
This is installed by default on the standard emonSD image build.

Alternatively to run manually:

    cd /opt/emoncms/modules/postprocess
    sudo php postprocess_run.php
### Tests

Processes are tested against small synthetic feeds in a temporary directory, no database or redis is required:

    php tests/run.php            # all tests
    php tests/run.php scalefeed  # tests whose name contains scalefeed

Most processes extend a base class in `common.php` and only implement the calculation:

- `MapProcess`: transforms each value of an input feed into an output feed, implement `value($value)`, e.g. `processes/scalefeed.php`
- `InPlaceProcess`: transforms each value of a feed in place, implement `value($value)`, e.g. `processes/to_signed.php`
- `CombineProcess`: combines input feeds on a common timeline into an output feed, implement `row($a, $b, ...)`, e.g. `processes/importcalc.php`, or with `reset($previous)` to accumulate, e.g. `processes/powertokwh.php`

Simulations that step through time with several inputs and outputs use `ModelHelper`, e.g. `processes/batterysimulator.php`. Other processes can use `FeedReader`, `FeedWriter` and `resolve_start_time()` for block based feed access, see `processes/backfillfeed.php` for an example.
