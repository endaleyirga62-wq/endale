# SDSS Project

## Project Layout

- `frontend/pages/` contains the public HTML pages.
- `frontend/assets/` contains the shared CSS and JavaScript.
- `backend/` contains the PHP pages and server-side request handling.
- `index.html` opens the frontend homepage.

## Running the Project with XAMPP

On Windows, with XAMPP installed at `C:\xampp\`, open PowerShell in the SDSS project folder and run:

```powershell
C:\xampp\php\php.exe -S localhost:8000
```

Then open http://localhost:8000/ in your browser. Keep the terminal open while using the system.

Do not use VS Code Live Server or open HTML files directly when testing PHP login, because those methods do not execute PHP.

## Demo Login

Use these credentials for local testing:

- **Username:** `mau@123`
- **Password:** `12345678`

After the credentials are verified, choose one of the six role dashboards. Role selection is a separate step from login.

These demo credentials are for local testing only. Do not use them in a deployed system.

## Placement Workflow

- Create student accounts before importing their records.
- The registrar CSV must use the exact headers `student_code,full_name,username,gpa`.
- Imported students are assigned to the current academic year.
- Students can submit three distinct department preferences while the current academic year is open.
- The registrar runs and reviews a draft before approving and publishing results.
- Published placement reports can be exported as CSV from the role-specific reports pages.

## Role-Based Sidebar Navigation

- Sidebar items, labels, visibility, and ordering are stored in the `role_navigation` database table and seeded with defaults for all six roles.
- Admins can edit each role's menu from **Role & Permissions**.
- Change position numbers to reorder links, update labels, hide optional entries, and save that role's sidebar.
- Dashboard and logout links remain visible.
- Sidebar changes affect navigation and page access for that role.

---

**Project name:** SDSS  
**Repository:** endale
