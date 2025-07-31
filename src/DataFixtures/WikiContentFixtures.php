<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\WikiContent;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class WikiContentFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Content for E-Commerce Home page (version 1)
        $content1 = new WikiContent();
        $content1->setPage($this->getReference('wiki-page-ecommerce-home', \App\Entity\WikiPage::class));
        $content1->setAuthor($this->getReference('user-jsmith', \App\Entity\User::class));
        $content1->setText(
            "# E-Commerce Platform Wiki\n\n" .
            "Welcome to the E-Commerce Platform project wiki. This is your central hub for project documentation, guides, and resources.\n\n" .
            "## Quick Links\n\n" .
            "* [[Setup Guide]] - Getting started with development\n" .
            "* [[API Documentation]] - REST API reference\n" .
            "* [[Deployment Guide]] - Production deployment instructions\n" .
            "* [[Database Schema]] - Database structure and relationships\n\n" .
            "## Project Overview\n\n" .
            "This e-commerce platform is built using modern web technologies and follows best practices for scalability and maintainability.\n\n" .
            "### Technology Stack\n\n" .
            "- **Backend**: Symfony 7.3\n" .
            "- **Frontend**: React 18 with TypeScript\n" .
            "- **Database**: MySQL 8.0\n" .
            "- **Cache**: Redis\n" .
            "- **Queue**: RabbitMQ\n\n" .
            "## Contributing\n\n" .
            "Please read the [[Development Guidelines]] before contributing to the project.\n\n" .
            "## Support\n\n" .
            "For questions or issues, please create a support ticket in the project."
        );
        $content1->setComments('Initial wiki home page creation');
        $content1->setUpdatedOn(new \DateTime('2024-01-02 10:30:00'));
        $content1->setVersion(1);
        
        $manager->persist($content1);
        $this->addReference('wiki-content-home-v1', $content1);

        // Updated content for E-Commerce Home page (version 2)
        $content2 = new WikiContent();
        $content2->setPage($this->getReference('wiki-page-ecommerce-home', \App\Entity\WikiPage::class));
        $content2->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $content2->setText(
            "# E-Commerce Platform Wiki\n\n" .
            "Welcome to the E-Commerce Platform project wiki. This is your central hub for project documentation, guides, and resources.\n\n" .
            "## Quick Links\n\n" .
            "* [[Setup Guide]] - Getting started with development\n" .
            "* [[API Documentation]] - REST API reference\n" .
            "* [[Deployment Guide]] - Production deployment instructions\n" .
            "* [[Database Schema]] - Database structure and relationships\n" .
            "* [[Testing Guidelines]] - Quality assurance procedures\n\n" .
            "## Project Overview\n\n" .
            "This e-commerce platform is built using modern web technologies and follows best practices for scalability and maintainability.\n\n" .
            "### Technology Stack\n\n" .
            "- **Backend**: Symfony 7.3\n" .
            "- **Frontend**: React 18 with TypeScript\n" .
            "- **Database**: MySQL 8.0\n" .
            "- **Cache**: Redis 7.x\n" .
            "- **Queue**: RabbitMQ 3.x\n" .
            "- **Search**: Elasticsearch 8.x\n\n" .
            "### Architecture\n\n" .
            "The platform follows a microservices architecture with the following main components:\n\n" .
            "- **User Service**: Authentication and user management\n" .
            "- **Product Service**: Catalog and inventory management\n" .
            "- **Order Service**: Order processing and fulfillment\n" .
            "- **Payment Service**: Payment processing and billing\n" .
            "- **Notification Service**: Email and SMS notifications\n\n" .
            "## Contributing\n\n" .
            "Please read the [[Development Guidelines]] before contributing to the project.\n\n" .
            "## Support\n\n" .
            "For questions or issues, please create a support ticket in the project."
        );
        $content2->setComments('Added architecture section and updated technology stack');
        $content2->setUpdatedOn(new \DateTime('2024-01-15 16:45:00'));
        $content2->setVersion(2);
        
        $manager->persist($content2);
        $this->addReference('wiki-content-home-v2', $content2);

        // Content for Setup Guide page (version 1)
        $content3 = new WikiContent();
        $content3->setPage($this->getReference('wiki-page-setup-guide', \App\Entity\WikiPage::class));
        $content3->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $content3->setText(
            "# Development Setup Guide\n\n" .
            "This guide will help you set up the development environment for the E-Commerce Platform.\n\n" .
            "## Prerequisites\n\n" .
            "Before you begin, ensure you have the following installed:\n\n" .
            "- PHP 8.2 or higher\n" .
            "- Composer 2.x\n" .
            "- Node.js 18.x or higher\n" .
            "- npm or yarn\n" .
            "- MySQL 8.0\n" .
            "- Redis (optional for development)\n\n" .
            "## Backend Setup\n\n" .
            "1. Clone the repository:\n" .
            "   ```bash\n" .
            "   git clone https://github.com/company/ecommerce-platform.git\n" .
            "   cd ecommerce-platform\n" .
            "   ```\n\n" .
            "2. Install PHP dependencies:\n" .
            "   ```bash\n" .
            "   composer install\n" .
            "   ```\n\n" .
            "3. Set up environment variables:\n" .
            "   ```bash\n" .
            "   cp .env .env.local\n" .
            "   # Edit .env.local with your database credentials\n" .
            "   ```\n\n" .
            "4. Create the database:\n" .
            "   ```bash\n" .
            "   php bin/console doctrine:database:create\n" .
            "   php bin/console doctrine:migrations:migrate\n" .
            "   ```\n\n" .
            "5. Load sample data:\n" .
            "   ```bash\n" .
            "   php bin/console doctrine:fixtures:load\n" .
            "   ```\n\n" .
            "## Frontend Setup\n\n" .
            "1. Navigate to the frontend directory:\n" .
            "   ```bash\n" .
            "   cd frontend\n" .
            "   ```\n\n" .
            "2. Install dependencies:\n" .
            "   ```bash\n" .
            "   npm install\n" .
            "   ```\n\n" .
            "3. Start the development server:\n" .
            "   ```bash\n" .
            "   npm run dev\n" .
            "   ```\n\n" .
            "## Running the Application\n\n" .
            "1. Start the Symfony development server:\n" .
            "   ```bash\n" .
            "   symfony server:start\n" .
            "   ```\n\n" .
            "2. Access the application at `http://localhost:8000`\n\n" .
            "## Troubleshooting\n\n" .
            "### Common Issues\n\n" .
            "- **Database connection errors**: Check your `.env.local` file\n" .
            "- **Permission issues**: Ensure proper file permissions for `var/` directory\n" .
            "- **Composer issues**: Clear cache with `composer clear-cache`"
        );
        $content3->setComments('Initial setup guide documentation');
        $content3->setUpdatedOn(new \DateTime('2024-01-05 14:00:00'));
        $content3->setVersion(1);
        
        $manager->persist($content3);
        $this->addReference('wiki-content-setup-v1', $content3);

        // Content for API Documentation page (version 1)
        $content4 = new WikiContent();
        $content4->setPage($this->getReference('wiki-page-api-docs', \App\Entity\WikiPage::class));
        $content4->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $content4->setText(
            "# API Documentation\n\n" .
            "The E-Commerce Platform provides a comprehensive REST API for integrating with external systems.\n\n" .
            "## Base URL\n\n" .
            "```\n" .
            "https://api.ecommerce.company.com/v1\n" .
            "```\n\n" .
            "## Authentication\n\n" .
            "The API uses JWT tokens for authentication. Include the token in the Authorization header:\n\n" .
            "```\n" .
            "Authorization: Bearer your-jwt-token\n" .
            "```\n\n" .
            "### Getting a Token\n\n" .
            "**POST** `/auth/login`\n\n" .
            "```json\n" .
            "{\n" .
            "  \"email\": \"user@example.com\",\n" .
            "  \"password\": \"password\"\n" .
            "}\n" .
            "```\n\n" .
            "**Response:**\n" .
            "```json\n" .
            "{\n" .
            "  \"token\": \"eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...\",\n" .
            "  \"expires_at\": \"2024-01-01T12:00:00Z\"\n" .
            "}\n" .
            "```\n\n" .
            "## User Management\n\n" .
            "### Get User Profile\n\n" .
            "**GET** `/users/me`\n\n" .
            "**Response:**\n" .
            "```json\n" .
            "{\n" .
            "  \"id\": 1,\n" .
            "  \"email\": \"user@example.com\",\n" .
            "  \"first_name\": \"John\",\n" .
            "  \"last_name\": \"Doe\",\n" .
            "  \"created_at\": \"2024-01-01T10:00:00Z\"\n" .
            "}\n" .
            "```\n\n" .
            "### Update User Profile\n\n" .
            "**PUT** `/users/me`\n\n" .
            "```json\n" .
            "{\n" .
            "  \"first_name\": \"John\",\n" .
            "  \"last_name\": \"Smith\"\n" .
            "}\n" .
            "```\n\n" .
            "## Product Management\n\n" .
            "### List Products\n\n" .
            "**GET** `/products`\n\n" .
            "**Parameters:**\n" .
            "- `page` (integer): Page number (default: 1)\n" .
            "- `limit` (integer): Items per page (default: 20)\n" .
            "- `category` (string): Filter by category\n" .
            "- `search` (string): Search term\n\n" .
            "### Get Product Details\n\n" .
            "**GET** `/products/{id}`\n\n" .
            "### Create Product\n\n" .
            "**POST** `/products`\n\n" .
            "```json\n" .
            "{\n" .
            "  \"name\": \"Product Name\",\n" .
            "  \"description\": \"Product description\",\n" .
            "  \"price\": 29.99,\n" .
            "  \"category_id\": 1,\n" .
            "  \"stock_quantity\": 100\n" .
            "}\n" .
            "```\n\n" .
            "## Error Handling\n\n" .
            "The API returns standard HTTP status codes:\n\n" .
            "- `200` - Success\n" .
            "- `201` - Created\n" .
            "- `400` - Bad Request\n" .
            "- `401` - Unauthorized\n" .
            "- `403` - Forbidden\n" .
            "- `404` - Not Found\n" .
            "- `500` - Internal Server Error\n\n" .
            "Error responses include a detailed message:\n\n" .
            "```json\n" .
            "{\n" .
            "  \"error\": {\n" .
            "    \"code\": \"VALIDATION_ERROR\",\n" .
            "    \"message\": \"The request data is invalid\",\n" .
            "    \"details\": {\n" .
            "      \"email\": [\"This value is not a valid email address\"]\n" .
            "    }\n" .
            "  }\n" .
            "}\n" .
            "```"
        );
        $content4->setComments('Initial API documentation with auth and basic endpoints');
        $content4->setUpdatedOn(new \DateTime('2024-01-08 11:20:00'));
        $content4->setVersion(1);
        
        $manager->persist($content4);
        $this->addReference('wiki-content-api-v1', $content4);

        // Content for Documentation Home page (version 1)
        $content5 = new WikiContent();
        $content5->setPage($this->getReference('wiki-page-docs-home', \App\Entity\WikiPage::class));
        $content5->setAuthor($this->getReference('user-dbrown', \App\Entity\User::class));
        $content5->setText(
            "# Documentation Home\n\n" .
            "Welcome to the central documentation hub for all our projects.\n\n" .
            "## Available Documentation\n\n" .
            "### Project Wikis\n\n" .
            "- [[E-Commerce Platform|E-Commerce Wiki]] - E-commerce development documentation\n" .
            "- [[CRM System|CRM Wiki]] - Customer relationship management docs\n" .
            "- [[Mobile Application|Mobile Wiki]] - Mobile app development guides\n\n" .
            "### Development Resources\n\n" .
            "- [[User Guide]] - End-user documentation and tutorials\n" .
            "- [[Technical Specifications]] - System architecture and design\n" .
            "- [[API Documentation]] - REST API references and examples\n" .
            "- [[Setup Guides]] - Installation and configuration instructions\n\n" .
            "### Standards and Guidelines\n\n" .
            "- **Coding Standards**: Follow PSR-12 for PHP code\n" .
            "- **Git Workflow**: Use feature branches and pull requests\n" .
            "- **Testing**: Minimum 80% code coverage required\n" .
            "- **Documentation**: Update docs with every feature change\n\n" .
            "### Quick References\n\n" .
            "#### Common Commands\n\n" .
            "```bash\n" .
            "# Database operations\n" .
            "php bin/console doctrine:migrations:migrate\n" .
            "php bin/console doctrine:fixtures:load\n\n" .
            "# Testing\n" .
            "php bin/phpunit\n" .
            "php bin/console app:test:integration\n\n" .
            "# Code quality\n" .
            "php bin/console app:lint\n" .
            "composer check-cs\n" .
            "```\n\n" .
            "#### Useful Links\n\n" .
            "- [GitHub Repository](https://github.com/company/main-repo)\n" .
            "- [Staging Environment](https://staging.company.com)\n" .
            "- [CI/CD Pipeline](https://ci.company.com)\n" .
            "- [Issue Tracker](https://issues.company.com)\n\n" .
            "## Contributing to Documentation\n\n" .
            "To contribute to the documentation:\n\n" .
            "1. Create or edit pages in the appropriate project wiki\n" .
            "2. Follow the documentation standards outlined in the style guide\n" .
            "3. Include code examples and screenshots where applicable\n" .
            "4. Update this index page when adding new major sections\n\n" .
            "## Support\n\n" .
            "For questions about documentation or to request new documentation:\n\n" .
            "- Create an issue with the 'documentation' label\n" .
            "- Contact the development team lead\n" .
            "- Join the #docs channel in our team chat\n\n" .
            "---\n\n" .
            "*Last updated: January 2024*"
        );
        $content5->setComments('Initial documentation home page content');
        $content5->setUpdatedOn(new \DateTime('2024-02-10 17:00:00'));
        $content5->setVersion(1);
        
        $manager->persist($content5);
        $this->addReference('wiki-content-docs-home-v1', $content5);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            WikiFixtures::class,
            UserFixtures::class,
        ];
    }
}