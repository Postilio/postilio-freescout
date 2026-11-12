<?php

// FreeScout runs this when the module starts.
if (!app()->routesAreCached()) {
    require __DIR__.'/Http/routes.php';
}
