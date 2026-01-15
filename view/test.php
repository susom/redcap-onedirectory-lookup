<?php

namespace Stanford\RedcapOneDirectoryLookup;

/** @var \Stanford\RedcapOneDirectoryLookup\RedcapOneDirectoryLookup $module */

echo $module->getSecretManager()->getSecret('MS_GRAPH_CLIENT_ID');
