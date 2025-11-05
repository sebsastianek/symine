# Database Migrations

This directory contains Doctrine migrations for the Redmine-compatible database schema.

## About This Schema

The entity classes in `src/Entity` exactly match the Redmine database structure. This allows the application to:

1. **Work with existing Redmine databases** - You can point this application at an existing Redmine database
2. **Create fresh installations** - Generate a new Redmine-compatible database from scratch

## Initial Migration

The initial migration (`Version20251105114040.php`) is a placeholder that documents the schema structure.

To actually create the database schema, you have two options:

### Option 1: For Fresh Installation

```bash
# Create the database
bin/console doctrine:database:create

# Generate and run all table creation SQL
bin/console doctrine:schema:update --force

# Or preview the SQL first
bin/console doctrine:schema:update --dump-sql
```

### Option 2: For Existing Redmine Database

If you already have a Redmine database:

1. Configure `DATABASE_URL` in `.env` or `.env.local` to point to your Redmine database
2. The application should work immediately with the existing data
3. Run migrations if there are any schema differences: `bin/console doctrine:migrations:migrate`

## Generating New Migrations

When you modify entities:

```bash
# Generate a migration for entity changes
bin/console doctrine:migrations:diff

# Review the generated migration in migrations/
# Then run it
bin/console doctrine:migrations:migrate
```

## Entity Overview

The schema includes 60+ entities matching Redmine's complete structure:

**Core Tables:**
- users (STI: User, Group, GroupAnonymous, GroupNonMember)
- projects
- issues
- trackers
- issue_statuses
- roles
- members & member_roles

**All Redmine Modules:**
- Time tracking (time_entries)
- Wiki (wikis, wiki_pages, wiki_contents, wiki_content_versions)
- Forums (boards, messages)
- News & Documents
- Files (attachments)
- Repository integration (repositories, changesets, changes)
- Custom fields
- Workflows
- Queries (saved filters)

See `STRUCTURE_REVIEW.md` for complete entity list and comparison with Redmine.
