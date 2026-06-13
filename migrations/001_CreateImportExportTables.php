<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateImportExportTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $this->createJobs($schema);
        $this->createBatches($schema);
        $this->createFiles($schema);
        $this->createErrors($schema);
        $this->createReports($schema);
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('import_export_reports');
        $schema->dropTableIfExists('import_export_errors');
        $schema->dropTableIfExists('import_export_files');
        $schema->dropTableIfExists('import_export_batches');
        $schema->dropTableIfExists('import_export_jobs');
    }

    public function getDescription(): string
    {
        return 'Create import/export jobs, batches, files, errors, and reports tables.';
    }

    private function createJobs(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('import_export_jobs')) {
            return;
        }

        $schema->createTable('import_export_jobs', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('type', 10);
            $table->string('adapter', 120);
            $table->string('status', 20);
            $table->string('mode', 20);
            $table->string('format', 40)->nullable();
            $table->string('source_disk', 120)->nullable();
            $table->string('source_path', 2048)->nullable();
            $table->string('result_disk', 120)->nullable();
            $table->string('result_path', 2048)->nullable();
            $table->json('filters')->nullable();
            $table->json('options')->nullable();
            $table->integer('total_records')->default(0);
            $table->integer('processed_records')->default(0);
            $table->integer('failed_records')->default(0);
            $table->integer('error_overflow_count')->default(0);
            $table->string('created_by', 12)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique('uuid');
            $table->index('type');
            $table->index('status');
            $table->index('adapter');
            $table->index('created_by');
            $table->index('created_at');
        });
    }

    private function createBatches(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('import_export_batches')) {
            return;
        }

        $schema->createTable('import_export_batches', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('job_uuid', 12);
            $table->integer('sequence');
            $table->string('status', 20);
            $table->integer('offset')->default(0);
            $table->integer('limit')->default(0);
            $table->integer('processed_records')->default(0);
            $table->integer('failed_records')->default(0);
            $table->integer('attempts')->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique('uuid');
            $table->unique(['job_uuid', 'sequence'], 'uniq_import_export_batch_sequence');
            $table->index(['job_uuid', 'status', 'sequence'], 'idx_import_export_batch_job_status');
            $table->index('locked_at');
            $table->foreign('job_uuid')
                ->references('uuid')
                ->on('import_export_jobs')
                ->cascadeOnDelete();
        });
    }

    private function createFiles(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('import_export_files')) {
            return;
        }

        $schema->createTable('import_export_files', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('job_uuid', 12);
            $table->string('role', 40);
            $table->string('disk', 120);
            $table->string('path', 2048);
            $table->string('mime_type', 120)->nullable();
            $table->bigInteger('size_bytes')->default(0);
            $table->string('checksum', 128)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique('uuid');
            $table->index('job_uuid');
            $table->index('role');
            $table->foreign('job_uuid')
                ->references('uuid')
                ->on('import_export_jobs')
                ->cascadeOnDelete();
        });
    }

    private function createErrors(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('import_export_errors')) {
            return;
        }

        $schema->createTable('import_export_errors', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('job_uuid', 12);
            $table->string('batch_uuid', 12)->nullable();
            $table->integer('record_number')->nullable();
            $table->string('severity', 20);
            $table->string('code', 120);
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique('uuid');
            $table->index('job_uuid');
            $table->index('batch_uuid');
            $table->index('severity');
            $table->foreign('job_uuid')
                ->references('uuid')
                ->on('import_export_jobs')
                ->cascadeOnDelete();
            $table->foreign('batch_uuid')
                ->references('uuid')
                ->on('import_export_batches')
                ->cascadeOnDelete();
        });
    }

    private function createReports(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('import_export_reports')) {
            return;
        }

        $schema->createTable('import_export_reports', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('job_uuid', 12);
            $table->json('summary');
            $table->string('report_disk', 120)->nullable();
            $table->string('report_path', 2048)->nullable();
            $table->string('failed_records_disk', 120)->nullable();
            $table->string('failed_records_path', 2048)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique('uuid');
            $table->unique('job_uuid');
            $table->foreign('job_uuid')
                ->references('uuid')
                ->on('import_export_jobs')
                ->cascadeOnDelete();
        });
    }
}
