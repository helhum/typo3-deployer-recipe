<?php
namespace Deployer;
use Deployer\Exception\ConfigurationException;

task('deploy:convert_surf', function () {
    invoke('deploy:prepare');
    if (!test('[ -L {{deploy_path}}/current ]') && test('[ -L {{deploy_path}}/releases/current ]')) {
        output()->writeln(sprintf(
            '<comment>Detected a possible TYPO3 Surf deployment within defined deploy_path (%s) on host "%s"</comment>',
            parse('{{deploy_path}}'),
            \Deployer\Task\Context::get()->getHost()->getHostname()
        ));
        if (askConfirmation('Do you want to convert from a Surf deployment?')) {
            $sharedPath = '{{deploy_path}}/shared';
            $currentReleaseDir = run('readlink -f {{deploy_path}}/releases/current');
            foreach (get('shared_dirs') as $dir) {
                if (!test("[ -d $sharedPath/$dir ]") && test("[ -L {{deploy_path}}/releases/current/$dir ]")) {
                    output()->writeln(sprintf('<comment>Detected %s to link to shared folder in unsupported Deployer location.</comment>', parse($dir)));
                    $currentSharedDir = run("readlink -f $currentReleaseDir/$dir");
                    $newSharedDir = "$sharedPath/$dir";
                    run("mkdir -p `dirname $sharedPath/$dir`");
                    run("mv $currentSharedDir $newSharedDir");
                    run("cd {{deploy_path}}/releases/current && {{bin/symlink}} $newSharedDir $dir");
                }
            }
            $currentReleaseDirName = basename($currentReleaseDir);
            run("cd {{deploy_path}}/releases && mv $currentReleaseDir 1");
            run("cd {{deploy_path}}/releases && {{bin/symlink}} 1 current");
            run("cd {{deploy_path}} && {{bin/symlink}} releases/1 current");
            run("cd {{deploy_path}} && echo $currentReleaseDirName,1 > .dep/releases");
            output()->writeln(sprintf('<info>The new deployment root will be "%s"</info>', parse('{{deploy_path}}/current')));
        } else {
            throw new ConfigurationException('Could not deploy to a path that was previously deployed by TYPO3 Surf.', 1512336746);
        }
    }
});
