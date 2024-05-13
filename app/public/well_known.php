<?php
const PATH_MASS_MARKER='./well-known/mass.txt';
const PATH_DOMAIN_VERIFICATION_FILE='./well-known/apple-developer-merchantid-domain-association.txt';
const APP_INI_FILE='../.env.local';

// parse config
$config = parse_ini_file(APP_INI_FILE);
$useMassEnablement = file_exists(PATH_MASS_MARKER);

// return matching value
if ($useMassEnablement) {
    echo $config['MASS_ENABLEMENT_DOMAIN_VERIFICATION_KEY'];
} else {
    echo file_get_contents(PATH_DOMAIN_VERIFICATION_FILE);
}
