<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\WikiContentVersion;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class WikiContentVersionFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Version 1 of E-Commerce Home page content
        $version1 = new WikiContentVersion();
        $version1->setWikiContent($this->getReference('wiki-content-home-v1', \App\Entity\WikiContent::class));
        $version1->setPage($this->getReference('wiki-page-ecommerce-home', \App\Entity\WikiPage::class));
        $version1->setAuthor($this->getReference('user-jsmith', \App\Entity\User::class));
        $version1->setData(gzcompress('# E-Commerce Platform Wiki

Welcome to the E-Commerce Platform project wiki.

## Quick Links

* Setup Guide
* API Documentation  
* Deployment Guide

## Project Overview

This e-commerce platform is built using modern web technologies.'));
        $version1->setCompression('gzip');
        $version1->setComments('Initial wiki home page creation');
        $version1->setUpdatedOn(new \DateTime('2024-01-02 10:30:00'));
        $version1->setVersion(1);
        
        $manager->persist($version1);
        $this->addReference('wiki-version-home-v1', $version1);

        // Version 2 of E-Commerce Home page content (updated)
        $version2 = new WikiContentVersion();
        $version2->setWikiContent($this->getReference('wiki-content-home-v2', \App\Entity\WikiContent::class));
        $version2->setPage($this->getReference('wiki-page-ecommerce-home', \App\Entity\WikiPage::class));
        $version2->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $version2->setData(gzcompress('# E-Commerce Platform Wiki

Welcome to the E-Commerce Platform project wiki. This is your central hub for project documentation, guides, and resources.

## Quick Links

* Setup Guide - Getting started with development
* API Documentation - REST API reference
* Deployment Guide - Production deployment instructions
* Database Schema - Database structure and relationships
* Testing Guidelines - Quality assurance procedures

## Project Overview

This e-commerce platform is built using modern web technologies and follows best practices for scalability and maintainability.

### Technology Stack

- **Backend**: Symfony 7.3
- **Frontend**: React 18 with TypeScript
- **Database**: MySQL 8.0
- **Cache**: Redis 7.x
- **Queue**: RabbitMQ 3.x
- **Search**: Elasticsearch 8.x

### Architecture

The platform follows a microservices architecture with the following main components:

- **User Service**: Authentication and user management
- **Product Service**: Catalog and inventory management
- **Order Service**: Order processing and fulfillment
- **Payment Service**: Payment processing and billing
- **Notification Service**: Email and SMS notifications'));
        $version2->setCompression('gzip');
        $version2->setComments('Added architecture section and updated technology stack');
        $version2->setUpdatedOn(new \DateTime('2024-01-15 16:45:00'));
        $version2->setVersion(2);
        
        $manager->persist($version2);
        $this->addReference('wiki-version-home-v2', $version2);

        // Version 1 of Setup Guide content
        $version3 = new WikiContentVersion();
        $version3->setWikiContent($this->getReference('wiki-content-setup-v1', \App\Entity\WikiContent::class));
        $version3->setPage($this->getReference('wiki-page-setup-guide', \App\Entity\WikiPage::class));
        $version3->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $version3->setData(gzcompress('# Development Setup Guide

This guide will help you set up the development environment for the E-Commerce Platform.

## Prerequisites

Before you begin, ensure you have the following installed:

- PHP 8.2 or higher
- Composer 2.x
- Node.js 18.x or higher
- npm or yarn
- MySQL 8.0
- Redis (optional for development)

## Backend Setup

1. Clone the repository:
   ```bash
   git clone https://github.com/company/ecommerce-platform.git
   cd ecommerce-platform
   ```

2. Install PHP dependencies:
   ```bash
   composer install
   ```

3. Set up environment variables:
   ```bash
   cp .env .env.local
   # Edit .env.local with your database credentials
   ```

4. Create the database:
   ```bash
   php bin/console doctrine:database:create
   php bin/console doctrine:migrations:migrate
   ```

5. Load sample data:
   ```bash
   php bin/console doctrine:fixtures:load
   ```'));
        $version3->setCompression('gzip');
        $version3->setComments('Initial setup guide documentation');
        $version3->setUpdatedOn(new \DateTime('2024-01-05 14:00:00'));
        $version3->setVersion(1);
        
        $manager->persist($version3);
        $this->addReference('wiki-version-setup-v1', $version3);

        // Version 1 of API Documentation content
        $version4 = new WikiContentVersion();
        $version4->setWikiContent($this->getReference('wiki-content-api-v1', \App\Entity\WikiContent::class));
        $version4->setPage($this->getReference('wiki-page-api-docs', \App\Entity\WikiPage::class));
        $version4->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $version4->setData(gzcompress('# API Documentation

The E-Commerce Platform provides a comprehensive REST API for integrating with external systems.

## Base URL

```
https://api.ecommerce.company.com/v1
```

## Authentication

The API uses JWT tokens for authentication. Include the token in the Authorization header:

```
Authorization: Bearer your-jwt-token
```

### Getting a Token

**POST** `/auth/login`

```json
{
  "email": "user@example.com",
  "password": "password"
}
```

**Response:**
```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "expires_at": "2024-01-01T12:00:00Z"
}
```

## User Management

### Get User Profile

**GET** `/users/me`

**Response:**
```json
{
  "id": 1,
  "email": "user@example.com", 
  "first_name": "John",
  "last_name": "Doe",
  "created_at": "2024-01-01T10:00:00Z"
}
```'));
        $version4->setCompression('gzip');
        $version4->setComments('Initial API documentation with auth and basic endpoints');
        $version4->setUpdatedOn(new \DateTime('2024-01-08 11:20:00'));
        $version4->setVersion(1);
        
        $manager->persist($version4);
        $this->addReference('wiki-version-api-v1', $version4);

        // Version 1 of Documentation Home page content
        $version5 = new WikiContentVersion();
        $version5->setWikiContent($this->getReference('wiki-content-docs-home-v1', \App\Entity\WikiContent::class));
        $version5->setPage($this->getReference('wiki-page-docs-home', \App\Entity\WikiPage::class));
        $version5->setAuthor($this->getReference('user-dbrown', \App\Entity\User::class));
        $version5->setData(gzcompress('# Documentation Home

Welcome to the central documentation hub for all our projects.

## Available Documentation

### Project Wikis

- E-Commerce Platform - E-commerce development documentation
- CRM System - Customer relationship management docs
- Mobile Application - Mobile app development guides

### Development Resources

- User Guide - End-user documentation and tutorials
- Technical Specifications - System architecture and design
- API Documentation - REST API references and examples
- Setup Guides - Installation and configuration instructions

### Standards and Guidelines

- **Coding Standards**: Follow PSR-12 for PHP code
- **Git Workflow**: Use feature branches and pull requests
- **Testing**: Minimum 80% code coverage required
- **Documentation**: Update docs with every feature change'));
        $version5->setCompression('gzip');
        $version5->setComments('Initial documentation home page content');
        $version5->setUpdatedOn(new \DateTime('2024-02-10 17:00:00'));
        $version5->setVersion(1);
        
        $manager->persist($version5);
        $this->addReference('wiki-version-docs-home-v1', $version5);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            WikiContentFixtures::class,
            WikiFixtures::class,
            UserFixtures::class,
        ];
    }
}