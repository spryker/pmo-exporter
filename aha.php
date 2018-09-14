<?php
require 'vendor/autoload.php';

use AhaApp\Aha;
use AhaApp\AhaConfig;

set_time_limit(10*60);

(new Aha(new AhaConfig()))->run();
