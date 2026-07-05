<?php
use Phinx\Migration\AbstractMigration;

class AddExecutionReportAnalyticsFields extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('execution_reports');
        
        // Analytics: Token accounting
        $table->addColumn('cached_tokens', 'integer', ['default' => 0, 'after' => 'total_tokens'])
              ->addColumn('embedding_tokens', 'integer', ['default' => 0, 'after' => 'cached_tokens'])
              
              // Analytics: Cost reconciliation
              ->addColumn('actual_cost', 'decimal', ['precision' => 12, 'scale' => 6, 'null' => true, 'after' => 'estimated_cost'])
              
              // Analytics: Performance metrics
              ->addColumn('queue_time_ms', 'integer', ['null' => true, 'after' => 'latency_ms'])
              ->addColumn('tool_count', 'integer', ['default' => 0, 'after' => 'queue_time_ms'])
              
              // Analytics: Response characteristics
              ->addColumn('streamed', 'boolean', ['default' => false, 'after' => 'tool_count'])
              ->addColumn('provider_version', 'string', ['limit' => 255, 'null' => true, 'after' => 'streamed'])
              ->addColumn('retry_count', 'integer', ['default' => 0, 'after' => 'provider_version'])
              
              ->update();
    }
}
