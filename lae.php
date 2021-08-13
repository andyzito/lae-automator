<?php

include __DIR__ . '/data.php';

class LAEAutomator {
    /**
     * Filtered array of passed CLI options.
     *
     * @var array
     */
    private $opts = array();

    /**
     * Which command to run.
     *
     * @var string
     */
    private $command = '';

    /**
     * Name of the target Git remote.
     *
     * @var string
     */
    private $remote = 'origin';

    /**
     * Convenient storage for various versions/names (version numbers, tags, branch names, etc)
     *
     * @var stdClass
     */
    private $names;

    function __construct($args, $version_data) {
        $this->names = new stdClass();

        // Parse passed command & options.
        $this->parse_args($args);

        // If no command, bail out.
        if ( empty( $this->command ) ) {
            $this->error( 'Please provide a command!' );
            exit;
        }

        // Loop through each defined major version and do stuff.
        foreach( $version_data as $major => $versions ) {

            // If --major is passed, skip non-matched major versions.
            if (!empty($this->opts['major']) && $this->opts['major'] != $major) {
                continue;
            }

            // Extract version numbers from array.
            // 3.9.2, 19.0.3, ...
            $this->names->old_core_version = $versions['old_core_version'];
            $this->names->new_core_version = array_key_exists( 'new_core_version', $versions ) ? $versions['new_core_version'] : $this->bump($this->names->old_core_version);
            $this->names->old_lae_version  = $versions['old_lae_version'];
            $this->names->new_lae_version = array_key_exists( 'new_lae_version', $versions ) ? $versions['new_lae_version'] : $this->bump($this->names->old_lae_version);

            // Calculate tags.
            // v3.9.2, v3.9.2-LAE19.0.3, v3.9.2-LAE19.0.3-base, ...
            $this->names->old_core_tag = 'v' . $this->names->old_core_version;
            $this->names->new_core_tag = 'v' . $this->names->new_core_version;
            $this->names->old_lae_tag_package = $this->names->old_core_tag . '-LAE' . $this->names->old_lae_version;
            $this->names->old_lae_tag_stable = $this->names->old_lae_tag_package . '-base';
            $this->names->new_lae_tag_package = $this->names->new_core_tag . '-LAE' . $this->names->new_lae_version;
            $this->names->new_lae_tag_stable = $this->names->new_lae_tag_package . '-base';

            // Calculate branch names.
            // LAE_39_STABLE, LAE_39_PACKAGE, LAE_1903_STABLE, LAE_1903_PACKAGE, ...
            $this->names->branch_stable = "LAE_${major}_STABLE";
            $this->names->branch_package = "LAE_${major}_PACKAGE";
            $beta_branch_num = str_replace( '.', '', $this->names->new_lae_version );
            $beta_branch = "LAE_${beta_branch_num}";
            $this->names->beta_branch_stable = "${beta_branch}_STABLE";
            $this->names->beta_branch_package = "${beta_branch}_PACKAGE";

            // Output the basics of names calculated above.
            $this->major_version_header($major);

            // Check that the names were properly calculated.
            if (!$this->confirm('Is the above info accurate?')) {
                $this->message("Moving on to the next major version.");
                return;
            }

            $this->runCommand($this->command);
        }
    }

