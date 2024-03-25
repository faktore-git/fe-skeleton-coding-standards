<?php

declare(strict_types=1);

namespace FaktorE\CLI\Sniffy;

use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

final class SniffyCommand extends Command
{
    protected static $defaultName = 'Sniffy';
    protected string $sniffyStorage = __DIR__ . '/../../../../Sniffy.json';
    protected array $sniffs = [];
    protected array $unmatchedSniffs = [];

    protected function configure(): void
    {
        $this->setDescription('Parse PHP_CodeSniffer rules');
        $this->setHelp(
            // phpcs:ignore Squiz.PHP.Heredoc
            <<<'EOT'
                Very simple checker to see which rules
                <info>PHP_CodeSniffer</info> has available, and which
                of them are unused in <info>phpcs.xml</info>.

                The set of "known ignored rules" is stored
                in a "<info>Sniffy.json</info>" data file, and updated
                with all known rules. In case new rules
                get added to PHP_CodeSniffer, this tool
                will reveal those.

                EOT
        );

        $this->setDefinition(
            [
                new InputOption(
                    'dry-run',
                    'd',
                    InputOption::VALUE_NONE,
                    'When set, the Sniffy.json file will not be updated.'
                ),

                new InputOption(
                    'reveal',
                    'r',
                    InputOption::VALUE_NONE,
                    'When set, the available rules will be shown alphabetically.'
                ),

                new InputOption(
                    'reveal-unmatched',
                    'u',
                    InputOption::VALUE_NONE,
                    'When set, the unmatched rules will be shown alphabetically.'
                ),

                new InputArgument(
                    'standards-directory',
                    InputArgument::OPTIONAL,
                    'Defines the "Standards" directory of PHP_CodeSniffer, '
                    . 'relative to current directory (<info>' . __DIR__ . '/</info>).',
                    '../../../squizlabs/php_codesniffer/src/Standards'
                ),

                new InputArgument(
                    'config',
                    InputArgument::OPTIONAL,
                    'Defines the directory and filename to your ruleset xml. '
                    . 'relative to current directory (<info>' . __DIR__ . '/</info>).',
                    '../../../../phpcs.xml'
                ),
            ]
        );
    }

    protected function isSniffed(string $name): array
    {
        $found = [];
        foreach ($this->sniffs as $ruleId => $meta) {
            if (str_starts_with($ruleId, $name . '.') || $ruleId === $name) {
                $found[] = $ruleId;
                // Remove from set of all sniffs, so that the remaining
                // entries in this array will be the rules, that were
                // unmatched
                unset($this->unmatchedSniffs[$ruleId]);
            }
        }

        return $found;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rootDirectory = __DIR__ . '/' . $input->getArgument('standards-directory');
        $finder = new Finder();
        $sniffsDirectories = $finder
            ->directories()
            ->in($rootDirectory)
            ->name('Sniffs');

        foreach ($sniffsDirectories as $sniffsDirectory) {
            $sniffsPath = $sniffsDirectory->getRealPath();

            $filesFinder = new Finder();
            $files = $filesFinder
                ->files()
                ->in($sniffsPath)
                ->name('*.php');

            // Loop through the files found within the current "Sniffs" directory
            foreach ($files as $file) {
                $filepath = $file->getRealPath();

                if (preg_match('@Standards/(.+)/Sniffs/(.+)Sniff\.php$@imsU', $filepath, $m)) {
                    $ruleId = $m[1] . '.' . str_replace('/', '.', $m[2]);
                    if (isset($sniffs[$ruleId])) {
                        throw new Exception('Oops. Duplicate rule ' . $ruleId);
                    }
                    $this->sniffs[$ruleId] = [
                        'file' => $filepath,
                        'ruleName' => $ruleId,
                    ];
                } else {
                    $output->writeln(
                        sprintf(
                            '<error>Confused about naming scheme of %s</error>.',
                            $filepath
                        )
                    );
                }
            }
        }

        $output->writeln(
            sprintf(
                'Counted <comment>%d</comment> rules in distribution',
                count($this->sniffs)
            )
        );
        ksort($this->sniffs);
        $this->unmatchedSniffs = $this->sniffs;

        if ($input->getOption('reveal')) {
            $output->writeln('Available rules:');
            $output->writeln('  * ' . implode("\n  * ", array_keys($this->sniffs)));
        }

        if (file_exists($this->sniffyStorage)) {
            $oldSniffs = json_decode(file_get_contents($this->sniffyStorage), true);
            $removedSniffs = $oldSniffs;
            $newSniffs = $this->sniffs;
            foreach ($this->sniffs as $ruleId => $sniff) {
                if (isset($oldSniffs[$ruleId])) {
                    unset($newSniffs[$ruleId]);
                    unset($removedSniffs[$ruleId]);
                }
            }

            $output->writeln(
                sprintf(
                    '<comment>%d</comment> rules removed, <comment>%d</comment> new rules.',
                    count($removedSniffs),
                    count($newSniffs)
                )
            );

            if (count($removedSniffs) > 0) {
                $output->writeln('Removed:');
                foreach ($removedSniffs as $ruleId => $sniff) {
                    $output->writeln('  * ' . $ruleId);
                }
            }

            if (count($newSniffs) > 0) {
                $output->writeln('New:');
                foreach ($newSniffs as $ruleId => $sniff) {
                    $output->writeln('  * ' . $ruleId);
                }
            }
        }

        $config = __DIR__ . '/' . $input->getArgument('config');
        if (!file_exists($config)) {
            $output->writeln(
                sprintf(
                    '<error>File %s not found, missing ruleset.</error>',
                    $config
                )
            );
        } else {
            $xml = simplexml_load_file($config);
            $rulecount = 0;
            foreach ($xml->rule as $rule) {
                $ruleId = (string)$rule->attributes()['ref'];
                $matched = $this->isSniffed($ruleId);
                $rulecount += count($matched);
                if (count($matched) > 0) {
                    $output->writeln(
                        sprintf(
                            'Rules matching for <comment>%s</comment>: <comment>%s</comment>',
                            $ruleId,
                            implode(', ', $matched)
                        )
                    );
                } else {
                    $output->writeln(
                        sprintf(
                            '<error>No rules</error> matching for <comment>%s</comment>.',
                            $ruleId,
                        )
                    );
                }
            }

            $output->writeln(
                sprintf(
                    '<info>Total of <comment>%d</comment> matched to available rules.</info>',
                    $rulecount
                )
            );

            if ($input->getOption('reveal-unmatched')) {
                $output->writeln('Unmatched rules:');
                $output->writeln('  * ' . implode("\n  * ", array_keys($this->unmatchedSniffs)));
            } else {
                $output->writeln(
                    sprintf(
                        '<info>Total of <comment>%d</comment> not enabled rules.</info>',
                        count($this->unmatchedSniffs)
                    )
                );
            }
        }

        if ($input->getOption('dry-run')) {
            $output->writeln(
                sprintf(
                    '<info>Not updating %s due to dry-run.</info>',
                    $this->sniffyStorage
                )
            );
        } else {
            file_put_contents($this->sniffyStorage, json_encode($this->sniffs));
            $output->writeln(
                sprintf(
                    '<info>Wrote <comment>%s</comment> with <comment>%d</comment> rules. See you next time.</info>',
                    $this->sniffyStorage,
                    count($this->sniffs),
                )
            );
        }

        return Command::SUCCESS;
    }
}
