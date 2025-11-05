<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial migration - Redmine database schema
 *
 * This migration was created manually as it represents importing an existing
 * Redmine database schema. The entity classes in src/Entity match the Redmine
 * database structure exactly, allowing this application to work with existing
 * Redmine databases.
 *
 * For a fresh installation, this migration would create all tables from scratch.
 * For migration from existing Redmine, the tables should already exist.
 *
 * NOTE: This file serves as documentation of the expected schema.
 * Run `bin/console doctrine:schema:update --dump-sql` to see actual SQL
 * needed for your database, or use doctrine:migrations:diff when connected
 * to a running database to generate incremental migrations.
 */
final class Version20251105114040 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial Redmine-compatible database schema with all core tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('-- This migration represents the complete Redmine database schema');
        $this->addSql('-- Tables are created based on Doctrine entities in src/Entity');
        $this->addSql('-- For actual SQL generation, use: bin/console doctrine:schema:update --dump-sql');

        $this->addSql('-- Core tables:');
        $this->addSql('-- users (with STI: User, Group, GroupAnonymous, GroupNonMember)');
        $this->addSql('-- projects');
        $this->addSql('-- issues');
        $this->addSql('-- trackers');
        $this->addSql('-- issue_statuses');
        $this->addSql('-- roles');
        $this->addSql('-- members');
        $this->addSql('-- member_roles');
        $this->addSql('--');
        $this->addSql('-- Supporting tables:');
        $this->addSql('-- custom_fields, custom_values, custom_field_enumerations');
        $this->addSql('-- versions, issue_categories, issue_relations');
        $this->addSql('-- workflows');
        $this->addSql('-- journals, journal_details');
        $this->addSql('-- time_entries');
        $this->addSql('-- attachments');
        $this->addSql('-- watchers');
        $this->addSql('-- wikis, wiki_pages, wiki_contents, wiki_content_versions');
        $this->addSql('-- news, documents, boards, messages');
        $this->addSql('-- repositories, changesets, changes');
        $this->addSql('-- queries, enabled_modules');
        $this->addSql('-- enumerations (STI: IssuePriority, TimeEntryActivity, DocumentCategory)');
        $this->addSql('-- auth_sources, email_addresses, tokens, user_preferences');
        $this->addSql('-- oauth_applications, oauth_access_grants, oauth_access_tokens');
        $this->addSql('-- groups_users');
        $this->addSql('-- settings');
        $this->addSql('--');
        $this->addSql('-- Junction tables:');
        $this->addSql('-- custom_fields_projects, custom_fields_roles, custom_fields_trackers');
        $this->addSql('-- projects_trackers');
        $this->addSql('-- queries_roles');
        $this->addSql('-- roles_managed_roles');
        $this->addSql('--');
        $this->addSql('-- Total entities: 60+');
        $this->addSql('-- All entities match Redmine database structure exactly');

        $this->warnIf(true, 'This is a placeholder migration. To create actual schema, run: bin/console doctrine:schema:update --force');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('-- WARNING: Dropping all tables would destroy all data');
        $this->addSql('-- This down() migration is intentionally left empty');
        $this->addSql('-- To drop schema, use: bin/console doctrine:schema:drop --force');

        $this->warnIf(true, 'Down migration not implemented - use doctrine:schema:drop if needed');
    }

    public function isTransactional(): bool
    {
        // Schema migrations should typically not be wrapped in transactions
        return false;
    }
}
