<?php
namespace Deployer;

set('bin/typo3', function () {
    if (test('[ -f {{composer_config/bin-dir}}/typo3 ]')) {
        return '{{bin/php}} {{composer_config/bin-dir}}/typo3';
    }
    return null;
});

function runConsole($command, array $arguments = []) {
    if (get('bin/typo3') === null) {
        output()->writeln(sprintf('<comment>Could not detect TYPO3 Console binary, skipping "%s"</comment>', $command));
        output()->writeln('<comment>Consider defining the path as "bin/typo3" within your Deployer configuration.</comment>');
        return '';
    }
    array_unshift($arguments, $command);
    return run('{{bin/typo3}} ' . implode(' ', array_map('escapeshellarg', $arguments)));
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

task('typo3:default:folders', function () {
    runConsole('install:fixfolderstructure');
})->desc('Creates TYPO3 default folder structure');

task('typo3:update:databaseschema', function () {
    runConsole('database:updateschema');
})->desc('Update database schema');

task('typo3:setup:extensions', function () {
    runConsole('extension:setup');
})->desc('Set up TYPO3 extensions');

/**
 * Add TYPO3 tasks
 */
after('deploy:transfer', 'typo3:default:folders');
after('deploy:transfer', 'typo3:update:databaseschema');
after('deploy:transfer', 'typo3:flush:caches');
after('deploy:transfer', 'typo3:setup:extensions');
after('deploy:release-code', 'typo3:flush:caches');
