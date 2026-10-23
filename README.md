# SDSS project layout

- `frontend/pages/` contains the public HTML pages.
- `frontend/assets/` contains the shared CSS and JavaScript.
- `backend/` contains the PHP pages and server-side request handling.
- `index.html` opens the frontend homepage.

The PHP pages continue to render their HTML so the existing server-rendered flows are preserved. On Windows with XAMPP installed at `C:\xampp`, open a terminal in the SDSS project folder and run `C:\xampp\php\php.exe -S localhost:8000`; then open `http://localhost:8000/`. Keep the terminal open while using the system. Do not use VS Code Live Server or open the HTML files directly when testing PHP login, since those methods do not execute PHP.

## Demo login

Use the same demo credentials for the login form:

- Username: `mau@123`
- Password: `12345678`

After the credentials are verified, choose which of the six role dashboards to open. The role choice is a separate step and is not part of the login form. These shared demo credentials are for local testing only; do not use them in a deployed system.

## Placement workflow notes

- Create student accounts before importing their records. The registrar CSV must use the exact headers `student_code,full_name,username,gpa`; imported students are assigned to the current academic year.
- Students can submit three distinct department preferences while the current academic year is open. The registrar runs and reviews a draft before approving and publishing results.
- Published placement reports can be exported as CSV from the role-specific reports pages.

## Role-based sidebar navigation

- Sidebar items, labels, visibility, and ordering are stored in the `role_navigation` database table and seeded with defaults for all six roles.
- Admins can edit each role's menu from **Role & Permissions**. Change the position numbers to reorder links, update labels, hide optional entries, and save that role's sidebar.
- The dashboard and logout links remain visible. Sidebar changes affect navigation and page access for that role.
