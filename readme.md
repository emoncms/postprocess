# Post processing module for emoncms

Rather than process inputs as data arrives in emoncms such as calculating cumulative kWh data from power data with the power to kWh input process, this module can be used to do these kind of processing steps after having recorded base data such as power data for some time. This removes the reliance on setting up everything right in the first instance providing the flexibility to recalculate processed feeds at a later date.

![postprocessor.png](files/postprocessor.png)

### EmonPi, Emonbase install

Install the postprocess module into home folder (e.g. /home/pi) directory (rather than emoncms/Modules):

    cd ~/
    git clone https://github.com/emoncms/postprocess.git

Symlink the web part of the postprocess module into emoncms/Modules, if not using Raspberry Pi replace 'pi' with your home folder name:

    ln -s /home/pi/postprocess/mainserver/postprocess /var/www/emoncms/Modules/postprocess
    
Use the default settings

    cd ~/postprocess 
    cp default.postprocess.settings.php postprocess.settings.php 

Run the background script with:

    sudo php /home/pi/postprocess/postprocess.php
