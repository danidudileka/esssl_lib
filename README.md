Library Management System - ESSSL Institute

A comprehensive digital library management system designed to replace manual processes at ESSSL Institute. The system provides automated book cataloging, member management, borrowing/returning processes, and payment handling through three distinct user roles: Reader, Librarian, and Super Admin.

Demo Credentials

- Reader Login:

  - Username: 'danidu@email.com'
  - Password: `crv96rDe`

- Librarian Login:

  - Username: `librarian@esssl.com`
  - Password: `lib_test`

- Super Admin Login:
  - Username: `admin@esssl.com`
  - Password: `admin`

🚀 Features

Reader Panel

- Secure login and profile management
- Advanced book search with filters (title, author, category, availability)
- Book borrowing and reservation system
- Favorites and borrowing history
- Membership details and payment tracking
- Interactive chatbot for assistance

Librarian Panel

- Complete book catalog management (add/edit/delete books)
- Member registration and management
- Loan approval/rejection system
- Reservation management
- Payment processing and membership renewals
- Comprehensive reporting and analytics

Super Admin Panel

- All librarian capabilities plus administrative controls
- Authorization for sensitive actions (member removal, librarian management)
- System-wide oversight and audit capabilities
- Policy configuration and management

🛠️ Technology Stack

Frontend

- HTML5 - Structure and layout
- CSS3 - Styling and responsive design
- JavaScript - Interactive functionality and dynamic content

Backend

- PHP - Server-side logic and API endpoints
- MySQL/MariaDB - Database management system

Development Environment

- Local Development: XAMPP Server
- Production Server: phpMyAdmin

📋 System Requirements

Local Development Setup

- XAMPP Server (Windows, Apache, MySQL, PHP)
- PHP: Version 7.4 or higher
- MySQL/MariaDB: Version 5.7 or higher
- Apache: Version 2.4 or higher
- Web Browser: Chrome, Firefox, Safari, or Edge (latest versions)

🔧 Installation & Setup

Local Development

1. Install XAMPP Server

   ```
   Download and install XAMPP SERVER
   ```

2. Clone/Download Project

   ```
   Place project files in xampp directory (usually C:\xampp\htdocs)
   ```

3. Database Setup

   ```
   - Access phpMyAdmin (http://localhost/phpmyadmin)
   - Create a new database for the library system
   - Import the provided SQL file to set up tables and initial data
   ```

4. Configuration

   ```
   - Update database connection settings in config files
   - Ensure PHP extensions are enabled (mysqli, pdo_mysql)
   ```

5. Launch Application
   ```
   Start xampp services and navigate to http://localhost/[project-folder]
   ```

📁 Project Structure

```
library-management-system/
├── admin/                  # Librarian and Super Admin panels
├── reader/                 # Reader/Student interface
├── assets/                 # CSS, JavaScript, and image files
├── includes/              # PHP configuration and utility files
├── database/              # SQL files and database scripts
├── documentation/         # Project documentation and reports
└── README.md             # This file
```

🔐 Security Features

- Role-based Access Control: Separate permissions for Reader, Librarian, and Super Admin
- Password Encryption: Secure hashing for all user passwords
- Session Management: Secure session handling and timeout
- Input Validation: Protection against SQL injection and XSS attacks
- HTTPS Encryption: Secure data transmission in production

🐛 Known Issues & Limitations

- Browser Compatibility: Optimized for modern browsers; may have limited functionality on older versions
- Mobile Responsiveness: While functional on mobile devices, desktop experience is recommended for administrative tasks
- Concurrent Users: System performance may vary under high concurrent load
- File Upload: Limited file size for book cover images and documents

🧪 Testing

The system has undergone comprehensive testing including:

- Unit Testing: Individual component functionality
- Integration Testing: System module interactions
- User Acceptance Testing: Real-world usage scenarios
- Security Testing: Vulnerability assessments and access control verification

👥 User Roles & Permissions

| Feature               | Reader | Librarian | Super Admin |
| --------------------- | ------ | --------- | ----------- |
| Book Search & View    | ✅     | ✅        | ✅          |
| Borrow/Reserve Books  | ✅     | ❌        | ❌          |
| Manage Books          | ❌     | ✅        | ✅          |
| Manage Members        | ❌     | ✅        | ✅          |
| Process Payments      | ❌     | ✅        | ✅          |
| Generate Reports      | ❌     | ✅        | ✅          |
| System Administration | ❌     | ❌        | ✅          |
| Authorize Deletions   | ❌     | ❌        | ✅          |

- Project Lead: Danidu Dileka
- Developer/Documentation: Nadun Warshan
- UI/UX Designer: Dulmin Theekshana

📄 License

This project is developed for educational purposes as part of the CIS5005 - Developing Quality Software and Systems II course at ESSSL Institute. All rights reserved to the development team and institution.

🔄 Version History

- v1.0.0 - Initial release with core functionality
- Current Version: Production-ready system with full feature set