    /**
     * Runs a specified LAE Automator command.
     *
     * @param string $command The command identifier.
     */
    function runCommand($command) {
        $N = $this->names;
        $this->message( "Running $command" );
        switch ($command) {
            case 'beta-branches':
                $this->runCommand('create-beta-branches');
                $this->exec("git checkout {$N->beta_branch_stable}");
                if ( !$this->confirm( "Remember to revert any CLAMP changes which have been superseded by upstream core changes. Are you done with this?" ) ) {
                    $this->message('Okay. Abandoning the remaining tasks: merge-beta-branches, update-readme, push-beta-branches');
                    break;
                };
                $this->runCommand('merge-beta-branches');
                if ( $this->confirm( "Are you ready to push the beta branches?" ) ) {
                    $this->runCommand('push-beta-branches');
                } else {
                    $this->message('Okay. Abandoning remaining tasks: push-beta-branches.');
                }
                break;
            case 'cleanup-beta-branches':
                $this->exec("git branch -d {$N->beta_branch_stable}");
                $this->exec("git push {$this->remote} :{$N->beta_branch_stable}");
                $this->exec("git branch -d {$N->beta_branch_package}");
                $this->exec("git push {$this->remote} :{$N->beta_branch_package}");
                break;
            case 'create-beta-branches':
                $this->exec("git checkout {$N->branch_stable}");
                $this->exec("git checkout -b {$N->beta_branch_stable}");
                $this->exec("git checkout {$N->branch_package}");
                $this->exec("git checkout -b {$N->beta_branch_package}");
                break;
            case 'create-tags':
                $this->exec("git checkout {$N->beta_branch_stable}");
                $this->exec("git tag -a {$N->new_lae_tag_stable} -m 'Moodle {$N->new_lae_tag_package} [No plugins]'");
                $this->exec("git checkout {$N->beta_branch_package}");
                $this->exec("git tag -a {$N->new_lae_tag_package} -m 'Moodle {$N->new_lae_tag_package}'");
                break;
            case 'merge-beta-branches':
                $this->exec("git checkout {$N->new_core_tag}"); // Ensures this is fetched.
                $this->exec("git checkout {$N->beta_branch_stable}");
                $this->exec("git merge {$N->new_core_tag}");
                $this->runCommand('update-readme');
                $this->exec("git checkout {$N->beta_branch_package}");
                $this->exec("git merge {$N->beta_branch_stable}");
                break;
            case 'merge-main-branches':
                $this->exec("git checkout {$N->branch_stable}");
                $this->exec("git merge {$N->beta_branch_stable}");
                $this->exec("git checkout {$N->branch_package}");
                $this->exec("git merge {$N->beta_branch_package}");
                break;
            case 'push-beta-branches':
                $this->exec("git push {$this->remote} {$N->beta_branch_stable}");
                $this->exec("git push {$this->remote} {$N->beta_branch_package}");
                break;
            case 'push-main-branches':
                $this->exec("git push {$this->remote} {$N->branch_stable}");
                $this->exec("git push {$this->remote} {$N->branch_package}");
                break;
            case 'push-tags':
                $this->exec("git push {$this->remote} {$N->new_lae_tag_stable}");
                $this->exec("git push {$this->remote} {$N->new_lae_tag_package}");
                break;
            case 'sanity-diff':
                $this->exec("diff <(git diff {$N->branch_stable}...{$N->beta_branch_stable} --numstat) <(git diff {$N->old_core_tag}...{$N->new_core_tag} --numstat)");
                $this->exec("diff <(git diff {$N->branch_package}...{$N->beta_branch_package} --numstat) <(git diff {$N->old_core_tag}...{$N->new_core_tag} --numstat)");
                break;
            case 'tag-n-push':
                $this->runCommand('merge-main-branches');
                $this->runCommand('create-tags');
                if ($this->confirm("Are you ready to push the main branches and the new tags?")) {
                    $this->runCommand('push-main-branches');
                    $this->runCommand('push-tags');
                } else {
                    $this->message('Okay. Abandoning remaining tasks: push-branches, push-tags, cleanup-beta-branches');
                    break;
                }
                if ($this->confirm("Would you like to automatically clean up the beta branches?")) {
                    $this->runCommand('cleanup-beta-branches');
                } else {
                    $this->message('Okay. Abandoning remaining tasks: cleanup-beta-branches');
                    break;
                }
                break;
            case 'update-readme':
                $old_core_version_escd = preg_quote($N->old_core_version);
                $new_core_version_escd = preg_quote($N->new_core_version);
                $old_lae_version_escd = preg_quote($N->old_lae_version);
                $new_lae_version_escd = preg_quote($N->new_lae_version);

                $this->exec("sed -i '' 's/$old_core_version_escd/$new_core_version_escd/g' ./LAE_readme.md");
                $this->exec("sed -i '' 's/$old_lae_version_escd/$new_lae_version_escd/g' ./LAE_readme.md");
                $this->exec("git a LAE_readme.md; git commit -m 'Update LAE_readme.md'");
                break;
            default:
                $this->error("Invalid command '$command'.");
                exit;
        }
    }

