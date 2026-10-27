# Admin & Client Manager System (PHP + MySQL + Bootstrap 5)

A complete, production-ready Admin and Client Management application with Project tracking, Milestone Payments, automated Email and WhatsApp reminders, and a view-only Client Portal.

---

## Complete Process Flow

### 1. Admin Workflow:
1. **Admin Login**: Admin logs in via `/admin/login.php`.
2. **Add Client**: Admin creates client accounts (Name, Email, Phone, Password).
3. **Add Project**: Admin selects client, enters project title, total budget, deadline, and status (`/admin/projects/add_project.php`).
4. **Add Payment**: Admin creates payment milestones for the project with amount and due date (`/admin/payments/add_payment.php`).
5. **Send Reminders**:
   - One-click **"Remind"** button.
   - Automatically dispatches an HTML reminder email with invoice details via PHPMailer.
   - Generates a pre-filled WhatsApp click-to-chat URL.
   - Logs the reminder timestamp in `reminder_logs` and `payments`.
6. **Mark as Paid**: One-click **"Mark Paid"** settles the invoice with current date and updates records.

### 2. Client Portal Workflow (View-Only):
1. **Client Login**: Client logs in via `/client/auth/login.php`.
2. **Dashboard**: View ongoing active projects, total settled amount, and pending invoice alerts.
3. **My Projects** (`/client/projects.php`): View-only list of all assigned projects, deadlines, and progress.
4. **My Payments** (`/client/payments.php`): View-only list of all invoices (Paid & Pending). Clients cannot add, edit, or delete records.
5. **My Profile** (`/client/profile.php`): Update contact phone, address, and portal password.

---

## Database Configuration

- **Database Name**: `client_flow`
- **Tables**:
  1. `admin`: Administrator accounts and credentials.
  2. `clients`: Client master directory and login credentials.
  3. `projects`: Client projects with budget, deadline, and status.
  4. `payments`: Payment milestones and invoices with due dates and statuses (`pending`, `paid`).
  5. `reminder_logs`: History of Email and WhatsApp reminders sent.

---

## Default Logins

| Role | Login URL | Email | Password |
| :--- | :--- | :--- | :--- |
| **Admin** | `http://localhost/client-flow/admin/login.php` | `admin@agency.com` | `Admin@123` |
| **Client** | `http://localhost/client-flow/client/auth/login.php` | `client@sample.com` | `Client@123` |
# Admin-Client-Team
