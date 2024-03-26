<?php

declare(strict_types=1);

namespace FaktorE\CLI\Sniffy;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class GithookCommand extends Command
{
    protected static $defaultName = 'Githook';
    protected array $knownOS = [
        'auto',
        'mac',
        'windows',
        'wsl',
        'linux'
    ];

    protected function configure(): void
    {
        $this->setDescription('Set up git hooks');
        $this->setHelp(
            // phpcs:ignore Squiz.PHP.Heredoc
            <<<'EOT'
                Auto-detects your OS and allows to setup
                git hooks that perform checks.

                On every "git commit" the checks will be executed,
                and prevent making a commit with failing
                checks.

                You can override the auto-detection.

                EOT
        );

        $this->setDefinition(
            [
                new InputArgument(
                    'os',
                    InputArgument::OPTIONAL,
                    sprintf(
                        "Sets the OS (Operating System)\nOne of: <comment>[%s]</comment>",
                        implode(', ', $this->knownOS)
                    ),
                    'auto'
                ),
                new InputOption(
                    'remove',
                    'r',
                    InputOption::VALUE_NONE,
                    'When set, the pre-commit git hook will be removed.'
                ),
                new InputOption(
                    'force',
                    'f',
                    InputOption::VALUE_NONE,
                    'When set and used with option "--remove", the pre-commit git hook will '
                    . 'be removed even if it does not match expected contents.'
                ),
            ]
        );
    }

    protected function detectOperatingSystem(): string
    {
        $uname = php_uname('s');
        if (str_contains($uname, 'Darwin')) {
            return 'mac';
        } elseif (str_contains($uname, 'Windows')) {
            if (str_contains($uname, 'Linux')) {
                return 'wsl'; // Windows Subsystem for Linux
            } else {
                return 'windows';
            }
        } elseif (str_contains($uname, 'Linux')) {
            return 'linux';
        } else {
            return 'unknown';
        }
    }

    protected function getGitDir(): string
    {
        $dirParts = explode(DIRECTORY_SEPARATOR, realpath(__DIR__));

        while(count($dirParts) > 0) {
            $checkDir = implode(DIRECTORY_SEPARATOR, $dirParts);
            if (is_dir($checkDir . DIRECTORY_SEPARATOR . '.git')) {
                return $checkDir . DIRECTORY_SEPARATOR . '.git';
            }
            array_pop($dirParts);
        }

        return '';
    }

    protected function getGitHook($os): string
    {
        $hook = '';

        switch($os) {
            case 'mac':
            case 'linux':
            case 'wsl':
                // phpcs:ignore Squiz.PHP.Heredoc
                $hook .=
                    <<<'EOT'
#!/usr/bin/env bash

ddev composer ci:php
ERROR_CODE=$?

if [ ${ERROR_CODE} -ne 0 ];then
    echo -e "\nERROR: CI failure. Check PHPStan / PHP_CodeSniffer / PHP-CS-Fixer output!\n"
    exit 1
else
    exit 0
fi
EOT;

                break;

            case 'windows':
                // phpcs:ignore Squiz.PHP.Heredoc
                $hook .=
                    <<<'EOT'
#!/usr/bin/env sh

ddev composer ci:php
ERROR_CODE=$?

if [ ${ERROR_CODE} -ne 0 ];then
    echo -e "\nERROR: CI failure. Check PHPStan / PHP_CodeSniffer / PHP-CS-Fixer output!\n"
    exit 1
else
    exit 0
fi
EOT;

                break;

        }

        return $hook;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $os = $input->getArgument('os');
        if ($os === 'auto') {
            $os = $this->detectOperatingSystem();
        }
        if (!in_array($os, $this->knownOS, true)) {
            $output->writeln('<error>Could not auto-detect OS. Please specify manually.</error>');
            return Command::FAILURE;
        }

        $output->writeln('Your OS: <info>' . $os . '</info>');

        $gitDir = $this->getGitDir();

        if ($gitDir === '') {
            $output->writeln('<error>Could not find .git directory.</error>');
            return Command::FAILURE;
        }
        $output->writeln('Your .git: <info>' . $gitDir . '</info>');

        $githookContent = $this->getGitHook($os);

        $githookFile = $gitDir . DIRECTORY_SEPARATOR . 'hooks' . DIRECTORY_SEPARATOR . 'pre-commit';
        if (file_exists($githookFile)) {
            if ($githookContent === file_get_contents($githookFile)) {
                if ($input->getOption('remove')) {
                    $output->writeln(
                        sprintf(
                            '<info>Removed git hook in: <comment>%s</comment></info>',
                            $githookFile
                        )
                    );
                    unlink($githookFile);
                    return Command::SUCCESS;
                }

                $output->writeln(
                    sprintf(
                        '<info>This git hook already exists in: <comment>%s</comment></info>',
                        $githookFile
                    )
                );
                return Command::SUCCESS;
            } elseif ($input->getOption('remove')) {
                if ($input->getOption('force')) {
                    $output->writeln(
                        sprintf(
                            '<info>Removed git hook WITH FORCE in: <comment>%s</comment></info>',
                            $githookFile
                        )
                    );
                    unlink($githookFile);
                    return Command::SUCCESS;
                } else {
                    $output->writeln(
                        sprintf(
                            '<error>Could not remove git hook, does not contain expected input in: <comment>%s</comment></error>',
                            $githookFile
                        )
                    );

                    return Command::FAILURE;
                }
            }

            $output->writeln(
                sprintf(
                    '<error>A different pre-commit git hook already exists. Please remove: <comment>%s</comment></error>',
                    $githookFile
                )
            );
            return Command::FAILURE;
        } elseif ($input->getOption('remove')) {
            $output->writeln(
                sprintf(
                    '<error>No existing git hook found, cannot remove: <comment>%s</comment></error>',
                    $githookFile
                )
            );

            return Command::FAILURE;
        }

        if (!file_put_contents($githookFile, $githookContent)) {
            $output->writeln(
                sprintf(
                    '<error>Could not write git hook: <comment>%s</comment></error>',
                    $githookFile
                )
            );
            return Command::FAILURE;
        }

        $output->writeln(
            sprintf(
                '<info>Created git hook in: <comment>%s</comment></info>',
                $githookFile
            )
        );

        chmod($githookFile, 0755);

        return Command::SUCCESS;
    }
}
