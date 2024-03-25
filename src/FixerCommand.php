<?php

declare(strict_types=1);

namespace FaktorE\CLI\Sniffy;

use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use PhpCsFixer\Config;
use Symfony\Component\Finder\Finder;

final class FixerCommand extends Command
{
    protected string $sniffyStorage = __DIR__ . '/Fixer.json';
    protected static $defaultName = 'Sniffy';
    protected array $sniffs = [];
    protected array $unmatchedSniffs = [];

    protected function configure(): void
    {
        $this->setDescription('Parse PHP-CS-Fixer rules');
        $this->setHelp(
            // phpcs:ignore Squiz.PHP.Heredoc
            <<<'EOT'
                Very simple checker to see which rules
                <info>PHP-CS-Fixer</info> has available, and which
                of them are unused in <info>.php-cs-fixer</info>.

                The set of "known ignored rules" is stored
                in a "<info>Fixer.json</info>" data file, and updated
                with all known rules. In case new rules
                get added to PHP-CS-Fixer, this tool
                will reveal those.

                EOT
        );

        $this->setDefinition(
            [
                new InputOption(
                    'dry-run',
                    'd',
                    InputOption::VALUE_NONE,
                    'When set, the Fixer.json file will not be updated.'
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
                    '../vendor/friendsofphp/php-cs-fixer/src/RuleSet/Sets'
                ),

                new InputArgument(
                    'config',
                    InputArgument::OPTIONAL,
                    'Defines the directory and filename to your ruleset configuration, '
                    . 'relative to current directory (<info>' . __DIR__ . '/</info>).',
                    '../.php-cs-fixer.php'
                ),
            ]
        );
    }

    protected function isSniffed(string $name): array
    {
        $found = [];
        foreach ($this->sniffs as $ruleId => $meta) {
            if ($name === $ruleId) {
                $found[] = '[' . json_encode($meta) . ']';
                unset($this->unmatchedSniffs[$ruleId]);
            }
        }

        return $found;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rootDirectory = __DIR__ . '/' . $input->getArgument('standards-directory');
        $finder = new Finder();
        $setFiles = $finder
            ->files()
            ->in($rootDirectory)
            ->name('*Set.php');

        $sets = 0;
        foreach ($setFiles as $setFile) {
            $set = $setFile->getRealPath();
            $sets++;

            if (preg_match('@PHPUnit@i', $set)) {
                continue;
            }

            require $set;
            $className = str_replace('.php', '', basename($set));
            $className = 'PhpCsFixer\RuleSet\Sets\\' . $className;
            $rulesetObject = new $className();
            $rules = $rulesetObject->getRules();
            $this->sniffs = array_merge_recursive($this->sniffs, $rules);
        }

        ksort($this->sniffs);
        $this->unmatchedSniffs = $this->sniffs;

        if ($input->getOption('reveal')) {
            $output->writeln('Available rules:');
            $output->writeln(print_r($this->sniffs, true));
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

        $configDirectory = __DIR__ . '/' . $input->getArgument('config');

        if (!file_exists($configDirectory)) {
            $output->writeln(
                sprintf(
                    '<error>File %s not found, missing ruleset.</error>',
                    $configDirectory
                )
            );
        } else {
            /** @var Config $config */
            $config = include $configDirectory;

            $rules = $config->getRules();

            $rulecount = 0;
            foreach ($rules as $ruleId => $ruleMeta) {
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
