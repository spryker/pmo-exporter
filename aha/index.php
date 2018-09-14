<?php
require 'vendor/autoload.php';

use AhaApp\Aha;
use AhaApp\AhaConfig;

(new Aha(new AhaConfig()))->run();
