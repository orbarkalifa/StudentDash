# StudentDash: A Centralized Moodle Dashboard for Students

StudentDash is a Moodle plugin that enhances the student learning experience by aggregating all essential academic information into a single, modern, and intuitive dashboard. Built with a React frontend and a PHP backend, it provides students with a comprehensive overview of their courses, assignments, grades, and personal tasks.

![StudentDash Screenshot](frontend/dashboard/public/studentDash.png) 


## 🚀 The Problem
In a standard Moodle environment, students often have to navigate through multiple pages and menus to find critical information like assignment deadlines, grades for different courses, and learning materials. This fragmented experience can be inefficient and frustrating.

## ✨ The Solution
StudentDash solves this by providing a single, centralized hub where students can see everything at a glance:
*   **Unified Dashboard:** View data from all your courses in one place.
*   **Course & Grade Tracking:** Easily access your enrolled courses and current grades.
*   **Assignment Overview:** Keep track of upcoming and past-due assignments.
*   **Personal Task Management:** Add and manage your own to-do items right within the dashboard.
*   **Modern Interface:** A clean, responsive user interface built with React.

## 🛠️ Tech Stack
*   **Backend:** PHP, Moodle API
*   **Frontend:** React, JavaScript, CSS
*   **Database:** Moodle's default database (MariaDB/PostgreSQL/MySQL)

## ⚙️ Getting Started

### Prerequisites
*   A working Moodle instance.
*   Administrator access to your Moodle site.

### Installation
1.  **Download:** Download the latest release as a ZIP file from the [releases page](https://github.com/your-username/StudentDash/releases) *(Suggestion: Create a GitHub release for your project)*.
2.  **Install Plugin:**
    *   Log in to your Moodle site as an admin.
    *   Navigate to `Site administration > Plugins > Install plugins`.
    *   Upload the ZIP file. Moodle will automatically detect the plugin type.
    *   Follow the on-screen instructions to complete the installation.
3.  **Manual Installation:**
    *   Alternatively, you can unzip the plugin and place the `studentdash` directory in `{your/moodle/dirroot}/local/`.
    *   Log in as an admin and go to `Site administration > Notifications` to run the database upgrade.

### Frontend Setup (for development)
The React frontend is located in the `frontend/dashboard` directory. To run it in development mode:
```bash
cd frontend/dashboard
npm install
npm start
```