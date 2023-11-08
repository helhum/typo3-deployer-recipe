<?php

namespace Deployer;

$setArrayValue = static function (
    array $array,
    string|array|\ArrayAccess $path,
    mixed $value,
    string $delimiter = '/'
): array {
    if (is_string($path)) {
        if ($path === '') {
            throw new \RuntimeException('Path must not be empty', 1341406194);
        }
        // Extract parts of the path
        $path = str_getcsv($path, $delimiter);
    }
    // Point to the root of the array
    $pointer = &$array;
    // Find path in given array
    foreach ($path as $segment) {
        // Fail if the part is empty
        if ($segment === '') {
            throw new \RuntimeException('Invalid path segment specified', 1341406846);
        }
        // Create cell if it doesn't exist
        if (is_array($pointer) && !array_key_exists($segment, $pointer)) {
            $pointer[$segment] = [];
        }
        // Make it array if it was something else before
        if (!is_array($pointer)) {
            $pointer = [];
        }
        // Set pointer to new cell
        $pointer = &$pointer[$segment];
    }
    // Set value of target cell
    $pointer = $value;
    return $array;
};

$yamlOutput = static function (mixed $value, string $indent = '') use (&$yamlOutput) {
    $output = '';
    if (is_array($value)) {
        foreach ($value as $name => $arrayValue) {
            $output .= $indent . $name . ':';
            if (is_array($arrayValue)) {
                $output .= chr(10) . $yamlOutput($arrayValue, $indent . '    ');
            } else {
                $output .= sprintf(' %s', var_export($arrayValue, true)) . chr(10);
            }
        }
    } else {
        $output .= sprintf($indent . '%s', var_export($value, true));
    }
    return $output;
};

set('environment_config', [
    'DB/Connections/Default/dbname' => 'Database name',
    'DB/Connections/Default/host' => 'Database host',
    'DB/Connections/Default/password' => 'Database password',
    'DB/Connections/Default/user' => 'Database user',
    'SYS/encryptionKey' => 'Encryption key',
    'SYS/sitename' => 'Site name',
]);

set('environment_config_yaml_file', '{{deploy_path}}/shared/config/override.settings.yaml');

task('deploy:init', function () use ($setArrayValue, $yamlOutput) {
    if (test('[ -f {{environment_config_yaml_file}} ]') || !askConfirmation(sprintf('Do you want to create a new environment configuration file (%s)?', basename(get('environment_config_yaml_file'))))) {
        return;
    }
    $config = [];
    foreach (get('environment_config') as $name => $question) {
        $askFunction = '\Deployer\ask';
        if (str_contains(strtolower($name), 'password')) {
            $askFunction = '\Deployer\askHiddenResponse';
        }
        $value = $askFunction($question, null);
        if ($value !== null) {
            $config = $setArrayValue($config, $name, trim($value));
        }
    }
    $yamlString = $yamlOutput($config);
    $envFile = ask('Environment file to import without file extension (empty to skip)', null);
    if ($envFile !== null) {
        $yamlString = <<<EOY
        imports:
            - { resource: 'environments/$envFile.yaml' }
        
        $yamlString
        EOY;
    }
    run(sprintf('echo %s > {{environment_config_yaml_file}}', escapeshellarg($yamlString)));
});
before('deploy:shared', 'deploy:init');
