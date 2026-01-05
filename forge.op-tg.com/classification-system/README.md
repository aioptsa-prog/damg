# Classification System

## Overview
The Classification System is a web application designed to manage and classify various entities through a user-friendly interface. It includes features such as seeding, typeahead search, and an icon picker, making it suitable for shared hosting environments.

## Features
- **Seeding**: Easily populate the database with initial data using the SeederService.
- **Typeahead**: Provides real-time suggestions as users type, enhancing the search experience.
- **Icon Picker**: Allows users to select and upload icons for classifications.

## Project Structure
The project is organized into several directories, each serving a specific purpose:

- **public/**: Contains the entry point and assets for the application.
  - `index.php`: Main entry point for routing requests.
  - `.htaccess`: Apache configuration for URL rewriting and security.
  - `css/`: Stylesheets for the application.
  - `js/`: JavaScript files for client-side functionality.

- **src/**: Contains the core application logic.
  - **Controllers/**: Manages the application flow and handles requests.
  - **Models/**: Represents the data structures and interacts with the database.
  - **Services/**: Contains business logic and operations related to seeding, typeahead, and icons.
  - **Database/**: Manages database connections, migrations, and seed data.

- **api/**: Contains endpoints for API requests related to typeahead, icons, and classifications.

- **admin/**: Provides an interface for administrative tasks, including managing categories and seeding data.

- **config/**: Contains configuration files for database and application settings.

- **lib/**: Includes helper functions used throughout the application.

- **storage/**: Contains the SQLite database file.

- **.env.example**: Template for environment variables.

- **composer.json**: Composer configuration file for dependencies.

## Installation
1. Clone the repository to your local machine.
2. Navigate to the project directory.
3. Install dependencies using Composer:
   ```
   composer install
   ```
4. Configure your database settings in `config/database.php`.
5. Set up environment variables by copying `.env.example` to `.env` and modifying as necessary.
6. Run the initial database migrations and seed the database:
   ```
   php src/Database/Migrations/create_tables.sql
   php src/Database/Seeds/initial_data.sql
   ```

## Usage
- Access the application through the web server configured to serve the `public` directory.
- Use the admin interface to manage classifications, categories, and icons.
- Utilize the API endpoints for integration with other applications or services.

## Contributing
Contributions are welcome! Please submit a pull request or open an issue for any enhancements or bug fixes.

## License
This project is licensed under the MIT License. See the LICENSE file for details.