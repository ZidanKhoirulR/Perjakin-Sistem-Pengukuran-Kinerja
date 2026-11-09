# Web-Based Institutional Performance Measurement System (PERJAKIN)

> **Performance Agreement and Institutional Achievement Measurement Platform Powered by Fat-Free Framework (F3)**

![PHP](https://img.shields.io/badge/PHP-%3E%3D%208.0-777BB4?style=for-the-badge&logo=php&logoColor=white)
![Framework](https://img.shields.io/badge/Framework-Fat--Free%203.x-F15A24?style=for-the-badge)
![Database](https://img.shields.io/badge/Database-MySQL%20%7C%20MariaDB-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![Frontend](https://img.shields.io/badge/Frontend-TailwindCSS%20%7C%20Alpine.js-38B2AC?style=for-the-badge&logo=tailwindcss&logoColor=white)
![Export](https://img.shields.io/badge/Export-PhpSpreadsheet%20%7C%20mPDF-0096D6?style=for-the-badge)

---

## Overview and Case Study

**PERJAKIN (Perjanjian Kinerja)** is a dedicated web-based enterprise performance management platform designed to streamline, monitor, and evaluate institutional performance agreements within public agencies. Operating as a centralized performance governance portal, the system facilitates structured management of strategic goals (*Tujuan*), organizational targets (*Sasaran*), performance indicators (*Indikator Kinerja*), and periodic achievement milestones across functional departments.

In maintaining an accountable and transparent institutional governance workflow, delivering reliable performance tracking and automated reporting mechanisms is essential. Historically, organizational performance monitoring encountered operational challenges:
- **Fragmented Data Tracking:** Manual recording via disconnected spreadsheets often introduced human error, version discrepancies, and delayed data aggregation during audit periods.
- **Lack of Centralized Progress Monitoring:** Department heads and stakeholders lacked real-time visibility into target realizations, impeding timely interventions and strategic decision-making.
- **Disparate User Roles and PIC Assignment:** Managing operational indicators required clear delegation between System Administrators, Person-In-Charge (PIC) officers, and institutional auditors.

To address these challenges, **PERJAKIN** implements an end-to-end performance agreement framework built on top of the high-performance **Fat-Free Framework (F3)** MVC engine. The platform provides interactive dashboard analytics, multi-level target evaluation, dynamic indicator assignment, and automated report generation supporting industry-standard formats (Excel and PDF).

---

## Technology Stack

- **Backend Framework:** Fat-Free Framework 3.x (PHP 8.0+)
- **Architecture Pattern:** Model-View-Controller (MVC) with custom routing dispatcher and controller-bound actions
- **Database Engine:** MySQL / MariaDB (InnoDB relational storage engine)
- **Frontend Engine:** TailwindCSS, Alpine.js, and SweetAlert2
- **Template System:** Fat-Free Template Engine with modular component rendering
- **Reporting and Export Engines:** PhpSpreadsheet (Excel data exports) and mPDF / FPDI (standardized PDF reporting)
- **Authentication and Security:** Two-Factor WhatsApp OTP dispatch, CSRF token validation, role-based route protection, parameter sanitization, and secure password hashing

---

## Key Features

- **Dashboard Summary and Analytics:** Real-time summary metric cards tracking total, achieved, and pending indicators with visual progress tracking.
- **Performance Period Management:** Configuration and versioning of fiscal performance cycles and strategic topics (*Tahun Perjakin*).
- **Strategic Goals and Targets:** Multi-tier definition of institutional goals (*Tujuan*) and key organizational targets (*Sasaran*).
- **Performance Indicator Delegation:** Creation of quantitative indicators with designated PIC assignment and baseline metrics.
- **Periodic Achievement Tracking:** Interactive submission interface for PICs to record quarterly and annual realizations with formula-driven achievement calculations.
- **Performance Agreement Reporting:** Standardized report generation for formal Performance Agreement documents (*Dokumen Perkin*) with export capabilities.
- **Comprehensive Recap and Audit:** Consolidated institutional achievement summary across all active organizational units.
