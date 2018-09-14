<?php
require 'vendor/autoload.php';

use PersonioApp\Personio;
use PersonioApp\PersonioConfig;

(new Personio(new PersonioConfig()))->run();

