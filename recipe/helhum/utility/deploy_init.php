<?php
namespace Deployer;

set('environment_config', [
    'DB/Connections/Default/dbname' => 'Database name',
    'DB/Connections/Default/host' => 'Database host',
    'DB/Connections/Default/password' => 'Database password',
    'DB/Connections/Default/user' => 'Database user',
    'SYS/encryptionKey' => 'Encryption key',
    'SYS/sitename' => 'Site name',
]);

task('deploy:init', function () {
    invoke('deploy:prepare');
    if (!test('[ -f {{deploy_path}}/shared/conf/host.yml ]')) {
        if (askConfirmation('Do you want to create new environment configuration?')) {
            $config = [];
            foreach (get('environment_config') as $name => $question) {
                if (stripos($name, 'password') === false) {
                    $config[$name] = ask(sprintf('%s, (%s)', $question, $name));
                } else {
                    $config[$name] = askHiddenResponse(sprintf('%s, (%s)', $question, $name));
                }
            }
        }
    }
});
