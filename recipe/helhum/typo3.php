<?php
namespace Deployer;

use Deployer\Exception\ConfigurationException;
use Deployer\Task\Context;

require 'recipe/common.php';
require 'contrib/rsync.php';

// Determine the source path which will be rsynced to the server
set('source_path', function () {
    $sourcePath = '{{build_path}}/current';
    if (!has('build_path')) {
        if (!file_exists(\getcwd() . '/deploy.php')) {
            throw new ConfigurationException('Could not determine path to deployment source directory ("source_path")', 1512317992);
        }
        $sourcePath = getcwd();
    }
    return $sourcePath;

});

set('composer_options', '--verbose --prefer-dist --no-progress --no-interaction --no-dev --classmap-authoritative');

// Fetch composer.json and store it in array for later use
set('composer_config', function () {
    $composerJsonPath = parse('{{source_path}}/composer.json');
    if (!file_exists($composerJsonPath)) {
        // If we don't find a composer.json file, we assume the root dir to be the release path
        return null;
    }
    return \json_decode(\file_get_contents($composerJsonPath), true);
});

// Extract bin-dir from composer config
set('composer_config/bin-dir', function() {
    $binDir = '{{release_path}}/vendor/bin';
    $composerConfig = get('composer_config');
    if (isset($composerConfig['config']['bin-dir'])) {
        $binDir = '{{release_path}}/' . $composerConfig['config']['bin-dir'];
    }
    return $binDir;
});

// Extract TYPO3 public directory from composer config
set('typo3/public_dir', function () {
    $composerConfig = get('composer_config');
    if (!isset($composerConfig['extra']['typo3/cms']['web-dir'])) {
        // If no config is provided, we assume the web dir to be "public" folder in release path
        return 'public';
    }
    return $composerConfig['extra']['typo3/cms']['web-dir'];
});

task('build:composer', function () {
    set('deploy_path', '{{build_path}}');
    runLocally('cd {{release_path}} && {{bin/composer}} build');
})->hidden();

/*
 * Local build and rsync strategy
 */
set('build_tasks', []);
task('deploy:build:local', function () {
    if (!has('build_path')) {
        // No build path defined. Assuming source path to be the current directory, skipping build
        return;
    }
    Context::push(new Context(localhost()));
    $composerConfig = get('composer_config');
    if (isset($composerConfig['scripts']['build'])) {
        add('build_tasks', ['build:composer']);
    }
    set('deploy_path', '{{build_path}}');
    set('keep_releases', 1);
    invoke('deploy:setup');
    invoke('deploy:release');
    invoke('deploy:update_code');
    invoke('deploy:vendors');
    foreach (get('build_tasks') as $task) {
        invoke($task);
    }
    invoke('deploy:symlink');
    invoke('deploy:cleanup');
    Context::pop();

})->desc('Build project')->hidden();

task('deploy:transfer',  [
    'deploy:release',
    'rsync:warmup',
    'rsync',
    'deploy:shared',
])->desc('Transfer code to target hosts')->hidden();

task('deploy:release-code', [
    'deploy:symlink',
])->desc('Release code on target hosts')->hidden();

task('rsync')->hidden();
task('rsync:warmup')->hidden();
task('deploy:copy_dirs')->hidden();
task('deploy:clear_paths')->hidden();

add('rsync', [
    'exclude' => [
        '.DS_Store',
        '.gitignore',
        '/.ddev',
        '/.env',
        '/{{typo3/public_dir}}/fileadmin',
        '/{{typo3/public_dir}}/typo3temp',
        '/{{typo3/public_dir}}/uploads',
        '/var/lock',
        '/var/log',
    ],
    'flags' => 'r',
    'options' => [
        'times',
        'perms',
        'links',
        'delete',
        'delete-excluded',
    ],
    'timeout' => 360,
]);
set('rsync_src', '{{source_path}}');
set('rsync_dest','{{release_path}}');

/*
 * Main deploy task
 */
task('deploy', [
    'deploy:info',
    'deploy:setup',
    'deploy:build:local',
    'deploy:transfer',
    'deploy:release-code',
    'deploy:cleanup',
])->desc('Deploy your project');
after('deploy', 'deploy:success');
before('deploy:transfer', 'deploy:lock');
after('deploy:release-code', 'deploy:unlock');
after('deploy:failed', 'deploy:unlock');

/*
 * Global config
 */
// Disallow statistics
set('allow_anonymous_stats', false);

/*
 * TYPO3-specific config
 */
set('shared_dirs', [
    '{{typo3/public_dir}}/fileadmin',
    '{{typo3/public_dir}}/uploads',
    '{{typo3/public_dir}}/typo3temp/assets',
    'var/lock',
    'var/log',
]);

set('shared_files',
    [
        'config/override.settings.yaml',
    ]
);

// Writeable directories
set('writable_dirs', [
    // These folders do not need to be made writeable on each deploy
    // but it is useful to make them writable on first deploy, so we keep them here
    '{{typo3/public_dir}}/fileadmin',
    '{{typo3/public_dir}}/uploads',
    '{{typo3/public_dir}}/typo3temp/assets',
    'var/cache',
    'var/lock',
    'var/log',
]);
// These are server specific and should be set in the main deployment description
// See https://deployer.org/docs/flow#deploy:writable
//after('deploy:shared', 'deploy:writable');
//set('writable_mode', 'chmod');
//set('writable_chmod_recursive', true);
//set('writable_use_sudo', false);
//set('writable_chmod_mode', 'g+w');

require __DIR__ . '/typo3_console.php';
require __DIR__ . '/utility/deploy_init.php';
