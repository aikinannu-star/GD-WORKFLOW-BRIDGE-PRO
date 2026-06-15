<?php
use Phinx\Migration\AbstractMigration;

class CreateSocialAndMediaTables extends AbstractMigration
{
    public function change()
    {
        // posts
        $posts = $this->table('posts', ['id' => false, 'primary_key' => ['id']]);
        $posts->addColumn('id', 'uuid')
              ->addColumn('tenant_id', 'string')
              ->addColumn('user_id', 'string')
              ->addColumn('content', 'text', ['null' => true])
              ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
              ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
              ->addIndex(['tenant_id', 'created_at'])
              ->create();

        // comments
        $comments = $this->table('comments', ['id' => false, 'primary_key' => ['id']]);
        $comments->addColumn('id', 'uuid')
                 ->addColumn('post_id', 'uuid')
                 ->addColumn('tenant_id', 'string')
                 ->addColumn('user_id', 'string')
                 ->addColumn('content', 'text')
                 ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                 ->addIndex(['post_id'])
                 ->create();

        // likes
        $likes = $this->table('likes', ['id' => false, 'primary_key' => ['id']]);
        $likes->addColumn('id', 'uuid')
              ->addColumn('tenant_id', 'string')
              ->addColumn('user_id', 'string')
              ->addColumn('post_id', 'uuid')
              ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
              ->addIndex(['post_id'])
              ->create();

        // follows
        $follows = $this->table('follows', ['id' => false, 'primary_key' => ['id']]);
        $follows->addColumn('id', 'uuid')
                ->addColumn('tenant_id', 'string')
                ->addColumn('follower_id', 'string')
                ->addColumn('followee_id', 'string')
                ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['follower_id'])
                ->create();

        // media metadata
        $media = $this->table('media', ['id' => false, 'primary_key' => ['id']]);
        $media->addColumn('id', 'uuid')
              ->addColumn('tenant_id', 'string')
              ->addColumn('user_id', 'string', ['null' => true])
              ->addColumn('key', 'string')
              ->addColumn('bucket', 'string', ['null' => true])
              ->addColumn('mime', 'string', ['null' => true])
              ->addColumn('size', 'integer', ['null' => true])
              ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
              ->create();
    }
}