    /**
     * Parse CLI args out into their proper places.
     *
     * @param string $cmd The shell command to run.
     */
    function parse_args($args) {
        array_shift( $args );

        $_opts = [];
        foreach ( $args as $arg ) {
            if (preg_match('/^--([^=]+)=?(.*)/', $arg, $match)) {
                $_opts[$match[1]] = $match[2];
            } else {
                $this->command = $arg;
            }
        }

        $this->remote = array_key_exists( 'remote', $_opts ) ? $_opts['remote'] : 'origin';
        $this->opts['major']  = array_key_exists( 'major', $_opts )  ? $_opts['major']  : '';
        $this->opts['confirm-all']  = array_key_exists( 'confirm-all', $_opts )  ? true  : false;
    }

    /**
     * Outputs help text.
     */
    function help() {
        $this->print( file_get_contents( __DIR__ . '/help.txt' ) );
    }

    /**
     * Convenience wrapper to output calculated info for target major version.
     */
    function major_version_header($version) {
        $this->print("MAJOR VERSION $version
----------------------
Remote:           {$this->remote}
Stable branches:  {$this->names->branch_stable} => {$this->names->beta_branch_stable}
Package branches: {$this->names->branch_package} => {$this->names->beta_branch_package}
Tags:             {$this->names->old_lae_tag_package}[-base] => {$this->names->new_lae_tag_package}[-base]");
    }

    /**
     * Output wrapper to ensure newlines.
     *
     * @param string $message Text to print.
     */
    function print($message) {
        echo "\n$message\n";
    }

    /**
     * Print wrapper for non-error, non-blocking info output.
     *
     * @param string $message Text to print.
     */
    function message($message) {
        $this->print("...$message");
    }

    /**
     * Print wrapper that displays error info and dies.
     *
     * @param string $message Text to print.
     */
    function error($message) {
        $this->print("!! $message");
        $this->help();
        exit;
    }

    /**
     * Print wrapper for requesting user confirmation.
     *
     * @param string $message Text to print.
     * @param boolean $default Default value to return when user gives no input.
     */
    function confirm($message, $default = true) {
        $yn = $default ? '[Y/n]' : '[y/N]';
        $confirm = readline("\n> $message $yn:");

        if ( preg_match( '/^y|yes$/', strtolower( $confirm ) ) ) {
            return true;
        } elseif ( preg_match( '/^n|no$/', strtolower( $confirm ) ) ) {
            return false;
        } elseif ( empty( $confirm ) ){
            return $default;
        } else {
            $this->confirm( $message, $default );
        }
    }

    /**
     * Bump patch for a semantic version string.
     *
     * @param string $version Semantic version string like 3.11.2.
     */
    function bump($version) {
        preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $matches);
        if ( is_numeric( $matches[3] ) ) {
            return $matches[1] . '.' . $matches[2] . '.' . strval($matches[3]+1);
        }
    }

    /**
     * Command exec wrapper to allow for 'confirm-all' functionality.
     *
     * @param string $cmd The shell command to run.
     */
    function exec( $cmd ) {
        if ( $this->opts['confirm-all'] ) {
            if ( !$this->confirm( "Run `$cmd`?" ) ) {
                $this->message( 'Skipping command' );
                return;
            }
        } else {
            $this->message( $cmd );
        }
        shell_exec( $cmd );
    }
}

new LAEAutomator($argv, $version_data);