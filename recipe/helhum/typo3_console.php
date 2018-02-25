<?php
namespace Deployer;

set('bin/typo3cms', function () {
    if (test('[ -f {{composer_config/bin-dir}}/typo3cms ]')) {
        return '{{bin/php}} {{composer_config/bin-dir}}/typo3cms';
    }
    if (test('[ -f {{composer_config/bin-dir}}/typo3console ]')) {
        return '{{bin/php}} {{composer_config/bin-dir}}/typo3console';
    }
    if (test('[ -f {{release_path}}/typo3cms ]')) {
        return '{{bin/php}} {{release_path}}/typo3cms';
    }
    return null;
});
function runConsole($command, array $arguments = []) {
    if (get('bin/typo3cms') === null) {
        output()->writeln(sprintf('<comment>Could not detect TYPO3 Console binary, skipping "%s"</comment>', $command));
        output()->writeln('<comment>Consider defining the path as "bin/typo3cms" within your Deployer configuration.</comment>');
        return '';
    }
    array_unshift($arguments, $command);
    return run('{{bin/typo3cms}} ' . implode(' ', array_map('escapeshellarg', $arguments)));
}

/**
 * Individual (reusable tasks)
 */
set('command', '');
set('arguments', []);
task('typo3:console', function () {
    $command = get('command');
    $arguments = get('arguments');
    if (is_string($arguments)) {
        $arguments = explode(' ', $arguments);
    }
    output()->writeln(runConsole($command, $arguments));
})->desc('Run any TYPO3 Console command');

task('typo3:flush:caches', function () {
    runConsole('cache:flush');
})->desc('Flush caches');

task('typo3:dump:settings', function () {
    runConsole('settings:dump', ['--no-dev']);
})->desc('Dump all settings into LocalConfiguration.php');

task('typo3:create_default_folders', function () {
    runConsole('install:fixfolderstructure');
})->desc('Creates TYPO3 default folder structure');

task('typo3:update:databaseschema', function () {
    runConsole('database:updateschema');
})->desc('Update database schema');

task('typo3:setup:extensions', function () {
    runConsole('extension:setupactive');
})->desc('Set up TYPO3 extensions');

/**
 * Add TYPO3 tasks
 */
after('transfer', 'typo3:dump:settings');
after('transfer', 'typo3:create_default_folders');
after('transfer', 'typo3:update:databaseschema');
after('transfer', 'typo3:flush:caches');
after('transfer', 'typo3:setup:extensions');
after('release', 'typo3:flush:caches');
