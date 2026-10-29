<?php

namespace App\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

#[AsCommand(
    name: 'update',
    description: 'Update WordPress and plugins',
)]
class UpdateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('autopilot', 'a', InputOption::VALUE_NONE, 'Run without asking for confirmation')
            ->addOption('autodeploy', 'd', InputOption::VALUE_NONE, 'Create a release without asking for confirmation');
    }

    protected function wdIsClean()
    {
        exec("[[ -n $(git status -s) ]] || echo 'clean'", $result);

        return count($result) === 1 && $result[0] === 'clean';
    }

    /**
     * Commits staged changes, retrying if git fails to acquire index.lock
     * (e.g. a stale lock left behind by a crashed/concurrent git process).
     */
    protected function gitCommit(string $message, int $maxRetries = 3): bool
    {
        exec('git add -A');

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            exec('git commit -m ' . escapeshellarg($message) . ' 2>&1', $output, $exitCode);

            if ($exitCode === 0) {
                return true;
            }

            if ($attempt < $maxRetries) {
                sleep(2);
                $output = [];
            }
        }

        warning('Git commit failed after ' . ($maxRetries + 1) . " attempts:\n" . implode("\n", $output));

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $autopilot = $input->getOption('autopilot') === true;
        $autodeploy = $input->getOption('autodeploy') === true;

        // Check if working directory is clean
        if (! $this->wdIsClean()) {
            error('Needs a clean working directory.');

            return Command::FAILURE;
        }

        // Open wp-admin
        if (!$autopilot && confirm('Do you want to open WP admin?')) {
            exec('wp admin');
        }

        $changelog = collect([]);

        // Check for core updates
        $core_updates = [];
        spin(function () use (&$core_updates) {
            exec('wp core check-update --format=json', $core_updates);
            $core_updates = $core_updates ? json_decode($core_updates[0], true) : [];
        }, 'Checking for core updates');

        if ($core_updates && count($core_updates) > 0) {
            // Get current version
            $old_version = '';
            spin(function () use (&$old_version) {
                exec('wp core version', $old_version);
                $old_version = trim($old_version[0]);
            }, 'Getting current version');

            // Sort available updates, latest version first
            $core_updates = collect($core_updates)
                ->sort(fn ($a, $b) => version_compare($b['version'], $a['version']))
                ->values();
            $target_update = $core_updates->first();

            if ($autopilot) {
                $do_update = true;
            } elseif ($core_updates->count() > 1) {
                // If multiple versions are available, ask which one to install (defaults to latest)
                $options = $core_updates
                    ->mapWithKeys(fn ($update) => [$update['version'] => "$old_version → {$update['version']}"])
                    ->put('skip', "Don't update")
                    ->all();
                $chosen_version = select('Multiple WordPress core versions available, which one do you want to install?', $options, $target_update['version']);
                $do_update = $chosen_version !== 'skip';
                $target_update = $core_updates->firstWhere('version', $chosen_version);
            } else {
                $do_update = confirm("Update core ($old_version → {$target_update['version']})?");
            }

            if ($do_update) {
                // Update
                exec('wp core update --version=' . $target_update['version']);

                // Get new version
                exec('wp core version', $new_version);
                $new_version = trim($new_version[0]);

                info("Update for WordPress core installed {$old_version} → {$new_version}");

                // Add to changelog
                $changelog->push("- Updated WordPress core `{$old_version}` → `{$new_version}`");

                // Git commit
                if ($autopilot || confirm('Commit changes to git?')) {
                    spin(fn () => $this->gitCommit('update wp core'), 'Committing changes to git');
                }
            }
        } else {
            info('WordPress core already up to date');
        }

        // Get list of all plugins
        $updateable_plugins = [];
        spin(function () use (&$updateable_plugins) {
            exec('wp plugin list --format=json', $plugins);
            $all_plugins = json_decode($plugins[0], true);

            // Get list of updateable plugins with information
            foreach ($all_plugins as $plugin) {
                // Skip plugins that don't have an update available
                if ($plugin['update'] !== 'available') {
                    continue;
                }

                // Get more info
                $plugininfo = null;
                exec("wp plugin get {$plugin['name']} --format=json", $plugininfo);
                $plugininfo = json_decode($plugininfo[0], true);

                $updateable_plugins[$plugin['name']] = $plugininfo['title'];
            }
        }, 'Getting list of plugins with updates');

        if (count($updateable_plugins) > 0) {
            $update_plugins = $autopilot
                ? array_keys($updateable_plugins)
                : multiselect('Select plugins to update', $updateable_plugins, array_keys($updateable_plugins));

            $auto_commit = $autopilot || confirm('Automatically commit changes to git?', true);

            foreach ($update_plugins as $plugin) {
                $versions = spin(function () use ($plugin, $updateable_plugins, &$changelog) {
                    // Update the plugin
                    $pluginupdate = null;
                    exec("wp plugin update {$plugin} --format=json", $pluginupdate);
                    $pluginupdate = json_decode($pluginupdate[0], true);

                    // Check for the status
                    if ($pluginupdate[0]['status'] !== 'Updated') {
                        return [false, $pluginupdate[0]['status']];
                    }

                    $old_version = $pluginupdate[0]['old_version'];
                    $new_version = $pluginupdate[0]['new_version'];

                    // Add changelog entry
                    $changelog->push("- Updated plugin {$updateable_plugins[$plugin]} `{$old_version}` → `{$new_version}`");

                    return [true, $old_version, $new_version];
                }, "Updating {$updateable_plugins[$plugin]}");

                if ($versions[0] === false) {
                    warning("Update might have failed: {$versions[1]}");

                    continue;
                }

                [$_, $old_version, $new_version] = $versions;

                info("Update for {$updateable_plugins[$plugin]} installed {$old_version} → {$new_version}");

                // Git commit
                if ($auto_commit || confirm('Commit changes to git?')) {
                    spin(fn () => $this->gitCommit("update {$plugin}"), 'Committing changes to git');
                }
            }
        } else {
            info('Plugins already up to date');
        }

        // Update translations
        if ($autopilot || confirm('Update translations?')) {
            spin(function () {
                exec('wp language core update');
                exec('wp language plugin update --all');
            }, 'Updating translations');

            if (! $this->wdIsClean()) {
                info('Updated translations');

                $changelog->push('- Updated translations');

                // Git commit
                if ($autopilot || confirm('Commit changes to git?')) {
                    spin(fn () => $this->gitCommit('update translations'), 'Committing changes to git');
                }
            } else {
                info('Translations already up to date');
            }
        }

        if ($changelog->isNotEmpty()) {
            // Push to remove and create a release?
            if ($autodeploy || confirm('Push to remote?')) {
                exec('git push');
                info('Pushed to remote');

                if ($autodeploy || confirm('Create release?')) {
                    // Create temporary file with changelog
                    $temp = tempnam(sys_get_temp_dir(), 'changelog');
                    file_put_contents($temp, $changelog->implode("\n"));

                    // Current date
                    $date = date('Y-m-d');

                    // Create release
                    exec("gh release create {$date} --title=\"{$date}\" --notes-file=\"{$temp}\" --draft");

                    // Remove temporary file
                    unlink($temp);

                    info("Created release {$date}");

                    // Wait for release to be created
                    sleep(1);

                    // Open releases in browser
                    exec('gh browse --releases');
                }
            }

            // Output changelog
            $io = new SymfonyStyle($input, $output);
            $io->title('Changelog');
            $io->writeln($changelog->toArray());
            $io->newLine();
        }

        return Command::SUCCESS;
    }
}
