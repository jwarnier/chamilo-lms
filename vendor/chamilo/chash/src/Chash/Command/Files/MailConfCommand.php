<?php

namespace Chash\Command\Files;

use Chash\Command\Database\CommonChamiloDatabaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Returns the current mail configuration
 */
class MailConfCommand extends CommonChamiloDatabaseCommand
{
    /**
     *
     */
    protected function configure()
    {
        parent::configure();
        $this
            ->setName('files:show_mail_conf')
            ->setDescription('Returns the current mail config');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);

        $output->writeln('<comment>Current mail configuration:</comment>');

        $path = $this->getHelper('configuration')->getConfigurationPath();
        $path .= 'mail.conf.php';
        define('IS_WINDOWS_OS',strtolower(substr(php_uname(), 0, 3 )) == 'win'?true:false);
        if (isset($path) && is_file($path)) {
            $output->writeln('File: '.$path);
            $lines = file($path);
            $list = array('SMTP_HOST','SMTP_PORT','SMTP_MAILER','SMTP_AUTH','SMTP_USER','SMTP_PASS');
            foreach ($lines as $line) {
                $match = array();
                if (preg_match("/platform_email\['(.*)'\]/",$line,$match)) {
                    if (in_array($match[1],$list)) {
                        eval($line);
                    }
                }
            }
            $output->writeln('Host:     '.$platform_email['SMTP_HOST']);
            $output->writeln('Port:     '.$platform_email['SMTP_PORT']);
            $output->writeln('Mailer:   '.$platform_email['SMTP_MAILER']);
            $output->writeln('Auth SMTP:'.$platform_email['SMTP_AUTH']);
            $output->writeln('User:     '.$platform_email['SMTP_USER']);
            $output->writeln('Pass:     '.$platform_email['SMTP_PASS']);
        } else {
            $output->writeln("<comment>Nothing to print</comment>");
        }
    }
}
