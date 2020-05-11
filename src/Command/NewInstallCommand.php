<?php

namespace PHPCensor\Command;

use PHPCensor\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class NewInstallCommand extends Command
{
    /** @var string  */
    private $configPath = (APP_DIR . 'config.yml');

    protected function configure()
    {
        $this
            ->setName('php-censor:install')

            ->addOption('no-interactive', 'n', InputOption::VALUE_NONE, 'No interactive installation')
            ->addOption('config-from-file', 'f', InputOption::VALUE_NONE, 'Take config from yml file and ignore other options')

            ->addOption('url', null, InputOption::VALUE_OPTIONAL, 'PHP Censor installation URL')
            ->addOption('db-type', null, InputOption::VALUE_OPTIONAL, 'Database type')
            ->addOption('db-host', null, InputOption::VALUE_OPTIONAL, 'Database host')
            ->addOption('db-port', null, InputOption::VALUE_OPTIONAL, 'Database port')
            ->addOption('db-name', null, InputOption::VALUE_OPTIONAL, 'Database name')
            ->addOption('db-user', null, InputOption::VALUE_OPTIONAL, 'Database user')
            ->addOption('db-password', null, InputOption::VALUE_OPTIONAL, 'Database password')
            ->addOption('db-pgsql-sslmode', null, InputOption::VALUE_OPTIONAL, 'Postgres SSLMODE option')
            ->addOption('admin-name', null, InputOption::VALUE_OPTIONAL, 'Admin name')
            ->addOption('admin-password', null, InputOption::VALUE_OPTIONAL, 'Admin password')
            ->addOption('admin-email', null, InputOption::VALUE_OPTIONAL, 'Admin email')
            ->addOption('queue-host', null, InputOption::VALUE_OPTIONAL, 'Beanstalkd queue server hostname')
            ->addOption('queue-port', null, InputOption::VALUE_OPTIONAL, 'Beanstalkd queue server port')
            ->addOption('queue-name', null, InputOption::VALUE_OPTIONAL, 'Beanstalkd queue name')

            ->setDescription('Install PHP Censor');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $configFromFile = (bool)$input->getOption('config-from-file');

        if (!$configFromFile && !$this->isInstalled()) {
            $output->writeln('<error>The PHP Censor config file exists and is not empty. PHP Censor is already installed!</error>');

            return false;
        }

        $output->writeln('');
        $output->writeln('<info>***************************************</info>');
        $output->writeln('<info>*  Welcome to PHP Censor installation *</info>');
        $output->writeln('<info>***************************************</info>');
        $output->writeln('');

        $output->writeln('Checking requirements...');

        $errors = $this->checkRequirements();
        if ($errors) {
            foreach ($errors as $error) {
                $output->writeln('');
                $output->writeln("<error>$error</error>");
            }

            return false;
        }

        $output->writeln('');
        $output->writeln('<info>OK</info>');

        if (!$configFromFile) {
            $connectionVerified = false;
            while (!$connectionVerified) {
                $db                 = $this->getDatabaseInformation($input, $output);
                $connectionVerified = $this->verifyDatabaseDetails($db, $output);
            }
            $output->writeln('');

            $conf                           = [];
            $conf['php-censor']             = $this->getConfigInformation($input, $output);
            $conf['php-censor']['database'] = $db;

            $this->writeConfigFile($conf);
        }

        $this->loadConfig();
        if (!$this->setupDatabase($output)) {
            return false;
        }

        $admin = $this->getAdminInformation($input, $output);
        $this->createAdminUser($admin, $output);

        $this->createDefaultGroup($output);
    }

    private function isInstalled(): bool
    {
        if (\is_file($this->configPath)) {
            $content = \file_get_contents($this->configPath);

            if (!empty($content)) {
                return false;
            }
        }

        return true;
    }

    private function checkRequirements(): array
    {
        $errors = [];

        if (!(\version_compare(PHP_VERSION, '7.1.0') >= 0)) {
            $errors[] = 'PHP Censor requires at least PHP 7.1.0! Installed PHP ' . PHP_VERSION;
        }

        $requiredExtensions = ['PDO', 'libxml', 'SimpleXML', 'xml', 'dom', 'json', 'curl', 'bcmath', 'openssl'];
        foreach ($requiredExtensions as $requiredExtension) {
            if (!\extension_loaded($requiredExtension)) {
                $errors[] = 'Extension required: ' . $requiredExtension;
            }
        }

        $requiredFunctions = ['exec', 'shell_exec', 'proc_open'];
        foreach ($requiredFunctions as $requiredFunction) {
            if (!\function_exists($requiredFunction)) {
                $errors[] = 'Function required: ' . $requiredFunction;
            }
        }

        return $errors;
    }

    private function loadConfig(): void
    {
        $config = Config::getInstance();

        if (\is_file($this->configPath)) {
            $config->loadYaml($this->configPath);
        }
    }

    private function getDatabaseInformation(InputInterface $input): array
    {
        $dbType     = (string)$input->getOption('db-type');
        $dbHost     = (string)$input->getOption('db-host');
        $dbName     = (string)$input->getOption('db-name');
        $dbUser     = (string)$input->getOption('db-user');
        $dbPassword = (string)$input->getOption('db-password');

        $defaultPort = 3306;
        if ('pgsql' === $dbType) {
            $defaultPort = 5432;
        }

        $dbPort = (int)$input->getOption('db-port');
        if (!$dbPort) {
            $dbPort = $defaultPort;
        }

        $dbPgsqlSslmode = (string)$input->getOption('db-pgsql-sslmode');
        if (!$dbPgsqlSslmode) {
            $dbPgsqlSslmode = 'prefer';
        }

        $dbConfig = [
            0 => [
                'host' => $dbHost,
                'port' => $dbPort,
            ],
        ];

        if ($dbType === 'pgsql') {
            $dbConfig[0]['pgsql-sslmode'] = $dbPgsqlSslmode;
        }

        return [
            'type'     => $dbType,
            'name'     => $dbName,
            'username' => $dbUser,
            'password' => $dbPassword,
            'servers'  => [
                'read'  => $dbConfig,
                'write' => $dbConfig,
            ],
        ];
    }
}
