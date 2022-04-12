<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class UpdateCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'update';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Run update process';

    private function wdIsClean()
    {
        exec("[[ -n $(git status -s) ]] || echo 'clean'", $result);

        return sizeof($result) === 1 && $result[0] === 'clean';
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // Check if working directory is clean
        if (!$this->wdIsClean()) {
            $this->error("Needs a clean working directory.");
            return;
        }

        $changelog = collect([]);

        // Check for core updates
        exec("wp core check-update --format=json", $coreUpdates);
        $coreUpdates = $coreUpdates ? json_decode($coreUpdates[0], true) : [];


        if (sizeof($coreUpdates) > 0) {
            // Get current version
            exec("wp core version", $oldVersion);
            $oldVersion = trim($oldVersion[0]);

            if ($this->confirm("Update core ($oldVersion → {$coreUpdates[0]['version']})?", true)) {
                // Update
                exec("wp core update");

                // Get new version
                exec("wp core version", $newVersion);
                $newVersion = trim($newVersion[0]);

                // Add to changelog
                $changelog->push("- Updated WordPress core `{$oldVersion}` → `{$newVersion}`");

                // Git commit
                if ($this->confirm("Commit to git?", true)) {
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
                $this->info("Skipping {$plugin['name']}");
                continue;
            }

            // Skip plugins that don't have an update available
            if ($plugin['update'] !== 'available') {
                $this->info("Skipping {$plugin['name']}");
                continue;
            }

            // Ask if update should be installed
            if (!$this->confirm("Update available for {$plugin['name']}. Install?", true)) {
                $this->info("Skipping {$plugin['name']}");
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
                $this->warn("⚠️  Update might have failed: {$pluginupdate[0]['status']}");
                continue;
            }

            // Add changelog entry
            $changelog->push("- Updated plugin {$plugininfo['title']} `{$pluginupdate[0]['old_version']}` → `{$pluginupdate[0]['new_version']}`");

            $this->info("✅ Update installed {$pluginupdate[0]['old_version']} → {$pluginupdate[0]['new_version']}");

            // Git commit
            if ($this->confirm("Commit to git?", true)) {
                exec("git add -A");
                exec("git commit -m\"update {$plugin['name']}\"");
            }
        }

        // Update translations
        if ($this->confirm("Update translations?", true)) {
            exec("wp language core update");
            exec("wp language plugin update --all");

            if (!$this->wdIsClean()) {
                $this->info("✅ Updated translations");

                $changelog->push("- Updated translations");

                // Git commit
                if ($this->confirm("Commit to git?", true)) {
                    exec("git add -A");
                    exec("git commit -m\"update translations\"");
                }
            } else {
                $this->info("✅ Translations already up to date");
            }
        }

        // Output changelog
        $this->newLine();
        $this->line("=== CHANGELOG ===============================================");
        $this->line($changelog->implode("\n"));
        $this->line("==============================================================");
    }
}
