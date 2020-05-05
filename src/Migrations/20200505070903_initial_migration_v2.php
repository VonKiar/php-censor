<?php

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;
use PHPCensor\Model\Build;

class InitialMigrationV2 extends AbstractMigration
{
    private const LATEST_V1_MIGRATION_NAME = 'FixedProjectDefaultBranch2';

    private function getLatestV1Migration()
    {
        return $this->fetchRow(\sprintf(
            "SELECT * FROM migration WHERE migration_name = '%s' LIMIT 1",
            self::LATEST_V1_MIGRATION_NAME
        ));
    }

    private function isNewInstallationUp(): bool
    {
        $isNewInstallation = !$this->hasTable('build');
        if (!$isNewInstallation && !$this->getLatestV1Migration()) {
            throw new \RuntimeException(
                'You should upgrade your PHP Censor to latest 1.2 release before you can upgrade it to release 2.0'
            );
        }

        return $isNewInstallation;
    }

    private function isNewInstallationDown(): bool
    {
        if ($this->getLatestV1Migration()) {
            return false;
        }

        return true;
    }

    public function up()
    {
        if (!$this->isNewInstallationUp()) {
            return;
        }

        $build        = $this->table('build');
        $buildMeta    = $this->table('build_meta');
        $buildError   = $this->table('build_error');
        $project      = $this->table('project');
        $projectGroup = $this->table('project_group');
        $user         = $this->table('user');
        $environment  = $this->table('environment');

        $databaseType          = $this->getAdapter()->getAdapterType();
        $buildLogOptions       = ['null' => true];
        $buildMetaValueOptions = [];
        if ('mysql' === $databaseType) {
            $buildLogOptions['limit']       = MysqlAdapter::TEXT_LONG;
            $buildMetaValueOptions['limit'] = MysqlAdapter::TEXT_LONG;
        }

        $build
            ->addColumn('project_id', 'integer')
            ->addColumn('commit_id', 'string', ['limit' => 50])
            ->addColumn('status', 'integer', ['limit' => 4])
            ->addColumn('log', 'text', $buildLogOptions)
            ->addColumn('branch', 'string', ['limit' => 250])
            ->addColumn('tag', 'string', ['limit' => 250, 'null' => true])
            ->addColumn('create_date', 'datetime', ['null' => true])
            ->addColumn('start_date', 'datetime', ['null' => true])
            ->addColumn('finish_date', 'datetime', ['null' => true])
            ->addColumn('committer_email', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('commit_message', 'text', ['null' => true])
            ->addColumn('extra', 'text', ['null' => true])
            ->addColumn('environment', 'string', ['limit' => 250, 'null' => true])
            ->addColumn('source', 'integer', ['default' => Build::SOURCE_UNKNOWN])
            ->addColumn('user_id', 'integer', ['default' => 0])
            ->addColumn('errors_total', 'integer', ['null' => true])
            ->addColumn('errors_total_previous', 'integer', ['null' => true])
            ->addColumn('errors_new', 'integer', ['null' => true])
            ->addColumn('parent_id', 'integer', ['default' => 0])

            ->addIndex(['project_id'])
            ->addIndex(['status'])

            ->save();

        $buildMeta
            ->addColumn('build_id', 'integer')
            ->addColumn('meta_key', 'string', ['limit' => 250])
            ->addColumn('meta_value', 'text', $buildMetaValueOptions)

            ->addIndex(['build_id', 'meta_key'])

            ->save();

        $buildError
            ->addColumn('build_id', 'integer')
            ->addColumn('plugin', 'string', ['limit' => 100])
            ->addColumn('file', 'string', ['limit' => 250, 'null' => true])
            ->addColumn('line_start', 'integer', ['null' => true])
            ->addColumn('line_end', 'integer', ['null' => true])
            ->addColumn('severity', 'integer', ['limit' => 255])
            ->addColumn('message', 'text')
            ->addColumn('create_date', 'datetime')
            ->addColumn('hash', 'string', ['limit' => 32, 'default' => ''])
            ->addColumn('is_new', 'boolean', ['default' => false])

            ->addIndex(['build_id', 'create_date'])
            ->addIndex(['hash'])

            ->save();

        $environment
            ->addColumn('project_id', 'integer')
            ->addColumn('name', 'string', ['limit' => 250])
            ->addColumn('branches', 'text')

            ->addIndex(['project_id', 'name'])

            ->save();

        $project
            ->addColumn('title', 'string', ['limit' => 250])
            ->addColumn('reference', 'string', ['limit' => 250])
            ->addColumn('default_branch', 'string', ['limit' => 250])
            ->addColumn('ssh_private_key', 'text', ['null' => true])
            ->addColumn('ssh_public_key', 'text', ['null' => true])
            ->addColumn('access_information', 'string', ['limit' => 250, 'null' => true])
            ->addColumn('allow_public_status', 'integer', ['default' => 0])
            ->addColumn('type', 'string', ['limit' => 50])
            ->addColumn('build_config', 'text', ['null' => true])
            ->addColumn('archived', 'boolean', ['default' => false])
            ->addColumn('group_id', 'integer', ['default' => 1])
            ->addColumn('default_branch_only', 'integer', ['default' => 0])
            ->addColumn('create_date', 'datetime', ['null' => true])
            ->addColumn('user_id', 'integer', ['default' => 0])
            ->addColumn('overwrite_build_config', 'integer', ['default' => 1])

            ->addIndex(['title'])

            ->save();

        $projectGroup
            ->addColumn('title', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('create_date', 'datetime', ['null' => true])
            ->addColumn('user_id', 'integer', ['default' => 0])

            ->save();

        $user
            ->addColumn('email', 'string', ['limit' => 250])
            ->addColumn('hash', 'string', ['limit' => 250])
            ->addColumn('name', 'string', ['limit' => 250])
            ->addColumn('is_admin', 'integer', ['default' => 0])
            ->addColumn('provider_key', 'string', ['default' => 'internal'])
            ->addColumn('provider_data', 'string', ['null'  => true])
            ->addColumn('language', 'string', ['limit' => 5, 'null' => true])
            ->addColumn('per_page', 'integer', ['null' => true])
            ->addColumn('remember_key', 'string', ['limit' => 32, 'null' => true])

            ->addIndex(['email'], ['unique' => true])
            ->addIndex(['name'])

            ->save();

        $build
            ->addForeignKey(
                'project_id',
                'project',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE']
            )
            ->save();

        $buildMeta
            ->addForeignKey(
                'build_id',
                'build',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE']
            )
            ->save();

        $buildError
            ->addForeignKey(
                'build_id',
                'build',
                'id',
                ['delete'=> 'CASCADE', 'update' => 'CASCADE']
            )
            ->save();

        $project
            ->addForeignKey(
                'group_id',
                'project_group',
                'id',
                ['delete' => 'RESTRICT', 'update' => 'CASCADE']
            )
            ->save();
    }

    public function down()
    {
        if (!$this->isNewInstallationDown()) {
            return;
        }

        $build        = $this->table('build');
        $buildMeta    = $this->table('build_meta');
        $buildError   = $this->table('build_error');
        $project      = $this->table('project');
        $projectGroup = $this->table('project_group');
        $user         = $this->table('user');
        $environment  = $this->table('environment');

        $build
            ->dropForeignKey('project_id')
            ->save();

        $buildMeta
            ->dropForeignKey('build_id')
            ->save();

        $buildError
            ->dropForeignKey('build_id')
            ->save();

        $project
            ->dropForeignKey('group_id')
            ->save();

        $build
            ->drop()
            ->save();

        $buildMeta
            ->drop()
            ->save();

        $buildError
            ->drop()
            ->save();

        $environment
            ->drop()
            ->save();

        $project
            ->drop()
            ->save();

        $projectGroup
            ->drop()
            ->save();

        $user
            ->drop()
            ->save();
    }
}
