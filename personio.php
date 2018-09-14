<?php
require 'vendor/autoload.php';

use PersonioApp\Personio;
use PersonioApp\PersonioConfig;

set_time_limit(10*60);

(new Personio(new PersonioConfig()))->run();

