<?php
use Phinx\Migration\AbstractMigration;

class CreateExecutionReportsTable extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('execution_reports', ['id' => false, 'primary_key' => ['execution_id']]);
        
        // Core identifiers
        $table->addColumn('execution_id', 'string', ['limit' => 255])
              ->addColumn('trace_id', 'string', ['limit' => 255, 'null' => true])
              ->addColumn('request_id', 'string', ['limit' => 255, 'null' => true])
              
              // Multi-tenancy & context
              ->addColumn('tenant_id', 'string', ['limit' => 255, 'null' => true])
              ->addColumn('assistant_id', 'string', ['limit' => 255, 'null' => true])
              ->addColumn('conversation_id', 'string', ['limit' => 255, 'null' => true])
              ->addColumn('workflow_id', 'string', ['limit' => 255, 'null' => true])
              ->addColumn('user_id', 'string', ['limit' => 255, 'null' => true])
              
              // Timing
              ->addColumn('started_at', 'timestamp', ['null' => true])
              ->addColumn('finished_at', 'timestamp', ['null' => true])
              ->addColumn('duration_ms', 'integer', ['null' => true])
              
              // Status
              ->addColumn('status', 'enum', ['values' => ['pending', 'running', 'success', 'failure', 'partial'], 'default' => 'pending'])
              ->addColumn('failure_reason', 'text', ['null' => true])
              ->addColumn('retry_count', 'integer', ['default' => 0])
              
              // Provider & model info
              ->addColumn('provider', 'string', ['limit' => 255, 'null' => true])
              ->addColumn('model', 'string', ['limit' => 255, 'null' => true])
              ->addColumn('endpoint', 'string', ['limit' => 2048, 'null' => true])
              
              // Performance metrics
              ->addColumn('latency_ms', 'integer', ['null' => true])
              ->addColumn('request_count', 'integer', ['default' => 0])
              
              // LLM usage
              ->addColumn('prompt_tokens', 'integer', ['default' => 0])
              ->addColumn('completion_tokens', 'integer', ['default' => 0])
              ->addColumn('total_tokens', 'integer', ['default' => 0])
              ->addColumn('estimated_cost', 'decimal', ['precision' => 12, 'scale' => 6, 'default' => 0.0])
              ->addColumn('currency', 'string', ['limit' => 3, 'default' => 'USD'])
              
              // Usage and cost attribution
              ->addColumn('usage_source', 'string', ['limit' => 64, 'default' => 'unknown'])
              ->addColumn('cost_source', 'string', ['limit' => 64, 'default' => 'none'])
              
              // JSON data
              ->addColumn('tools', 'json', ['null' => true])
              ->addColumn('memory', 'json', ['null' => true])
              ->addColumn('observability', 'json', ['null' => true])
              ->addColumn('output', 'json', ['null' => true])
              
              // Timestamps
              ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
              ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
              
              // Indexes for common queries
              ->addIndex(['tenant_id', 'created_at'])
              ->addIndex(['trace_id'], ['unique' => true])
              ->addIndex(['request_id'])
              ->addIndex(['assistant_id', 'created_at'])
              ->addIndex(['user_id', 'created_at'])
              ->addIndex(['status'])
              ->addIndex(['provider'])
              
              ->create();
    }
}
