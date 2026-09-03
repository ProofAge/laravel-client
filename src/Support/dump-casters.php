<?php

use ProofAge\Laravel\Support\DumpCasters;

// Runs when Composer's autoloader loads, before Laravel builds the cloner behind dd() and
// dump(); see ProofAge\Laravel\Support\DumpCasters for why the service provider is too late.
DumpCasters::register();
