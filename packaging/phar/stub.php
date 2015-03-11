<?php

Phar::mapPhar();
include "phar://filestore.phar/filestored";
$oApp = new filestored();
$oApp->main();
__HALT_COMPILER();
