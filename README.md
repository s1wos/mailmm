# 📬 mailmm-service — Temporary Email Platform

A **full-stack service** for creating disposable email addresses and receiving messages. The frontend uses **Vue 3, Pinia, and TypeScript**. The backend runs on **Laravel and PostgreSQL**, with **Postfix** for incoming mail and **Laravel Reverb** for live inbox updates.

The service is live at **[mailmm.my](https://mailmm.my/)**.

---

## ✨ Features

- 📧 Generate a disposable email address that lasts 24 hours
- 📩 Receive and view emails in real time and download attachments
- 🔄 Switch to a new address when you no longer need the old inbox
- 🎨 Responsive inbox built with Vue 3
- 🔑 Create permanent mailboxes and manage domains from the admin panel
- 📨 Forward an archived message or automatically redirect new mail from a permanent mailbox
- 🐳 Dockerized deployment on Ubuntu

---

## 🗂 Tech Stack

**Frontend**

- Vue 3 (Composition API)
- Pinia (state management)
- TypeScript and Vite

**Backend**

- Laravel 13 on PHP 8.4
- PostgreSQL
- Postfix (incoming SMTP)
- WebSockets (Laravel Reverb)
- Nginx and Docker Compose on Ubuntu

---

## 📁 Project Structure

- `app/` — Laravel core application code
- `public/` — public entry point and Vite build output
- `resources/` — Vue application, styles, and server-rendered views
- `routes/` — Laravel routes
- `deploy/` — environment setup, PostgreSQL initialization, and Postfix configuration

---

## 🚀 Getting Started

With Docker and Docker Compose installed, clone the repo and enter the project directory.

```bash
git clone https://github.com/s1wos/mailmm.git
cd mailmm
```

On Linux or macOS, run `sh d init`. On Windows, run `.\d.bat init` in PowerShell. The script creates `.env`, generates credentials, builds the images, and starts the services.

Open the [local inbox](http://localhost:8080/). To test mail delivery, send a message to the address shown in the inbox through the local SMTP server at `127.0.0.1:2525`.

---

## 📋 Roadmap

- [x] Disposable email generation
- [x] Real-time inbox updates with WebSockets
- [x] PostgreSQL integration
- [x] Dockerized deployment
- [x] Custom domain support
- [x] Email forwarding

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
