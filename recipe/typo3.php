<?php
namespace Deployer;

use Deployer\Exception\ConfigurationException;

require 'recipe/common.php';
require __DIR__ . '/rsync.php';

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

set('composer_config', function () {
    $composerJsonPath = parse('{{source_path}}/composer.json');
    if (!file_exists($composerJsonPath)) {
        // If we don't find a composer.json file, we assume the root dir to be the release path
        return null;
    }
    return \json_decode(\file_get_contents($composerJsonPath), true);
});

set('composer_config/bin-dir', function() {
    $binDir = '{{release_path}}/vendor/bin';
    $composerConfig = get('composer_config');
    if (isset($composerConfig['config']['bin-dir'])) {
        $binDir = '{{release_path}}/' . $composerConfig['config']['bin-dir'];
    }
    return $binDir;
});

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

set('typo3/public_dir', function () {
    $composerConfig = get('composer_config');
    if (!isset($composerConfig['extra']['typo3/cms']['web-dir'])) {
        // If no config is provided, we assume the web dir to be the release path
        return '.';
    }
    return $composerConfig['extra']['typo3/cms']['web-dir'];
});

/**
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
    'filter' => [
        '- */.DS_Store',
        '- */.git',
        '+ /conf/***',
        '+ /packages/***',
        '- /{{typo3/root_dir}}/fileadmin',
        '- /{{typo3/root_dir}}/uploads',
        '+ /{{typo3/root_dir}}/***',
        '+ /{{typo3/public_dir}}/***',
        '+ /vendor/***',
        '- /var/log/*.log',
        '+ /var/***',
        '+ /composer.*',
        '- *',
    ],
    'flags'        => 'r',
    'options'      => ['times','perms','links','delete','delete-excluded',],
    'timeout'      => 360,
]);
set('rsync_src', '{{source_path}}');
set('rsync_dest','{{release_path}}');

/**
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

/**
 * Global config
 */
set('allow_anonymous_stats', false);

/**
 * TYPO3 Specific config
 */
set('shared_dirs', [
    '{{typo3/root_dir}}/fileadmin',
    '{{typo3/root_dir}}/uploads',
]);

set('shared_files',
    [
        'conf/host.yml',
    ]
);
