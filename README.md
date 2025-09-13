# OpenParcelTracker

OpenParcelTracker is a lightweight, self-hosted PHP parcel tracking system that provides comprehensive package management and real-time location tracking capabilities. Built with simplicity and efficiency in mind, it offers both public tracking interfaces and powerful administrative tools.

## 🚀 Features

### Core Functionality
- **Package Management**: Create, edit, and manage packages with unique tracking numbers
- **Real-time Location Tracking**: GPS coordinate tracking with location history
- **Interactive Maps**: OpenStreetMap integration with draggable markers for precise positioning
- **Photo Upload Support**: Attach photos and documentation to packages
- **Address Geocoding**: Automatic address resolution and manual coordinate editing
- **Status Management**: Track package status throughout delivery lifecycle

### User Interfaces
- **Public Tracking Portal**: Search packages by tracking number with map visualization
- **Admin Panel**: Full management interface with drag-and-drop map editing
- **Responsive Design**: Mobile-friendly interface that works on all devices
- **Multi-language Support**: Extensible localization system

### Technical Features
- **Database Flexibility**: Support for both MySQL and SQLite backends
- **Auto-installer**: Web-based installation wizard for easy setup
- **Version Management**: Built-in update system with GitHub integration
- **Security**: User authentication with secure password hashing
- **RESTful API**: AJAX endpoints for dynamic functionality

## 📋 Requirements

- **PHP**: 7.4 or higher with PDO extension
- **Database**: MySQL 5.7+ or SQLite 3.x
- **Web Server**: Apache, Nginx, or any PHP-compatible server
- **Extensions**: PDO, JSON, file upload support

## 🛠️ Installation

### Option 1: Auto-Installer (Recommended)

1. Upload all files to your web server
2. Navigate to `http://yourdomain.com/installer.php`
3. Follow the setup wizard to configure your database
4. Default admin credentials: `admin` / `admin123` (change immediately!)

### Option 2: Manual Installation

1. **Upload Files**: Upload all project files to your web server

2. **Database Configuration**: Create a `.env` file in the root directory:
   ```env
   DB_DRIVER=mysql
   DB_HOST=localhost
   DB_NAME=tracker
   DB_USER=your_username
   DB_PASS=your_password
   DB_CHARSET=utf8mb4
   ```

   For SQLite (no additional database server required):
   ```env
   DB_DRIVER=sqlite
   DB_NAME=tracker
   ```

3. **Initialize Database**: The system will automatically create required tables on first access

4. **Set Permissions**: Ensure the web server can write to the data directory (for SQLite) and photos directory

## 🎯 Usage

### Public Interface

Access the main tracking interface at `http://yourdomain.com/`

- **Package Search**: Enter tracking number to view package details
- **Map Visualization**: See current location and movement history
- **Package Information**: View photos, descriptions, and delivery details

### Admin Panel

Access the admin interface at `http://yourdomain.com/admin/`

**Default Credentials**:
- Username: `admin`
- Password: `admin123`

**Admin Features**:
- **Dashboard**: Overview of all packages on interactive map
- **Package Management**: Add, edit, delete packages
- **Location Updates**: Drag markers to update positions or use address lookup
- **Photo Management**: Upload and manage package photos
- **User Management**: Manage admin accounts
- **System Updates**: Check for and install updates

### API Endpoints

The system provides AJAX endpoints for dynamic functionality:

- `POST /admin/index.php?action=add_package`: Create new package
- `POST /admin/index.php?action=update_position`: Update package location
- `POST /admin/index.php?action=delete_package`: Remove package
- `GET /admin/index.php?action=get_packages`: Retrieve package list

## 📊 Database Schema

### Tables

**packages**
- `id`: Primary key
- `tracking_number`: Unique tracking identifier
- `title`: Package title/description
- `last_lat`, `last_lng`: Current GPS coordinates
- `last_address`: Current address
- `status`: Delivery status
- `image_path`: Photo file path
- `arriving`: Expected arrival information
- `destination`: Delivery destination
- `delivery_option`: Delivery method
- `description`: Additional notes
- `created_at`, `updated_at`: Timestamps

**locations**
- `id`: Primary key
- `package_id`: Foreign key to packages
- `lat`, `lng`: GPS coordinates
- `address`: Location address
- `note`: Location notes
- `created_at`: Timestamp

**users**
- `id`: Primary key
- `username`: Login username
- `password_hash`: Hashed password
- `is_admin`: Admin privileges flag
- `created_at`: Account creation timestamp

## ⚙️ Configuration

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `DB_DRIVER` | Database type (mysql/sqlite) | mysql |
| `DB_HOST` | Database host | localhost |
| `DB_NAME` | Database name | tracker |
| `DB_USER` | Database username | - |
| `DB_PASS` | Database password | - |
| `DB_CHARSET` | Database charset | utf8mb4 |

### File Structure

```
OpenParcelTracker/
├── admin/              # Admin panel
│   └── index.php      # Admin interface
├── data/              # SQLite database directory
├── photos/            # Uploaded photos (auto-created)
├── .env               # Environment configuration
├── db.php             # Database connection and schema
├── index.php          # Public tracking interface
├── installer.php      # Auto-installer
├── footer.php         # Shared footer component
└── README.md          # This file
```

## 🔒 Security Considerations

- Change default admin password immediately after installation
- Use strong database passwords
- Ensure proper file permissions (photos directory writable)
- Keep the system updated using the built-in update mechanism
- Consider HTTPS for production deployments

## 🔄 Updates

The system includes an automatic update checker:

1. **Check for Updates**: Admin panel displays available updates
2. **Auto-Update**: Click update button to fetch latest version from GitHub
3. **Manual Update**: Download latest release and replace files (preserve .env and data/)

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

### Development Setup

1. Clone the repository
2. Set up local web server (Apache/Nginx + PHP)
3. Configure database connection
4. Use SQLite for development (no additional setup required)

## 📝 License

This project is open source. Please refer to the license file for details.

## 👨‍💻 Credits

- **Developer**: [Buyreadysite.com](https://buyreadysite.com)
- **Maps**: OpenStreetMap and Leaflet.js
- **Current Version**: 1.7.9

## 🐛 Troubleshooting

### Common Issues

**Database Connection Errors**:
- Verify database credentials in `.env` file
- Ensure database server is running
- Check database user permissions

**File Upload Issues**:
- Verify photos directory exists and is writable
- Check PHP upload limits (`upload_max_filesize`, `post_max_size`)
- Ensure proper file permissions

**Map Not Loading**:
- Check internet connection for OpenStreetMap tiles
- Verify no JavaScript errors in browser console
- Ensure proper latitude/longitude values

**Update Issues**:
- Check internet connectivity
- Verify GitHub access is not blocked
- Ensure write permissions for file updates

For additional support, please open an issue on the GitHub repository.
