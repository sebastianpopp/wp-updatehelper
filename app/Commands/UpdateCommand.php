<?php

namespace App\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
        $io = new SymfonyStyle($input, $output);

        // Check if working directory is clean
        if (!$this->wdIsClean()) {
            $io->error("Needs a clean working directory.");
            return Command::FAILURE;
        }

        // Open wp-admin
        if ($io->confirm("Open wp-admin?", true)) {
            exec("wp admin");
        }

        $changelog = collect([]);

        // Check for core updates
        exec("wp core check-update --format=json", $coreUpdates);
        $coreUpdates = $coreUpdates ? json_decode($coreUpdates[0], true) : [];

        if (sizeof($coreUpdates) > 0) {
            // Get current version
            exec("wp core version", $oldVersion);
            $oldVersion = trim($oldVersion[0]);

            if ($io->confirm("Update core ($oldVersion → {$coreUpdates[0]['version']})?", true)) {
                // Update
                exec("wp core update");

                // Get new version
                exec("wp core version", $newVersion);
                $newVersion = trim($newVersion[0]);

                // Add to changelog
                $changelog->push("- Updated WordPress core `{$oldVersion}` → `{$newVersion}`");

                // Git commit
                if ($io->confirm("Commit to git?", true)) {
                    exec("git add -A");
                    exec("git commit -m\"update wp core\"");
                }
            }
        }

        // Get list of all plugins
        exec("wp plugin list --format=json", $plugins);
        $plugins = json_decode($plugins[0], true);

        foreach ($plugins as $plugin) {
            // Skip plugins that are not active
            if ($plugin['status'] !== 'active') {
                $io->info("Skipping {$plugin['name']}");
                continue;
            }

            // Skip plugins that don't have an update available
            if ($plugin['update'] !== 'available') {
                $io->info("Skipping {$plugin['name']}");
                continue;
            }

            // Ask if update should be installed
            if (!$io->confirm("Update available for {$plugin['name']}. Install?", true)) {
                $io->info("Skipping {$plugin['name']}");
                continue;
            }

            // Get more info
            $plugininfo = null;
            exec("wp plugin get {$plugin['name']} --format=json", $plugininfo);
            $plugininfo = json_decode($plugininfo[0], true);

            // Update the plugin
            $pluginupdate = null;
            exec("wp plugin update {$plugin['name']} --format=json", $pluginupdate);
            $pluginupdate = json_decode($pluginupdate[0], true);

            // Check for the status
            if ($pluginupdate[0]['status'] !== "Updated") {
                $io->warning("Update might have failed: {$pluginupdate[0]['status']}");
                continue;
            }

            $old_version = $pluginupdate[0]['old_version'];
            $new_version = $pluginupdate[0]['new_version'];

            // Add changelog entry
            $changelog->push("- Updated plugin {$plugininfo['title']} `{$old_version}` → `{$new_version}`");

            $io->success("Update installed {$old_version} → {$new_version}");

            // Git commit
            if ($io->confirm("Commit to git?", true)) {
                exec("git add -A");
                exec("git commit -m\"update {$plugin['name']}\"");
            }
        }

        // Update translations
        if ($io->confirm("Update translations?", true)) {
            exec("wp language core update");
            exec("wp language plugin update --all");

            if (!$this->wdIsClean()) {
                $io->success("Updated translations");

                $changelog->push("- Updated translations");

                // Git commit
                if ($io->confirm("Commit to git?", true)) {
                    exec("git add -A");
                    exec("git commit -m\"update translations\"");
                }
            } else {
                $io->success("Translations already up to date");
            }
        }

        // Output changelog
        $io->title("Changelog");
        $io->writeln($changelog->toArray());
        $io->newLine();

        return Command::SUCCESS;
    }
}
