<?php

// opcache.preload (docker/Dockerfile): loads the classes listed by the compiled container of the production
// environment when PHP starts. Does nothing until that container exists (bin/console cache:warmup).
foreach (glob(dirname(__DIR__).'/var/cache/prod/*.preload.php') ?: [] as $file) {
    require $file;
}
