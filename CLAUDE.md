# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel 12 application using PHP 8.2+ with Laravel Breeze for authentication. The project uses:
- **Frontend**: Blade templates with Tailwind CSS and Alpine.js
- **Build System**: Vite for asset compilation
- **Database**: SQLite (default) with Eloquent ORM
- **Authentication**: Laravel Breeze with email verification
- **Testing**: PHPUnit with Feature and Unit test suites

## Development Commands

### Starting Development
```bash
# Install PHP dependencies
composer install

# Install Node.js dependencies
npm install

# Copy environment file (if needed)
cp .env.example .env

# Generate application key
php artisan key:generate

# Create database file and run migrations
touch database/database.sqlite
php artisan migrate

# Start development server (includes server, queue, logs, and Vite)
composer run dev
```

### Individual Services
```bash
# PHP development server
php artisan serve

# Vite development server for assets
npm run dev

# Queue worker
php artisan queue:listen --tries=1

# Log viewer
php artisan pail --timeout=0
```

### Building for Production
```bash
# Build frontend assets
npm run build

# Optimize Laravel for production
php artisan optimize
```

### Testing
```bash
# Run all tests
composer run test
# OR
php artisan test

# Run specific test suite
php artisan test --testsuite=Feature
php artisan test --testsuite=Unit

# Run specific test file
php artisan test tests/Feature/ExampleTest.php
```

### Code Quality
```bash
# Laravel Pint (code formatter)
./vendor/bin/pint

# Clear caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

## Architecture

### Directory Structure
- `app/Http/Controllers/` - HTTP controllers including Auth controllers from Breeze
- `app/Models/` - Eloquent models (User model included)
- `app/View/Components/` - Blade components (AppLayout, GuestLayout)
- `resources/views/` - Blade templates with auth views and dashboard
- `resources/js/` - JavaScript files (Alpine.js setup)
- `resources/css/` - CSS files (Tailwind CSS)
- `routes/` - Route definitions (web.php, auth.php)
- `database/migrations/` - Database migrations
- `tests/` - PHPUnit tests (Feature and Unit)

### Key Components
- **Authentication**: Laravel Breeze provides login, registration, password reset, and email verification
- **User Management**: Profile editing and account deletion functionality
- **Database**: Uses SQLite by default with standard Laravel migrations
- **Frontend**: Server-side rendered Blade templates with Tailwind CSS styling
- **Asset Pipeline**: Vite handles CSS and JavaScript compilation with hot reloading

### Configuration
- Database configured for SQLite in `.env`
- Vite configuration in `vite.config.js` handles asset compilation
- Tailwind CSS configured in `tailwind.config.js` with forms plugin
- PHPUnit configured in `phpunit.xml` with SQLite in-memory testing database

## Database

The application uses SQLite by default. The database file is located at `database/database.sqlite`.

### Common Database Operations
```bash
# Create new migration
php artisan make:migration create_example_table

# Run migrations
php artisan migrate

# Rollback migrations
php artisan migrate:rollback

# Seed database
php artisan db:seed

# Access database directly
php artisan tinker
```

## Frontend Development

The frontend uses Blade templates with Tailwind CSS and Alpine.js:
- Tailwind CSS for styling with forms plugin
- Alpine.js for interactive components
- Vite for asset building and hot reloading
- Blade components for reusable UI elements

### Asset Files
- `resources/css/app.css` - Main CSS file
- `resources/js/app.js` - Main JavaScript file with Alpine.js
- `resources/js/bootstrap.js` - Bootstrap configuration with Axios

## Authentication Flow

Laravel Breeze provides:
- User registration with email verification
- Login/logout functionality (supports username OR email)
- Password reset flow
- Profile management (edit profile, change password, delete account)
- Email verification middleware

### Username Authentication
The application supports flexible login using either username or email:
- Login form accepts "Username or Email"
- Automatically detects whether input is email (using validation) or username
- Modified `LoginRequest` handles both authentication methods
- User model includes `username` field (unique, nullable)

### Test Admin Account
For development/testing, use the AdminUserSeeder:
```bash
php artisan db:seed --class=AdminUserSeeder
```
Credentials:
- **Username:** `admin`
- **Email:** `admin@osmanager.local`
- **Password:** `admin123`

All authentication routes are defined in `routes/auth.php` and controllers are in `app/Http/Controllers/Auth/`.

## Database Connections

### Primary Database
- SQLite by default (`database/database.sqlite`)
- Configuration in `config/database.php`

### POS Database (Placeholder)
A commented-out `pos` connection is configured for future uniCenta integration:
- Located in `config/database.php` 
- Uncomment and set environment variables when ready
- Designed for read-only access to POS data
- Usage: `DB::connection('pos')->table('products')->get()`

## Troubleshooting

### Storage Permission Issues
If you encounter "Permission denied" errors for `storage/framework/views/`:

```bash
# Clear compiled views and caches
php artisan view:clear
php artisan config:clear
php artisan route:clear

# Fix storage permissions
chmod -R 775 storage/
chmod -R 775 bootstrap/cache/
```

This typically happens when Laravel runs under different users (web server vs CLI).