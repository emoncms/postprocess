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

Processes that transform each value of a feed extend `MapProcess` (input feed to output feed, e.g. `processes/scalefeed.php`) or `InPlaceProcess` (modify a feed in place, e.g. `processes/to_signed.php`) in `common.php` and only implement `value($value)`. Other processes can use `FeedReader`, `FeedWriter` and `resolve_start_time()` for block based feed access, see `processes/backfillfeed.php` for an example.
