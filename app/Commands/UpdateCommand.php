<?php

namespace App\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

#[AsCommand(
    name: 'update',
    description: 'Update WordPress and plugins',
)]
class UpdateCommand extends Command
{
    protected function wdIsClean()
    {
        exec("[[ -n $(git status -s) ]] || echo 'clean'", $result);

        return sizeof($result) === 1 && $result[0] === 'clean';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Check if working directory is clean
        if (!$this->wdIsClean()) {
            error("Needs a clean working directory.");

            return Command::FAILURE;
        }

        // Open wp-admin
        if (confirm("Do you want to open WP admin?")) {
            exec("wp admin");
        }

        $changelog = collect([]);

        // Check for core updates
        $coreUpdates = [];
        spin(function() use (&$coreUpdates) {
            exec("wp core check-update --format=json", $coreUpdates);
            $coreUpdates = $coreUpdates ? json_decode($coreUpdates[0], true) : [];
        }, "Checking for core updates");

        if (sizeof($coreUpdates) > 0) {
            // Get current version
            $oldVersion = '';
            spin(function() use (&$oldVersion) {
                exec("wp core version", $oldVersion);
                $oldVersion = trim($oldVersion[0]);
            }, "Getting current version");

            if (confirm("Update core ($oldVersion → {$coreUpdates[0]['version']})?")) {
                // Update
                exec("wp core update");

                // Get new version
                exec("wp core version", $newVersion);
                $newVersion = trim($newVersion[0]);

                // Add to changelog
                $changelog->push("- Updated WordPress core `{$oldVersion}` → `{$newVersion}`");

                // Git commit
                if (confirm("Commit changes to git?")) {
                    exec("git add -A");
                    exec("git commit -m\"update wp core\"");
                }
            }
        }

        // Get list of all plugins
        $updateablePlugins = [];
        spin(function () use (&$updateablePlugins) {
            exec("wp plugin list --format=json", $plugins);
            $allPlugins = json_decode($plugins[0], true);


            // Get list of updateable plugins with information
            foreach ($allPlugins as $plugin) {
                // Skip plugins that are not active
                if ($plugin['status'] !== 'active') {
                    continue;
                }

                // Skip plugins that don't have an update available
                if ($plugin['update'] !== 'available') {
                    continue;
                }

                // Get more info
                $plugininfo = null;
                exec("wp plugin get {$plugin['name']} --format=json", $plugininfo);
                $plugininfo = json_decode($plugininfo[0], true);

                $updateablePlugins[$plugin['name']] = $plugininfo['title'];
            }
        }, "Getting list of plugins with updates");

        $updatePlugins = multiselect("Select plugins to update", $updateablePlugins, array_keys($updateablePlugins));

        $autoCommit = confirm("Automatically commit changes to git?", true);

        foreach ($updatePlugins as $plugin) {
            $versions = spin(function () use ($plugin, $updateablePlugins, $autoCommit, &$changelog) {
                // Update the plugin
                $pluginupdate = null;
                exec("wp plugin update {$plugin} --format=json", $pluginupdate);
                $pluginupdate = json_decode($pluginupdate[0], true);

                // Check for the status
                if ($pluginupdate[0]['status'] !== "Updated") {
                    return null;
                }

                $old_version = $pluginupdate[0]['old_version'];
                $new_version = $pluginupdate[0]['new_version'];

                // Add changelog entry
                $changelog->push("- Updated plugin {$updateablePlugins[$plugin]} `{$old_version}` → `{$new_version}`");

                return [$old_version, $new_version];
            }, "Updating {$updateablePlugins[$plugin]}");

            if ($versions === null) {
                warning("Update might have failed: {$pluginupdate[0]['status']}");
                continue;
            }

            list($old_version, $new_version) = $versions;

            note("Update for {$updateablePlugins[$plugin]} installed {$old_version} → {$new_version}");

            // Git commit
            if ($autoCommit || confirm("Commit changes to git?")) {
                spin(function () use ($plugin) {
                    exec("git add -A");
                    exec("git commit -m\"update {$plugin}\"");
                }, "Committing changes to git");
            }
        }

        // Update translations
        if (confirm("Update translations?")) {
            spin(function () {
                exec("wp language core update");
                exec("wp language plugin update --all");
            }, "Updating translations");

            if (!$this->wdIsClean()) {
                note("Updated translations");

                $changelog->push("- Updated translations");

                // Git commit
                if (confirm("Commit changes to git?")) {
                    exec("git add -A");
                    exec("git commit -m\"update translations\"");
                }
            } else {
                note("Translations already up to date");
            }
        }

        // Push to remove and create a release?
        if (confirm("Push to remote?")) {
            exec("git push");
            note("Pushed to remote");

            if (confirm("Create release?")) {
                // Create temporary file with changelog
                $temp = tempnam(sys_get_temp_dir(), 'changelog');
                file_put_contents($temp, $changelog->implode("\n"));

                // Current date
                $date = date('Y-m-d');

                // Create release
                exec("gh release create {$date} --title=\"{$date}\" --notes-file=\"{$temp}\" --draft");

                // Remove temporary file
                unlink($temp);

                note("Created release {$date}");

                // Open releases in browser
                exec("gh browse --releases");
            }
        }

        // Output changelog
        $io = new SymfonyStyle($input, $output);
        $io->title("Changelog");
        $io->writeln($changelog->toArray());
        $io->newLine();

        return Command::SUCCESS;
    }
}
