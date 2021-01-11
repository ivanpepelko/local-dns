#!/usr/bin/env php
<?php

use Aperture\LocalDns\LocalDns;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;

require_once __DIR__ . '/vendor/autoload.php';

(new class extends SingleCommandApplication {
    protected static $defaultName = 'local-dns';

    protected function configure()
    {
        $this
            ->setVersion('1.0.0-beta')
            ->addOption(
                'hosts-file',
                null,
                InputOption::VALUE_REQUIRED,
                'Override hosts file path (for use in Docker environment)'
            )
            ->addOption(
                'ns',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Additional nameserver (by default those listed in /etc/resolv.conf are used)',
                []
            )
            ->addOption(
                'override-ns',
                'o',
                InputOption::VALUE_NONE,
                'If set, /etc/resolv.conf nameservers will not be used (only nameservers defined by <info>--ns</info> option)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $hostsFile = $input->getOption('hosts-file');
        $nameservers = $input->getOption('ns');
        $override = $input->getOption('override-ns');

        if ($hostsFile !== null) {
            if (!is_readable($hostsFile)) {
                $output->writeln('<error>Provided hosts file is not readable</error>');
                return 1;
            }

            if (!is_file($hostsFile)) {
                $output->writeln('<error>Provided hosts file is not regular file</error>');
                return 1;
            }
        }

        if ($override && !$nameservers) {
            $output->writeln('<comment>Option -o|--override-ns was provided without additional nameservers, ignoring</comment>');
            $override = false;
        }

        LocalDns::run($hostsFile, $nameservers, $override);

        return 0;
    }

})->run();
