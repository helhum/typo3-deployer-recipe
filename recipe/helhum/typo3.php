<?php
namespace Deployer;

use Deployer\Exception\ConfigurationException;

require 'recipe/common.php';
require 'recipe/rsync.php';

// Unset env vars that affect build process
unset($_ENV['TYPO3_CONTEXT'], $_ENV['TYPO3_PATH_ROOT'], $_ENV['TYPO3_PATH_WEB'], $_ENV['TYPO3_PATH_COMPOSER_ROOT'], $_ENV['TYPO3_PATH_APP']);
putenv('TYPO3_CONTEXT');
putenv('TYPO3_PATH_ROOT');
putenv('TYPO3_PATH_WEB');
putenv('TYPO3_PATH_COMPOSER_ROOT');
putenv('TYPO3_PATH_APP');

// Determine the source path which will be rsynced to the server
set('source_path', function () {
    $sourcePath = '{{build_path}}/current';
    if (!has('build_path') && !Deployer::hasDefault('build_path')) {
        if (!file_exists(\getcwd() . '/deploy.php')) {
            throw new ConfigurationException('Could not determine path to deployment source directory ("source_path")', 1512317992);
        }
        $sourcePath = getcwd();
    }
    return $sourcePath;

});

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

// Extract TYPO3 root dir from composer config
set('typo3/root_dir', function () {
    // If no config is provided, we assume the root dir to be the release path
    $typo3RootDir = '.';
    $composerConfig = get('composer_config');
    if (isset($composerConfig['extra']['typo3/cms']['web-dir'])) {
        $typo3RootDir = $composerConfig['extra']['typo3/cms']['web-dir'];
    }
    if (isset($composerConfig['extra']['typo3/cms']['root-dir'])) {
        $typo3RootDir = $composerConfig['extra']['typo3/cms']['root-dir'];
    }
    return $typo3RootDir;
});

// Extract TYPO3 public directory from composer config
set('typo3/public_dir', function () {
    $composerConfig = get('composer_config');
    if (!isset($composerConfig['extra']['typo3/cms']['web-dir'])) {
        // If no config is provided, we assume the web dir to be the release path
        return '.';
    }
    return $composerConfig['extra']['typo3/cms']['web-dir'];
});

/*
 * Local build and rsync strategy
 */
set('build_tasks', []);
task('build', function () {
    if (!has('build_path') && !Deployer::hasDefault('build_path')) {
        // No build path defined. Assuming source path to be the current directory, skipping build
        return;
    }
    // This code is copied from TaskCommand, as it seems to be the only option currently to get the target hosts
    $stage = input()->hasArgument('stage') ? input()->getArgument('stage') : null;
    $roles = input()->getOption('roles');
    $hosts = input()->getOption('hosts');
    if (!empty($hosts)) {
        $hosts = Deployer::get()->hostSelector->getByHostnames($hosts);
    } elseif (!empty($roles)) {
        $hosts = Deployer::get()->hostSelector->getByRoles($roles);
    } else {
        $hosts = Deployer::get()->hostSelector->getHosts($stage);
    }
    // Just select one host under the assumption that it does not make sense
    // to deploy different branches for the same hosts selection
    $hostBranch = current($hosts)->getConfig()->get('branch');
    $defaultBranch = get('branch');
    // Only change the branch, if we have differences
    if ($defaultBranch !== $hostBranch) {
        set('branch', $hostBranch);
    }
    set('deploy_path', '{{build_path}}');
    set('keep_releases', 1);
    invoke('deploy:prepare');
    invoke('deploy:release');
    invoke('deploy:update_code');
    invoke('deploy:vendors');
    foreach (get('build_tasks') as $task) {
        invoke($task);
    }
    invoke('deploy:symlink');
    invoke('cleanup');

})->local()->desc('Build project')->setPrivate();

task('transfer',  [
    'deploy:release',
    'rsync:warmup',
    'rsync',
    'deploy:shared',
])->desc('Transfer code to target hosts')->setPrivate();

task('release', [
    'deploy:symlink',
])->desc('Release code on target hosts')->setPrivate();

task('rsync')->setPrivate();
task('rsync:warmup')->setPrivate();
task('deploy:copy_dirs')->setPrivate();
task('deploy:clear_paths')->setPrivate();

add('rsync', [
    'exclude' => [
        '.DS_Store',
        '.gitignore',
        '/.env',
        '/{{typo3/root_dir}}/fileadmin',
        '/{{typo3/root_dir}}/typo3temp',
        '/{{typo3/root_dir}}/uploads',
        '/var/log',
    ],
    'include' => [
        '/.htaccess',
        '/var/log/.gitkeep',
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
    'deploy:prepare',
    'build',
    'transfer',
    'release',
    'cleanup',
])->desc('Deploy your project');
after('deploy', 'success');
before('transfer', 'deploy:lock');
after('release', 'deploy:unlock');
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
    '{{typo3/root_dir}}/fileadmin',
    '{{typo3/root_dir}}/uploads',
    '{{typo3/root_dir}}/typo3temp/assets',
    '{{typo3/root_dir}}/typo3temp/var/locks',
    'var/log',
]);

set('shared_files',
    [
        'conf/host.yml',
    ]
);

// Writeable directories
set('writable_dirs', [
    '{{typo3/root_dir}}/typo3temp/var/Cache',
    // These folders do not need to be made writeable on each deploy
    // but it is useful to make them writable on first deploy, so we keep them here
    '{{typo3/root_dir}}/fileadmin',
    '{{typo3/root_dir}}/uploads',
    '{{typo3/root_dir}}/typo3temp/assets',
    '{{typo3/root_dir}}/typo3temp/var/locks',
]);
// These are server specific and should be set in the main deployment description
// See https://deployer.org/docs/flow#deploy:writable
//after('deploy:shared', 'deploy:writable');
//set('writable_mode', 'chmod');
//set('writable_chmod_recursive', true);
//set('writable_use_sudo', false);
//set('writable_chmod_mode', 'g+w');
