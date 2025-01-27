#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
if [ -f "/.dockerenv" ]; then
    php $DIR/postprocess_run.php
else
    sudo php $DIR/postprocess_run.php
fi
